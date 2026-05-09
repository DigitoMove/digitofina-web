<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Approval;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\LoanSchedule;
use App\Models\TransactionType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LoanService
{
    public function __construct(
        private AccountNumberGenerator $numbers,
        private AuditLogger $auditLogger,
        private TransactionPostingService $transactions,
    ) {
    }

    public function approve(Loan $loan, User $approver, float $approvedAmount, ?string $comments = null): Loan
    {
        if ($approver->hasRole('cashier') || $approver->hasRole('member')) {
            throw new InvalidArgumentException('Cashiers and members cannot approve loans.');
        }

        return DB::transaction(function () use ($loan, $approver, $approvedAmount, $comments) {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $loan->update([
                'approved_amount' => $approvedAmount,
                'principal_balance' => $approvedAmount,
                'status' => 'approved',
                'approved_at' => now()->toDateString(),
            ]);

            Approval::create([
                'approvable_type' => $loan->getMorphClass(),
                'approvable_id' => $loan->id,
                'requested_by' => $loan->officer_id,
                'approved_by' => $approver->id,
                'level' => 'Admin / Final Approver',
                'status' => 'approved',
                'comments' => $comments,
                'acted_at' => now(),
            ]);

            $this->auditLogger->record('loan.approved', $loan, ['approved_amount' => $approvedAmount]);

            return $loan->refresh();
        });
    }

    public function disburseToSavings(Loan $loan, User $user): Loan
    {
        return DB::transaction(function () use ($loan, $user) {
            $loan = Loan::query()->with(['client', 'product', 'savingsAccount'])->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($loan->status !== 'approved') {
                throw new InvalidArgumentException('Loan must be approved before disbursement.');
            }

            $savings = $loan->savingsAccount ?: $loan->client->accounts()
                ->whereHas('accountType', fn ($query) => $query->where('category', 'savings'))
                ->first();

            if (! $savings) {
                throw new InvalidArgumentException('Client needs a savings account before disbursement.');
            }

            $loanType = AccountType::query()->where('category', 'loan')->firstOrFail();
            $loanAccount = Account::create([
                'client_id' => $loan->client_id,
                'branch_id' => $loan->branch_id,
                'account_type_id' => $loanType->id,
                'loan_id' => $loan->id,
                'account_number' => $this->numbers->accountNumber($loan->client->branch, $loanType),
                'account_name' => $loan->client->full_name.' Loan Account',
                'status' => 'active',
                'balance' => $loan->approved_amount,
                'opened_at' => now()->toDateString(),
            ]);

            $type = TransactionType::query()->where('code', 'loan_disbursement')->firstOrFail();
            $this->transactions->postNonCashCredit($savings, $type, (float) $loan->approved_amount, $user, 'Loan disbursement to savings account', [
                ['account_code' => '1200', 'entry_type' => 'debit', 'amount' => $loan->approved_amount, 'memo' => 'Loan principal raised'],
                ['account_code' => '2100', 'entry_type' => 'credit', 'amount' => $loan->approved_amount, 'memo' => 'Loan proceeds credited to savings'],
            ]);

            $loan->update([
                'savings_account_id' => $savings->id,
                'loan_account_id' => $loanAccount->id,
                'status' => 'active',
                'disbursed_at' => now()->toDateString(),
            ]);

            $this->generateSchedule($loan->refresh(), now());
            $this->auditLogger->record('loan.disbursed_to_savings', $loan, ['amount' => $loan->approved_amount]);

            return $loan->refresh();
        });
    }

    public function generateSchedule(Loan $loan, CarbonInterface $startDate): void
    {
        $loan->schedules()->delete();

        $periods = max(1, $loan->term_periods);
        $principal = round(((float) $loan->approved_amount) / $periods, 2);
        $remaining = (float) $loan->approved_amount;

        for ($i = 1; $i <= $periods; $i++) {
            $interest = $loan->product->interest_method === 'flat'
                ? round(((float) $loan->approved_amount) * ((float) $loan->interest_rate / 100) / $periods, 2)
                : round($remaining * ((float) $loan->interest_rate / 100) / $periods, 2);

            $dueDate = match ($loan->repayment_frequency) {
                'daily' => $startDate->copy()->addDays($i),
                'weekly' => $startDate->copy()->addWeeks($i),
                default => $startDate->copy()->addMonths($i),
            };

            $principalDue = $i === $periods ? round($remaining, 2) : $principal;

            LoanSchedule::create([
                'loan_id' => $loan->id,
                'installment_number' => $i,
                'due_date' => $dueDate->toDateString(),
                'principal_due' => $principalDue,
                'interest_due' => $interest,
                'total_due' => $principalDue + $interest,
                'outstanding_amount' => $principalDue + $interest,
            ]);

            $remaining -= $principalDue;
        }
    }

    public function repay(Loan $loan, float $amount, User $user, ?int $transactionId = null): LoanRepayment
    {
        return DB::transaction(function () use ($loan, $amount, $user, $transactionId) {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $remaining = $amount;

            $penaltyPaid = min($remaining, (float) $loan->penalty_balance);
            $remaining -= $penaltyPaid;
            $feesPaid = min($remaining, (float) $loan->fees_balance);
            $remaining -= $feesPaid;
            $interestPaid = min($remaining, (float) $loan->interest_balance);
            $remaining -= $interestPaid;
            $principalPaid = min($remaining, (float) $loan->principal_balance);

            $loan->update([
                'penalty_balance' => (float) $loan->penalty_balance - $penaltyPaid,
                'fees_balance' => (float) $loan->fees_balance - $feesPaid,
                'interest_balance' => (float) $loan->interest_balance - $interestPaid,
                'principal_balance' => (float) $loan->principal_balance - $principalPaid,
                'status' => ((float) $loan->principal_balance - $principalPaid) <= 0 ? 'completed' : $loan->status,
            ]);

            $this->allocateSchedulePayments($loan, $amount);

            $repayment = LoanRepayment::create([
                'loan_id' => $loan->id,
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'penalty_paid' => $penaltyPaid,
                'fees_paid' => $feesPaid,
                'interest_paid' => $interestPaid,
                'principal_paid' => $principalPaid,
                'paid_at' => now()->toDateString(),
            ]);

            foreach ([
                ['4100', 'credit', $interestPaid],
                ['4200', 'credit', $feesPaid + $penaltyPaid],
                ['1200', 'credit', $principalPaid],
            ] as [$accountCode, $entryType, $entryAmount]) {
                if ($entryAmount > 0) {
                    $chartAccount = ChartAccount::query()->where('code', $accountCode)->first();

                    JournalEntry::create([
                        'transaction_id' => $transactionId,
                        'chart_account_id' => $chartAccount?->id,
                        'reference' => 'LR-'.$repayment->id,
                        'account_name' => $chartAccount?->name ?? $accountCode,
                        'entry_type' => $entryType,
                        'amount' => $entryAmount,
                        'memo' => 'Loan repayment allocation',
                    ]);
                }
            }

            $this->auditLogger->record('loan.repayment_posted', $loan, ['amount' => $amount]);

            return $repayment;
        });
    }

    private function allocateSchedulePayments(Loan $loan, float $amount): void
    {
        $remaining = $amount;

        foreach ($loan->schedules()->where('status', '!=', 'paid')->orderBy('due_date')->lockForUpdate()->get() as $schedule) {
            if ($remaining <= 0) {
                break;
            }

            $pay = min($remaining, (float) $schedule->outstanding_amount);
            $outstanding = (float) $schedule->outstanding_amount - $pay;

            $schedule->update([
                'amount_paid' => (float) $schedule->amount_paid + $pay,
                'outstanding_amount' => $outstanding,
                'status' => $outstanding <= 0 ? 'paid' : 'partially_paid',
            ]);

            $remaining -= $pay;
        }
    }
}
