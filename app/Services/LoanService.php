<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Approval;
use App\Models\ChartAccount;
use App\Models\FinancialDay;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanApproval;
use App\Models\LoanDisbursement;
use App\Models\LoanPenalty;
use App\Models\LoanRepayment;
use App\Models\LoanSchedule;
use App\Models\RoleFinancialLimit;
use App\Models\TransactionType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LoanService
{
    public function __construct(
        private AccountNumberGenerator $numbers,
        private AuditLogger $auditLogger,
        private TransactionPostingService $transactions,
        private LoanCalculatorService $calculator,
        private PenaltyCalculationService $penalties,
    ) {}

    public function approve(Loan $loan, User $approver, float $approvedAmount, ?string $comments = null): Loan
    {
        if ($approver->hasRole('cashier') || $approver->hasRole('member')) {
            throw new InvalidArgumentException('Cashiers and members cannot approve loans.');
        }

        return DB::transaction(function () use ($loan, $approver, $approvedAmount, $comments) {
            $loan = Loan::query()->with(['product', 'loanGuarantors', 'collaterals'])->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if (! in_array($loan->status, ['submitted', 'under_review', 'pending_guarantor_approval', 'pending_collateral_verification'], true)) {
                throw new InvalidArgumentException('Only submitted loans can be approved.');
            }

            if ($approvedAmount < (float) $loan->product->min_amount || $approvedAmount > (float) $loan->product->max_amount) {
                throw new InvalidArgumentException('Approved amount is outside the product amount limits.');
            }

            if ($loan->product->require_guarantors && $loan->loanGuarantors()->where('status', 'accepted')->count() < (int) $loan->product->minimum_guarantors) {
                throw new InvalidArgumentException('This product requires accepted guarantors before approval.');
            }

            if ($loan->product->require_collateral && ! $loan->collaterals()->where('status', 'verified')->exists()) {
                throw new InvalidArgumentException('This product requires verified collateral before approval.');
            }

            $loan->update([
                'approved_amount' => $approvedAmount,
                'principal_balance' => $approvedAmount,
                'status' => 'approved',
                'approved_by' => $approver->id,
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

            LoanApproval::create([
                'organization_id' => $loan->organization_id,
                'loan_id' => $loan->id,
                'approver_id' => $approver->id,
                'approval_level' => $this->approvalLevel($approvedAmount),
                'role_required' => $this->approvalRole($approvedAmount),
                'status' => 'approved',
                'notes' => $comments,
                'acted_at' => now(),
            ]);

            $this->auditLogger->record('loan.approved', $loan, ['approved_amount' => $approvedAmount]);

            return $loan->refresh();
        });
    }

    public function reject(Loan $loan, User $user, string $reason): Loan
    {
        return DB::transaction(function () use ($loan, $user, $reason) {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if (in_array($loan->status, ['disbursed', 'active', 'fully_paid', 'closed'], true)) {
                throw new InvalidArgumentException('This loan can no longer be rejected.');
            }

            $loan->update([
                'status' => 'rejected',
                'reviewed_by' => $user->id,
                'rejection_reason' => $reason,
            ]);

            LoanApproval::create([
                'organization_id' => $loan->organization_id,
                'loan_id' => $loan->id,
                'approver_id' => $user->id,
                'approval_level' => 1,
                'status' => 'rejected',
                'notes' => $reason,
                'acted_at' => now(),
            ]);

            $this->auditLogger->record('loan.rejected', $loan, ['reason' => $reason]);

            return $loan->refresh();
        });
    }

    public function disburseToSavings(Loan $loan, User $user, string $paymentMethod = 'transfer_to_savings'): Loan
    {
        return DB::transaction(function () use ($loan, $user, $paymentMethod) {
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
                'organization_id' => $loan->organization_id,
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

            $fees = round((float) $loan->product->processing_fee + (float) $loan->product->insurance_fee, 2);
            $netAmount = max(0, round((float) $loan->approved_amount - $fees, 2));
            $type = TransactionType::query()->where('code', 'loan_disbursement')->firstOrFail();
            $transaction = $this->transactions->postNonCashCredit($savings, $type, $netAmount, $user, 'Loan disbursement to savings account', [
                ['account_code' => '1200', 'entry_type' => 'debit', 'amount' => $loan->approved_amount, 'memo' => 'Loan principal raised'],
                ['account_code' => '2100', 'entry_type' => 'credit', 'amount' => $netAmount, 'memo' => 'Loan proceeds credited to savings'],
                ['account_code' => '4200', 'entry_type' => 'credit', 'amount' => $fees, 'memo' => 'Loan disbursement fees deducted'],
            ]);

            LoanDisbursement::create([
                'organization_id' => $loan->organization_id,
                'branch_id' => $loan->branch_id,
                'loan_id' => $loan->id,
                'disbursed_by' => $user->id,
                'disbursed_amount' => $loan->approved_amount,
                'fees_deducted' => $fees,
                'net_amount' => $netAmount,
                'payment_method' => $paymentMethod,
                'transaction_reference' => $transaction->reference,
                'disbursement_date' => now()->toDateString(),
                'status' => 'successful',
            ]);

            $loan->update([
                'savings_account_id' => $savings->id,
                'loan_account_id' => $loanAccount->id,
                'status' => 'active',
                'fees_balance' => 0,
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
        $periodicRate = $this->periodicRate((float) $loan->interest_rate, $loan->repayment_frequency);
        $rows = match ($loan->product->interest_method) {
            'flat' => $this->calculator->flatRate((float) $loan->approved_amount, $periodicRate, $periods),
            'reducing_balance_equal_installments' => $this->calculator->reducingBalanceEqualInstallments((float) $loan->approved_amount, $periodicRate, $periods),
            default => $this->calculator->decliningBalance((float) $loan->approved_amount, $periodicRate, $periods),
        };
        $totalInterest = 0;

        foreach ($rows as $row) {
            $i = $row['installment'];
            $dueDate = match ($loan->repayment_frequency) {
                'daily' => $startDate->copy()->addDays($i),
                'weekly' => $startDate->copy()->addWeeks($i),
                'bi-weekly' => $startDate->copy()->addWeeks($i * 2),
                'quarterly' => $startDate->copy()->addMonths($i * 3),
                default => $startDate->copy()->addMonths($i),
            };
            $totalDue = round($row['principal'] + $row['interest'], 2);

            LoanSchedule::create([
                'loan_id' => $loan->id,
                'installment_number' => $i,
                'due_date' => $dueDate->toDateString(),
                'principal_due' => $row['principal'],
                'interest_due' => $row['interest'],
                'total_due' => $totalDue,
                'outstanding_amount' => $totalDue,
                'balance_due' => $totalDue,
            ]);

            $totalInterest += $row['interest'];
        }

        $loan->update(['interest_balance' => round($totalInterest, 2)]);
    }

    public function repay(Loan $loan, float $amount, User $user, ?int $transactionId = null, string $paymentMethod = 'cash', ?string $reference = null, ?CarbonInterface $paidAt = null): LoanRepayment
    {
        return DB::transaction(function () use ($loan, $amount, $user, $transactionId, $paymentMethod, $reference, $paidAt) {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if (! in_array($loan->status, ['active', 'in_arrears'], true)) {
                throw new InvalidArgumentException('Loan must be active before repayments can be posted.');
            }

            $paymentDate = $paidAt ?: now();
            $this->ensureRepaymentBusinessDateOpen($user, (int) $loan->branch_id, $paymentDate);

            $remaining = $amount;

            $penaltyPaid = min($remaining, (float) $loan->penalty_balance);
            $remaining -= $penaltyPaid;
            $feesPaid = min($remaining, (float) $loan->fees_balance);
            $remaining -= $feesPaid;
            $interestPaid = min($remaining, (float) $loan->interest_balance);
            $remaining -= $interestPaid;
            $principalPaid = min($remaining, (float) $loan->principal_balance);
            $remaining -= $principalPaid;
            $isSettled = ((float) $loan->principal_balance - $principalPaid) <= 0
                && ((float) $loan->interest_balance - $interestPaid) <= 0
                && ((float) $loan->fees_balance - $feesPaid) <= 0
                && ((float) $loan->penalty_balance - $penaltyPaid) <= 0;

            $loan->update([
                'penalty_balance' => max(0, (float) $loan->penalty_balance - $penaltyPaid),
                'fees_balance' => max(0, (float) $loan->fees_balance - $feesPaid),
                'interest_balance' => max(0, (float) $loan->interest_balance - $interestPaid),
                'principal_balance' => max(0, (float) $loan->principal_balance - $principalPaid),
                'status' => $isSettled ? 'fully_paid' : $loan->status,
            ]);

            $this->allocateSchedulePayments($loan, [
                'penalty' => $penaltyPaid,
                'fees' => $feesPaid,
                'interest' => $interestPaid,
                'principal' => $principalPaid,
            ]);

            $repayment = LoanRepayment::create([
                'organization_id' => $loan->organization_id,
                'loan_id' => $loan->id,
                'client_id' => $loan->client_id,
                'branch_id' => $loan->branch_id,
                'transaction_id' => $transactionId,
                'received_by' => $user->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference' => $reference ?: 'LR'.now()->format('ymdHis').Str::upper(Str::random(4)),
                'status' => 'successful',
                'penalty_paid' => $penaltyPaid,
                'fees_paid' => $feesPaid,
                'interest_paid' => $interestPaid,
                'principal_paid' => $principalPaid,
                'paid_at' => $paymentDate->toDateString(),
            ]);

            foreach ([
                ['1000', 'debit', $penaltyPaid + $feesPaid + $interestPaid + $principalPaid],
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

            if ($isSettled) {
                $loan->loanGuarantors()->where('status', '!=', 'released')->update(['status' => 'released']);
                $loan->collaterals()->where('status', '!=', 'released')->update(['status' => 'released']);
            }

            $this->auditLogger->record('loan.repayment_posted', $loan, ['amount' => $amount]);

            return $repayment;
        });
    }

    public function applyOverduePenalties(?Loan $loan = null, ?CarbonInterface $asOf = null, ?int $branchId = null): int
    {
        $asOf = $asOf ?: now();
        $count = 0;

        $query = LoanSchedule::query()
            ->with('loan.product')
            ->whereDate('due_date', '<', $asOf->toDateString())
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid');

        if ($loan) {
            $query->where('loan_id', $loan->id);
        }

        if ($branchId) {
            $query->whereHas('loan', fn ($query) => $query->where('branch_id', $branchId));
        }

        foreach ($query->cursor() as $schedule) {
            DB::transaction(function () use ($schedule, $asOf, &$count) {
                $schedule = LoanSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
                $loan = Loan::query()->with('product')->whereKey($schedule->loan_id)->lockForUpdate()->firstOrFail();
                $days = (int) $schedule->due_date->diffInDays($asOf);
                $alreadyApplied = LoanPenalty::query()
                    ->where('loan_schedule_id', $schedule->id)
                    ->whereDate('calculated_for_date', $asOf->toDateString())
                    ->exists();

                $penaltyAmount = $alreadyApplied ? 0 : $this->penalties->calculate(
                    $schedule,
                    (float) $loan->product->penalty_rate,
                    $loan->product->penalty_type,
                    $days
                );

                if ($penaltyAmount > 0) {
                    LoanPenalty::create([
                        'loan_id' => $loan->id,
                        'loan_schedule_id' => $schedule->id,
                        'amount' => $penaltyAmount,
                        'days_overdue' => $days,
                        'calculated_for_date' => $asOf->toDateString(),
                        'status' => 'pending',
                    ]);

                    $schedule->increment('penalty_due', $penaltyAmount);
                    $schedule->increment('total_due', $penaltyAmount);
                    $schedule->increment('outstanding_amount', $penaltyAmount);
                    $schedule->increment('balance_due', $penaltyAmount);
                    $loan->increment('penalty_balance', $penaltyAmount);
                }

                $schedule->update(['status' => 'overdue', 'days_overdue' => $days]);
                $loan->update(['status' => 'in_arrears']);
                $count++;
            });
        }

        return $count;
    }

    /**
     * @param  array{penalty:float, fees:float, interest:float, principal:float}  $amounts
     */
    private function allocateSchedulePayments(Loan $loan, array $amounts): void
    {
        $remaining = $amounts;

        foreach ($loan->schedules()->where('status', '!=', 'paid')->orderBy('due_date')->lockForUpdate()->get() as $schedule) {
            if (array_sum($remaining) <= 0) {
                break;
            }

            $penaltyPaid = $this->allocateBucket($remaining['penalty'], (float) $schedule->penalty_due - (float) $schedule->penalty_paid);
            $feesPaid = $this->allocateBucket($remaining['fees'], (float) $schedule->fees_due - (float) $schedule->fees_paid);
            $interestPaid = $this->allocateBucket($remaining['interest'], (float) $schedule->interest_due - (float) $schedule->interest_paid);
            $principalPaid = $this->allocateBucket($remaining['principal'], (float) $schedule->principal_due - (float) $schedule->principal_paid);
            $totalPaid = $penaltyPaid + $feesPaid + $interestPaid + $principalPaid;
            $outstanding = max(0, (float) $schedule->outstanding_amount - $totalPaid);

            $schedule->update([
                'principal_paid' => (float) $schedule->principal_paid + $principalPaid,
                'interest_paid' => (float) $schedule->interest_paid + $interestPaid,
                'fees_paid' => (float) $schedule->fees_paid + $feesPaid,
                'penalty_paid' => (float) $schedule->penalty_paid + $penaltyPaid,
                'total_paid' => (float) $schedule->total_paid + $totalPaid,
                'amount_paid' => (float) $schedule->amount_paid + $totalPaid,
                'outstanding_amount' => $outstanding,
                'balance_due' => $outstanding,
                'status' => $outstanding <= 0 ? 'paid' : 'partially_paid',
            ]);
        }
    }

    private function allocateBucket(float &$remaining, float $due): float
    {
        $paid = min($remaining, max(0, $due));
        $remaining -= $paid;

        return $paid;
    }

    private function periodicRate(float $annualRate, string $frequency): float
    {
        return match ($frequency) {
            'daily' => $annualRate / 365,
            'weekly' => $annualRate / 52,
            'bi-weekly' => $annualRate / 26,
            'quarterly' => $annualRate / 4,
            default => $annualRate / 12,
        };
    }

    private function approvalLevel(float $amount): int
    {
        return match (true) {
            $amount <= 1_000_000 => 1,
            $amount <= 10_000_000 => 2,
            default => 3,
        };
    }

    private function approvalRole(float $amount): string
    {
        return match ($this->approvalLevel($amount)) {
            1 => 'Branch Manager',
            2 => 'Branch Manager + Accountant',
            default => 'Credit Committee / Admin',
        };
    }

    private function ensureRepaymentBusinessDateOpen(User $user, int $branchId, CarbonInterface $paymentDate): void
    {
        if ($paymentDate->isPast() && ! $paymentDate->isSameDay(today())) {
            if (! $user->canPerform('post-backdated-transactions')) {
                throw new InvalidArgumentException('Backdated repayments require permission.');
            }

            $limit = RoleFinancialLimit::query()
                ->where('role_id', $user->role_id)
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $user->organization_id))
                ->latest('organization_id')
                ->first();

            if ($limit && (int) $limit->max_backdate_days > 0 && (int) $paymentDate->diffInDays(today()) > (int) $limit->max_backdate_days) {
                throw new InvalidArgumentException('Backdated repayment date is outside this role limit.');
            }
        }

        $financialDay = FinancialDay::query()
            ->where('branch_id', $branchId)
            ->whereDate('business_date', $paymentDate->toDateString())
            ->first();

        if ($financialDay && in_array($financialDay->status, ['closed', 'locked'], true)) {
            throw new InvalidArgumentException('The financial day is closed and locked for this branch.');
        }
    }
}
