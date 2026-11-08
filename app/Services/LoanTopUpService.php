<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ChartAccount;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanSchedule;
use App\Models\LoanTopUp;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LoanTopUpService
{
    public function __construct(
        private LoanCalculatorService $calculator,
        private TransactionPostingService $transactions,
        private BusinessCalendarService $calendar,
        private AuditLogger $auditLogger,
    ) {}

    public function post(Loan $loan, User $user, float $amount, int $extensionDays, string $reason, string $submissionToken): LoanTopUp
    {
        abort_unless($user->canAdministerLoanRepairs() && $user->canPerform('disburse-loans'), 403);
        CurrencyDenomination::assertValid($amount, 'Top-up amount');
        if ($extensionDays < 1 || $extensionDays > 3650) {
            throw new InvalidArgumentException('Extension must be between 1 and 3650 days.');
        }

        return DB::transaction(function () use ($loan, $user, $amount, $extensionDays, $reason, $submissionToken): LoanTopUp {
            $loan = Loan::query()->with('product')->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $existing = $loan->topUps()->where('submission_token', $submissionToken)->first();
            if ($existing) {
                if ($existing->status !== 'posted' || (float) $existing->principal_added !== $amount || (int) $existing->extension_days !== $extensionDays) {
                    throw new InvalidArgumentException('This top-up request has already been used. Reload the loan to start a new top-up.');
                }

                return $existing;
            }
            if (! in_array($loan->status, ['active', 'in_arrears'], true)) {
                throw new InvalidArgumentException('Only disbursed, active loans can be topped up.');
            }
            if ((float) $loan->approved_amount + $amount > (float) $loan->product->max_amount) {
                throw new InvalidArgumentException('The increased principal exceeds this loan product’s maximum amount.');
            }

            $schedules = $loan->schedules()->where('outstanding_amount', '>', 0)->orderBy('installment_number')->lockForUpdate()->get();
            if ($schedules->isEmpty()) {
                throw new InvalidArgumentException('This loan has no unpaid installments to extend.');
            }
            $savings = Account::query()->with('accountType')->whereKey($loan->savings_account_id)->lockForUpdate()->first();
            $loanAccount = Account::query()->whereKey($loan->loan_account_id)->lockForUpdate()->first();
            if (! $savings || $savings->status !== 'active' || $savings->accountType?->category !== 'savings'
                || (int) $savings->client_id !== (int) $loan->client_id || ! $loanAccount || $loanAccount->status !== 'active') {
                throw new InvalidArgumentException('This loan needs an active client savings account and active loan account.');
            }
            if (ChartAccount::query()->where('organization_id', $loan->organization_id)->where('is_active', true)->whereIn('code', ['1200', '2100'])->count() !== 2) {
                throw new InvalidArgumentException('Loan receivable and client savings ledger accounts must be active before the top-up.');
            }

            $today = CarbonImmutable::today();
            $controls = app(FinancialControlService::class);
            $businessDate = $controls->ensureFinancialDayOpen($user, (int) $loan->branch_id, $today);
            $controls->enforceTransactionLimit($user, $amount, $businessDate);
            $previousMaturity = CarbonImmutable::parse($loan->schedules()->max('due_date'));
            $newMaturity = $previousMaturity->addDays($extensionDays);
            if ($newMaturity->lte($today)) {
                throw new InvalidArgumentException('The extended maturity must be after the top-up date.');
            }
            $termDays = max(1, $today->diffInDays($newMaturity));
            $periodicRate = (float) $loan->interest_rate * $termDays / 365 / $schedules->count();
            $rows = match ($loan->product->interest_method) {
                'flat' => $this->calculator->flatRate($amount, $periodicRate, $schedules->count()),
                'reducing_balance_equal_installments' => $this->calculator->reducingBalanceEqualInstallments($amount, $periodicRate, $schedules->count()),
                default => $this->calculator->decliningBalance($amount, $periodicRate, $schedules->count()),
            };
            $interestAdded = round(array_sum(array_column($rows, 'interest')), 2);
            $before = [];
            $after = [];
            foreach ($schedules->values() as $index => $schedule) {
                $before[$schedule->id] = $this->snapshot($schedule);
                $principalAdded = (float) $rows[$index]['principal'];
                $periodInterest = (float) $rows[$index]['interest'];
                $dueDate = CarbonImmutable::parse($schedule->due_date)->addDays($extensionDays);
                $isOverdue = $dueDate->lt($today->subDay());
                $schedule->update([
                    'due_date' => $dueDate->toDateString(),
                    'extension_days' => (int) $schedule->extension_days + $extensionDays,
                    'principal_due' => round((float) $schedule->principal_due + $principalAdded, 2),
                    'interest_due' => round((float) $schedule->interest_due + $periodInterest, 2),
                    'total_due' => round((float) $schedule->total_due + $principalAdded + $periodInterest, 2),
                    'outstanding_amount' => round((float) $schedule->outstanding_amount + $principalAdded + $periodInterest, 2),
                    'balance_due' => round((float) $schedule->balance_due + $principalAdded + $periodInterest, 2),
                    'days_overdue' => $isOverdue ? $this->calendar->businessDaysBetween($dueDate, $today, (int) $loan->organization_id) : 0,
                    'status' => $isOverdue ? 'overdue' : ((float) $schedule->total_paid > 0 ? 'partially_paid' : 'pending'),
                ]);
                $after[$schedule->id] = $this->snapshot($schedule->refresh());
            }

            $type = TransactionType::query()->firstOrCreate(
                ['code' => 'loan_disbursement'],
                ['name' => 'Loan Disbursement', 'direction' => 'credit'],
            );
            $transaction = $this->transactions->postNonCashCredit($savings, $type, $amount, $user,
                "Loan top-up to savings for {$loan->loan_number}", [
                    ['account_code' => '1200', 'entry_type' => 'debit', 'amount' => $amount, 'memo' => 'Additional loan principal'],
                    ['account_code' => '2100', 'entry_type' => 'credit', 'amount' => $amount, 'memo' => 'Top-up credited to client savings'],
                ], $businessDate);
            $disbursement = LoanDisbursement::query()->create([
                'organization_id' => $loan->organization_id,
                'branch_id' => $loan->branch_id,
                'loan_id' => $loan->id,
                'disbursed_by' => $user->id,
                'disbursed_amount' => $amount,
                'fees_deducted' => 0,
                'net_amount' => $amount,
                'payment_method' => 'top_up_to_savings',
                'transaction_reference' => $transaction->reference,
                'disbursement_date' => $businessDate->toDateString(),
                'status' => 'successful',
            ]);

            $previousStatus = $loan->status;
            $loan->update([
                'approved_amount' => round((float) $loan->approved_amount + $amount, 2),
                'principal_balance' => round((float) $loan->principal_balance + $amount, 2),
                'interest_balance' => round((float) $loan->interest_balance + $interestAdded, 2),
                'status' => $loan->schedules()->eligibleForArrears($today)->exists() ? 'in_arrears' : 'active',
            ]);
            $loanAccount->update(['balance' => round((float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance, 2)]);
            $topUp = LoanTopUp::query()->create([
                'organization_id' => $loan->organization_id,
                'loan_id' => $loan->id,
                'transaction_id' => $transaction->id,
                'loan_disbursement_id' => $disbursement->id,
                'created_by' => $user->id,
                'submission_token' => $submissionToken,
                'principal_added' => $amount,
                'interest_added' => $interestAdded,
                'extension_days' => $extensionDays,
                'previous_maturity_date' => $previousMaturity->toDateString(),
                'new_maturity_date' => $loan->schedules()->max('due_date'),
                'previous_loan_status' => $previousStatus,
                'latest_repayment_id' => (int) ($loan->repayments()->max('id') ?? 0),
                'schedule_before' => $before,
                'schedule_after' => $after,
                'reason' => $reason,
            ]);
            $this->auditLogger->record('loan.topped_up', $loan, [
                'top_up_id' => $topUp->id,
                'transaction_reference' => $transaction->reference,
                'principal_added' => $amount,
                'interest_added' => $interestAdded,
                'extension_days' => $extensionDays,
                'previous_maturity_date' => $previousMaturity->toDateString(),
                'new_maturity_date' => $topUp->new_maturity_date->toDateString(),
                'reason' => $reason,
            ], actor: $user);

            return $topUp;
        });
    }

    public function reverse(LoanTopUp $topUp, User $checker): void
    {
        $topUp = LoanTopUp::query()->whereKey($topUp->id)->lockForUpdate()->firstOrFail();
        $loan = Loan::query()->whereKey($topUp->loan_id)->lockForUpdate()->firstOrFail();
        if ($topUp->status !== 'posted' || $loan->topUps()->where('status', 'posted')->where('id', '>', $topUp->id)->exists()) {
            throw new InvalidArgumentException('Only the latest active loan top-up can be reversed.');
        }
        if ($loan->repayments()->where('id', '>', $topUp->latest_repayment_id)->where('status', '!=', 'reversed')->exists()) {
            throw new InvalidArgumentException('Reverse later loan repayments before reversing this top-up.');
        }
        foreach ($topUp->schedule_after as $scheduleId => $expected) {
            $schedule = LoanSchedule::query()->where('loan_id', $loan->id)->whereKey($scheduleId)->lockForUpdate()->first();
            if (! $schedule || $this->snapshot($schedule) !== $expected) {
                throw new InvalidArgumentException('The repayment schedule changed after this top-up. Review it before reversing the top-up.');
            }
            $schedule->update($topUp->schedule_before[$scheduleId]);
        }
        $loan->update([
            'approved_amount' => round((float) $loan->approved_amount - (float) $topUp->principal_added, 2),
            'principal_balance' => round((float) $loan->principal_balance - (float) $topUp->principal_added, 2),
            'interest_balance' => round((float) $loan->interest_balance - (float) $topUp->interest_added, 2),
            'status' => $topUp->previous_loan_status,
        ]);
        Account::query()->whereKey($loan->loan_account_id)->lockForUpdate()->update([
            'balance' => round((float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance, 2),
        ]);
        $topUp->disbursement()->update(['status' => 'reversed']);
        $topUp->update(['status' => 'reversed']);
        $this->auditLogger->record('loan.top_up_reversed', $loan, ['top_up_id' => $topUp->id], actor: $checker);
    }

    /** @return array<string, mixed> */
    private function snapshot(LoanSchedule $schedule): array
    {
        return collect($schedule->only([
            'due_date', 'extension_days', 'principal_due', 'interest_due', 'total_due',
            'outstanding_amount', 'balance_due', 'days_overdue', 'status',
        ]))->map(fn (mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)->all();
    }
}
