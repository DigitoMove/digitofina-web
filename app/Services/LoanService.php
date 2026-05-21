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
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\LoanRepaymentAllocation;
use App\Models\LoanSchedule;
use App\Models\RoleFinancialLimit;
use App\Models\SystemSetting;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\ApiSecurity;
use App\Support\CurrencyDenomination;
use Carbon\CarbonImmutable;
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
        private LoanApplicationFeeService $applicationFees,
        private BusinessCalendarService $calendar,
        private InAppNotificationService $notifications,
    ) {}

    public function approve(Loan $loan, User $approver, float $approvedAmount, ?string $comments = null): Loan
    {
        CurrencyDenomination::assertValid($approvedAmount, 'Approved amount');

        if ($approver->hasRole('teller-cashier') || $approver->hasRole('member')) {
            throw new InvalidArgumentException('Tellers/cashiers and members cannot approve loans.');
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

            $workflowDate = $this->effectiveWorkflowDate($loan);

            $loan->update([
                'approved_amount' => $approvedAmount,
                'principal_balance' => $approvedAmount,
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => $workflowDate->toDateString(),
            ]);

            $this->generateSchedule($loan->refresh()->load('product'), $workflowDate);

            Approval::create([
                'approvable_type' => $loan->getMorphClass(),
                'approvable_id' => $loan->id,
                'requested_by' => $loan->officer_id,
                'approved_by' => $approver->id,
                'level' => 'Supervisor / Final Approver',
                'status' => 'approved',
                'comments' => $comments,
                'acted_at' => $workflowDate,
            ]);

            LoanApproval::create([
                'organization_id' => $loan->organization_id,
                'loan_id' => $loan->id,
                'approver_id' => $approver->id,
                'approval_level' => $this->approvalLevel($approvedAmount),
                'role_required' => $this->approvalRole($approvedAmount),
                'status' => 'approved',
                'notes' => $comments,
                'acted_at' => $workflowDate,
            ]);

            $this->auditLogger->record('loan.approved', $loan, ['approved_amount' => $approvedAmount]);
            $freshLoan = $loan->refresh()->loadMissing('client', 'officer');
            $loanUrl = route('loans.show', $freshLoan);
            $this->notifications->notifyLoanOfficer(
                $freshLoan,
                'Loan approved',
                "{$freshLoan->client?->full_name}'s loan {$freshLoan->loan_number} has been approved for ".number_format($approvedAmount, 2).'.',
                $loanUrl,
                'loan',
                'success',
            );
            $this->notifications->notifyLoanClient(
                $freshLoan,
                'Your loan has been approved',
                'Your loan application has been approved and is waiting for disbursement.',
                $loanUrl,
                'loan',
                'success',
            );
            $this->notifications->notifyStaffByPermission(
                ['disburse-loans'],
                $freshLoan->branch_id,
                $freshLoan->organization_id,
                'Loan ready for disbursement',
                "Loan {$freshLoan->loan_number} for {$freshLoan->client?->full_name} has been approved and is ready for disbursement.",
                $loanUrl,
                'loan',
                'warning',
                ['loan_id' => $freshLoan->id, 'loan_number' => $freshLoan->loan_number],
            );

            return $freshLoan;
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
            $freshLoan = $loan->refresh()->loadMissing('client');
            $this->notifications->notifyLoanOfficer(
                $freshLoan,
                'Loan rejected',
                "Loan {$freshLoan->loan_number} was rejected. Reason: {$reason}",
                route('loans.show', $freshLoan),
                'loan',
                'danger',
            );
            $this->notifications->notifyLoanClient(
                $freshLoan,
                'Your loan was not approved',
                'Your loan application was rejected. Please contact your Loan Officer for details.',
                route('loans.show', $freshLoan),
                'loan',
                'danger',
            );

            return $freshLoan;
        });
    }

    public function cancel(Loan $loan, User $user, string $reason): Loan
    {
        abort_unless($user->hasRole('super-admin') || $user->canPerform('manage-users') || $user->canPerform('disburse-loans'), 403);

        return DB::transaction(function () use ($loan, $user, $reason): Loan {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if (in_array($loan->status, ['disbursed', 'active', 'in_arrears', 'fully_paid', 'closed', 'written_off'], true)) {
                throw new InvalidArgumentException('This loan can no longer be cancelled.');
            }

            $loan->update([
                'status' => 'cancelled',
                'reviewed_by' => $user->id,
                'rejection_reason' => $reason,
            ]);

            LoanApproval::create([
                'organization_id' => $loan->organization_id,
                'loan_id' => $loan->id,
                'approver_id' => $user->id,
                'approval_level' => 1,
                'status' => 'cancelled',
                'notes' => $reason,
                'acted_at' => now(),
            ]);

            $this->auditLogger->record('loan.cancelled', $loan, ['reason' => $reason]);
            $freshLoan = $loan->refresh()->loadMissing('client');
            $this->notifications->notifyLoanOfficer(
                $freshLoan,
                'Loan cancelled',
                "Loan {$freshLoan->loan_number} was cancelled. Reason: {$reason}",
                route('loans.show', $freshLoan),
                'loan',
                'warning',
            );
            $this->notifications->notifyLoanClient(
                $freshLoan,
                'Your loan was cancelled',
                'Your loan application was cancelled. Please contact your Loan Officer for details.',
                route('loans.show', $freshLoan),
                'loan',
                'warning',
            );

            return $freshLoan;
        });
    }

    public function assignOfficer(Loan $loan, User $user, int $officerId): Loan
    {
        abort_unless($user->hasRole('super-admin') || $user->canPerform('manage-users') || $user->canPerform('disburse-loans'), 403);

        return DB::transaction(function () use ($loan, $user, $officerId): Loan {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $assignedOfficer = User::query()
                ->with('role')
                ->where('status', 'active')
                ->whereKey($officerId)
                ->firstOrFail();

            if (! in_array($assignedOfficer->role?->slug, ['branch-manager', 'loan-officer'], true)) {
                throw new InvalidArgumentException('Select an active branch head or Loan Officer.');
            }

            if ($loan->organization_id && $assignedOfficer->organization_id && (int) $loan->organization_id !== (int) $assignedOfficer->organization_id) {
                throw new InvalidArgumentException('The assigned officer must belong to this organization.');
            }

            if ($loan->branch_id && $assignedOfficer->branch_id && (int) $loan->branch_id !== (int) $assignedOfficer->branch_id && ! ApiSecurity::hasGlobalBranchAccess($user)) {
                throw new InvalidArgumentException('The assigned officer must belong to this branch.');
            }

            $oldOfficerId = $loan->officer_id;
            $loan->update(['officer_id' => $assignedOfficer->id]);

            $this->auditLogger->record('loan.officer_assigned', $loan, [
                'old_officer_id' => $oldOfficerId,
                'new_officer_id' => $assignedOfficer->id,
                'assigned_by' => $user->id,
            ]);
            $freshLoan = $loan->refresh()->loadMissing('client');
            $this->notifications->notifyUser(
                $assignedOfficer,
                'Loan assigned to you',
                "Loan {$freshLoan->loan_number} for {$freshLoan->client?->full_name} has been assigned to you.",
                route('loans.show', $freshLoan),
                'loan',
                'info',
                ['loan_id' => $freshLoan->id, 'loan_number' => $freshLoan->loan_number],
            );

            return $freshLoan;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraftTerms(Loan $loan, User $user, array $data): Loan
    {
        abort_unless($user->hasRole('super-admin') || $user->canPerform('manage-users') || $user->canPerform('disburse-loans'), 403);

        return DB::transaction(function () use ($loan, $data): Loan {
            $loan = Loan::query()->with(['client.branch'])->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($loan->status !== 'draft') {
                throw new InvalidArgumentException('Only draft loan applications can be adjusted.');
            }

            $product = LoanProduct::query()->whereKey($data['loan_product_id'])->firstOrFail();
            $requestedAmount = (float) $data['requested_amount'];
            $termPeriods = (int) $data['term_periods'];
            CurrencyDenomination::assertValid($requestedAmount, 'Requested amount');

            if ($requestedAmount < (float) $product->min_amount || $requestedAmount > (float) $product->max_amount) {
                throw new InvalidArgumentException('Requested amount is outside the selected product limits.');
            }

            if ($termPeriods < (int) $product->min_term || $termPeriods > (int) $product->max_term) {
                throw new InvalidArgumentException('Installments are outside the selected product term limits.');
            }

            $loan->update([
                'loan_product_id' => $product->id,
                'requested_amount' => $requestedAmount,
                'interest_rate' => $product->annual_interest_rate,
                'term_periods' => $termPeriods,
                'repayment_frequency' => $product->repayment_frequency,
                'required_savings_amount' => round($requestedAmount * ((float) $product->required_savings_percentage / 100), 2),
                'application_date' => $data['application_date'] ?? $loan->application_date,
            ]);

            $this->generateSchedule($loan->refresh()->load('product'), CarbonImmutable::parse($loan->application_date?->toDateString() ?? today()->toDateString()));
            $this->auditLogger->record('loan.draft_terms_updated', $loan, [
                'requested_amount' => $requestedAmount,
                'loan_product_id' => $product->id,
                'term_periods' => $termPeriods,
            ]);

            return $loan->refresh();
        });
    }

    public function generateSavingsAccount(Loan $loan, User $user): Account
    {
        abort_unless($user->canPerform('manage-accounts') || $user->canPerform('manage-loans'), 403);

        return DB::transaction(function () use ($loan): Account {
            $loan = Loan::query()->with(['client.branch'])->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($loan->savings_account_id) {
                return Account::query()->findOrFail($loan->savings_account_id);
            }

            $existing = $loan->client->accounts()
                ->whereHas('accountType', fn ($query) => $query->where('category', 'savings'))
                ->first();

            if ($existing) {
                $loan->update(['savings_account_id' => $existing->id]);

                return $existing;
            }

            $type = AccountType::query()->where('category', 'savings')->firstOrFail();
            $account = Account::create([
                'organization_id' => $loan->organization_id,
                'client_id' => $loan->client_id,
                'branch_id' => $loan->branch_id,
                'account_type_id' => $type->id,
                'account_number' => $this->numbers->accountNumber($loan->client->branch, $type),
                'account_name' => $loan->client->full_name.' Savings Account',
                'status' => 'active',
                'balance' => 0,
                'opened_at' => now()->toDateString(),
            ]);

            $loan->update(['savings_account_id' => $account->id]);
            $this->auditLogger->record('loan.savings_account_generated', $loan, ['account_number' => $account->account_number]);
            $this->notifications->notifyAccountClient(
                $account,
                'Savings account generated',
                "Savings account {$account->account_number} was generated for loan processing.",
                route('clients.accounts', $loan->client),
                'account',
                'success',
            );

            return $account;
        });
    }

    public function generateLoanAccount(Loan $loan, User $user): Account
    {
        abort_unless($user->canPerform('manage-accounts') || $user->canPerform('manage-loans'), 403);

        return DB::transaction(function () use ($loan): Account {
            $loan = Loan::query()->with(['client.branch'])->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($loan->loan_account_id) {
                return Account::query()->findOrFail($loan->loan_account_id);
            }

            $type = AccountType::query()->where('category', 'loan')->firstOrFail();
            $account = Account::create([
                'organization_id' => $loan->organization_id,
                'client_id' => $loan->client_id,
                'branch_id' => $loan->branch_id,
                'account_type_id' => $type->id,
                'loan_id' => $loan->id,
                'account_number' => $this->numbers->accountNumber($loan->client->branch, $type),
                'account_name' => $loan->client->full_name.' Loan Account',
                'status' => 'active',
                'balance' => 0,
                'opened_at' => now()->toDateString(),
            ]);

            $loan->update(['loan_account_id' => $account->id]);
            $this->auditLogger->record('loan.loan_account_generated', $loan, ['account_number' => $account->account_number]);
            $this->notifications->notifyAccountClient(
                $account,
                'Loan account generated',
                "Loan account {$account->account_number} was generated for {$loan->loan_number}.",
                route('loans.show', $loan),
                'loan',
                'success',
                ['loan_id' => $loan->id, 'loan_number' => $loan->loan_number],
            );

            return $account;
        });
    }

    public function submitForAppraisal(Loan $loan, User $user): Loan
    {
        abort_unless($user->canPerform('manage-loans'), 403);

        return DB::transaction(function () use ($loan, $user): Loan {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($loan->status !== 'draft') {
                throw new InvalidArgumentException('Only draft loans can be submitted for appraisal.');
            }

            if (! $loan->savings_account_id || ! $loan->loan_account_id) {
                throw new InvalidArgumentException('Generate the savings and loan accounts before appraisal.');
            }

            $workflowDate = $this->effectiveWorkflowDate($loan);

            $loan->update([
                'status' => 'under_review',
                'reviewed_by' => $user->id,
                'submitted_at' => $workflowDate->toDateString(),
            ]);

            $this->auditLogger->record('loan.submitted_for_appraisal', $loan);
            $freshLoan = $loan->refresh()->loadMissing('client');
            $this->notifications->notifyLoanOfficer(
                $freshLoan,
                'Loan submitted for appraisal',
                "Loan {$freshLoan->loan_number} is now under appraisal.",
                route('loans.show', $freshLoan),
                'loan',
                'info',
            );

            return $freshLoan;
        });
    }

    public function submitForApproval(Loan $loan, User $user): Loan
    {
        abort_unless($user->canPerform('manage-loans'), 403);

        return DB::transaction(function () use ($loan): Loan {
            $loan = Loan::query()->with('product')->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($loan->status !== 'under_review') {
                throw new InvalidArgumentException('Only appraised loans can be submitted for approval.');
            }

            $status = match (true) {
                $loan->product->require_guarantors => 'pending_guarantor_approval',
                $loan->product->require_collateral => 'pending_collateral_verification',
                default => 'submitted',
            };

            $workflowDate = $this->effectiveWorkflowDate($loan);

            $loan->update([
                'status' => $status,
                'submitted_at' => $workflowDate->toDateString(),
            ]);

            $this->auditLogger->record('loan.submitted_for_approval', $loan);
            $freshLoan = $loan->refresh()->loadMissing('client');
            $this->notifications->notifyStaffByPermission(
                ['approve-loans'],
                $freshLoan->branch_id,
                $freshLoan->organization_id,
                'Loan waiting for approval',
                "Loan {$freshLoan->loan_number} for {$freshLoan->client?->full_name} has been submitted for approval.",
                route('loans.show', $freshLoan),
                'loan',
                'warning',
                ['loan_id' => $freshLoan->id, 'loan_number' => $freshLoan->loan_number],
            );

            return $freshLoan;
        });
    }

    public function disburseToSavings(Loan $loan, User $user, string $paymentMethod = 'transfer_to_savings'): Loan
    {
        return DB::transaction(function () use ($loan, $user, $paymentMethod) {
            $loan = Loan::query()->with(['branch', 'client', 'product', 'savingsAccount', 'loanAccount'])->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($loan->status !== 'approved') {
                throw new InvalidArgumentException('Loan must be approved before disbursement.');
            }

            $savings = $loan->savingsAccount ?: $loan->client->accounts()
                ->whereHas('accountType', fn ($query) => $query->where('category', 'savings'))
                ->first();

            if (! $savings) {
                throw new InvalidArgumentException('Client needs a savings account before disbursement.');
            }

            $loanAccount = $loan->loanAccount ?: $this->generateLoanAccount($loan, $user);
            $loanAccount->update(['balance' => $loan->approved_amount]);

            $requestedAmountForFee = (float) $loan->requested_amount;
            $fees = round($this->applicationFees->feeFor($requestedAmountForFee), 2);
            CurrencyDenomination::assertValid((float) $loan->approved_amount, 'Approved amount');

            if ($fees > 0) {
                CurrencyDenomination::assertValid($fees, 'Application fee');
            }

            if ($fees > (float) $loan->approved_amount) {
                throw new InvalidArgumentException('Application fee cannot exceed the approved loan amount.');
            }

            $netAmount = max(0, round((float) $loan->approved_amount - $fees, 2));
            $type = TransactionType::query()->where('code', 'loan_disbursement')->firstOrFail();
            $applicationFeeIncomeCode = $this->applicationFeeIncomeCode($loan);
            $journals = [
                ['account_code' => '1200', 'entry_type' => 'debit', 'amount' => $loan->approved_amount, 'memo' => 'Loan principal raised'],
                ['account_code' => '2100', 'entry_type' => 'credit', 'amount' => $netAmount, 'memo' => 'Loan proceeds credited to savings'],
            ];

            if ($fees > 0) {
                $journals[] = ['account_code' => $applicationFeeIncomeCode, 'entry_type' => 'credit', 'amount' => $fees, 'memo' => 'Loan application fee deducted'];
            }

            $disbursementDate = $this->effectiveWorkflowDate($loan);
            $transaction = $this->transactions->postNonCashCredit($savings, $type, $netAmount, $user, 'Loan disbursement to savings account', $journals, $disbursementDate);

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
                'disbursement_date' => $disbursementDate->toDateString(),
                'status' => 'successful',
            ]);

            $loan->update([
                'savings_account_id' => $savings->id,
                'loan_account_id' => $loanAccount->id,
                'status' => 'active',
                'fees_balance' => 0,
                'disbursed_at' => $disbursementDate->toDateString(),
            ]);

            $this->generateSchedule($loan->refresh(), $disbursementDate);
            $this->auditLogger->record('loan.disbursed_to_savings', $loan, [
                'amount' => $loan->approved_amount,
                'application_fee_basis' => $requestedAmountForFee,
                'application_fee' => $fees,
            ]);
            $freshLoan = $loan->refresh()->loadMissing('client', 'officer');
            $loanUrl = route('loans.show', $freshLoan);
            $this->notifications->notifyLoanOfficer(
                $freshLoan,
                'Loan disbursed',
                "Loan {$freshLoan->loan_number} has been disbursed to the client's savings account.",
                $loanUrl,
                'loan',
                'success',
            );
            $this->notifications->notifyLoanClient(
                $freshLoan,
                'Loan disbursed',
                'Your loan has been disbursed. Net amount credited: '.number_format($netAmount, 2).'.',
                $loanUrl,
                'loan',
                'success',
            );
            $this->notifications->notifyCashiers(
                $freshLoan->branch_id,
                $freshLoan->organization_id,
                'Loan disbursed to savings',
                "Loan {$freshLoan->loan_number} for {$freshLoan->client?->full_name} is now in savings and can be withdrawn from the cashier drawer.",
                $loanUrl,
                'drawer',
                'warning',
                ['loan_id' => $freshLoan->id, 'loan_number' => $freshLoan->loan_number],
            );

            return $freshLoan;
        });
    }

    public function generateSchedule(Loan $loan, CarbonInterface $startDate): void
    {
        $loan->loadMissing('product');
        $loan->schedules()->delete();

        $periods = max(1, $loan->term_periods);
        $periodicRate = $this->periodicRate((float) $loan->interest_rate, $loan->repayment_frequency);
        $principal = (float) ($loan->approved_amount ?: $loan->requested_amount);
        $rows = match ($loan->product->interest_method) {
            'flat' => $this->calculator->flatRate($principal, $periodicRate, $periods),
            'reducing_balance_equal_installments' => $this->calculator->reducingBalanceEqualInstallments($principal, $periodicRate, $periods),
            default => $this->calculator->decliningBalance($principal, $periodicRate, $periods),
        };
        $totalInterest = 0;

        foreach ($rows as $row) {
            $i = $row['installment'];
            $dueDate = $this->calendar->dueDate($startDate, $i, $loan->repayment_frequency);
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
                'status' => $loan->status === 'draft' ? 'preview' : 'pending',
            ]);

            $totalInterest += $row['interest'];
        }

        $loan->update(['interest_balance' => round($totalInterest, 2)]);
    }

    private function applicationFeeIncomeCode(Loan $loan): string
    {
        $branch = $loan->branch;
        $branchCode = $branch?->code;

        if ($branchCode) {
            $branchAccountCode = $branchCode.'-4330';

            if (ChartAccount::query()->where('code', $branchAccountCode)->where('is_active', true)->exists()) {
                return $branchAccountCode;
            }
        }

        return '4200';
    }

    public function repay(Loan $loan, float $amount, User $user, ?int $transactionId = null, string $paymentMethod = 'cash', ?string $reference = null, ?CarbonInterface $paidAt = null, string $counterAccountCode = '1000', string $journalMemo = 'Loan repayment allocation'): LoanRepayment
    {
        CurrencyDenomination::assertValid($amount);

        return DB::transaction(function () use ($loan, $amount, $user, $transactionId, $paymentMethod, $reference, $paidAt, $counterAccountCode, $journalMemo) {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if (! in_array($loan->status, ['active', 'in_arrears'], true)) {
                throw new InvalidArgumentException('Loan must be active before repayments can be posted.');
            }

            $paymentDate = $paidAt ?: now();
            $this->ensureRepaymentBusinessDateOpen($user, (int) $loan->branch_id, $paymentDate);

            $scheduleAllocation = $this->allocateSchedulePayments($loan, $amount);
            $penaltyPaid = $scheduleAllocation['totals']['penalty'];
            $feesPaid = $scheduleAllocation['totals']['fees'];
            $interestPaid = $scheduleAllocation['totals']['interest'];
            $principalPaid = $scheduleAllocation['totals']['principal'];

            if ($scheduleAllocation['applied'] <= 0) {
                throw new InvalidArgumentException('There are no outstanding installments to receive this repayment.');
            }

            $isSettled = ((float) $loan->principal_balance - $principalPaid) <= 0
                && ((float) $loan->interest_balance - $interestPaid) <= 0
                && ((float) $loan->fees_balance - $feesPaid) <= 0
                && ((float) $loan->penalty_balance - $penaltyPaid) <= 0;

            $loan->update([
                'penalty_balance' => max(0, (float) $loan->penalty_balance - $penaltyPaid),
                'fees_balance' => max(0, (float) $loan->fees_balance - $feesPaid),
                'interest_balance' => max(0, (float) $loan->interest_balance - $interestPaid),
                'principal_balance' => max(0, (float) $loan->principal_balance - $principalPaid),
                'status' => $isSettled ? 'closed' : $loan->status,
            ]);

            if ($isSettled && $loan->loan_account_id) {
                Account::query()
                    ->whereKey($loan->loan_account_id)
                    ->lockForUpdate()
                    ->update([
                        'balance' => 0,
                        'status' => 'closed',
                    ]);
            }

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

            foreach ($scheduleAllocation['allocations'] as $allocation) {
                LoanRepaymentAllocation::create($allocation + ['loan_repayment_id' => $repayment->id]);
            }

            foreach ([
                [$counterAccountCode, 'debit', $penaltyPaid + $feesPaid + $interestPaid + $principalPaid],
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
                        'memo' => $journalMemo,
                    ]);
                }
            }

            if ($isSettled) {
                $loan->loanGuarantors()->where('status', '!=', 'released')->update(['status' => 'released']);
                $loan->collaterals()->where('status', '!=', 'released')->update(['status' => 'released']);
            }

            $this->auditLogger->record('loan.repayment_posted', $loan, ['amount' => $amount]);
            $freshLoan = $loan->refresh()->loadMissing('client');
            $loanUrl = route('loans.show', $freshLoan);
            $this->notifications->notifyLoanOfficer(
                $freshLoan,
                'Loan repayment posted',
                'A repayment of '.number_format($amount, 2)." was posted to loan {$freshLoan->loan_number}.",
                $loanUrl,
                'loan',
                $isSettled ? 'success' : 'info',
            );
            $this->notifications->notifyLoanClient(
                $freshLoan,
                $isSettled ? 'Loan fully cleared' : 'Loan repayment received',
                $isSettled
                    ? 'Your loan has been fully cleared and closed.'
                    : 'Your loan repayment has been received.',
                $loanUrl,
                'loan',
                $isSettled ? 'success' : 'info',
                ['repayment_id' => $repayment->id, 'amount' => $amount],
            );

            return $repayment;
        });
    }

    public function reopenAndClearPaidInstallments(Loan $loan, User $user, string $reason): Loan
    {
        return DB::transaction(function () use ($loan, $user, $reason): Loan {
            $loan = Loan::query()
                ->with(['schedules', 'loanAccount'])
                ->whereKey($loan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($loan->status, ['closed', 'fully_paid'], true)) {
                throw new InvalidArgumentException('Only closed or fully paid loans can be reopened.');
            }

            $principalBalance = (float) $loan->schedules->sum('principal_due');
            $interestBalance = (float) $loan->schedules->sum('interest_due');
            $feesBalance = (float) $loan->schedules->sum('fees_due');
            $penaltyBalance = (float) $loan->schedules->sum('penalty_due');
            $totalBalance = $principalBalance + $interestBalance + $feesBalance + $penaltyBalance;
            $status = $loan->schedules->contains(fn (LoanSchedule $schedule): bool => $schedule->due_date?->lt(today()) === true)
                ? 'in_arrears'
                : 'active';

            foreach ($loan->schedules as $schedule) {
                $isOverdue = $schedule->due_date?->lt(today()) === true;

                $schedule->update([
                    'principal_paid' => 0,
                    'interest_paid' => 0,
                    'fees_paid' => 0,
                    'penalty_paid' => 0,
                    'total_paid' => 0,
                    'amount_paid' => 0,
                    'outstanding_amount' => (float) $schedule->total_due,
                    'balance_due' => (float) $schedule->total_due,
                    'status' => $isOverdue ? 'overdue' : 'pending',
                    'days_overdue' => $isOverdue ? $this->calendar->businessDaysBetween($schedule->due_date, today()) : 0,
                ]);
            }

            $loan->repayments()
                ->where('status', 'successful')
                ->update([
                    'status' => 'reversed',
                    'updated_at' => now(),
                ]);

            $loan->update([
                'principal_balance' => $principalBalance,
                'interest_balance' => $interestBalance,
                'fees_balance' => $feesBalance,
                'penalty_balance' => $penaltyBalance,
                'status' => $status,
            ]);

            if ($loan->loan_account_id) {
                Account::query()
                    ->whereKey($loan->loan_account_id)
                    ->lockForUpdate()
                    ->update([
                        'balance' => $totalBalance,
                        'status' => 'active',
                    ]);
            }

            $this->auditLogger->record('loan.reopened_installments_cleared', $loan->fresh(), [
                'reason' => $reason,
                'reopened_by' => $user->id,
                'restored_balance' => $totalBalance,
            ], actor: $user);

            return $loan->refresh();
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
                $days = $this->calendar->businessDaysBetween($schedule->due_date, $asOf);
                $penaltyType = $loan->product?->penalty_type ?: 'percentage';
                $penaltyRate = (float) ($loan->product?->penalty_rate ?? 0);
                $penaltyRate = $penaltyRate > 0 ? $penaltyRate : (float) SystemSetting::value('loan_arrears_penalty_rate', 2);
                $repaymentFrequency = $loan->repayment_frequency ?: $loan->product?->repayment_frequency;
                [$penaltyPeriodStart, $penaltyPeriodEnd] = $this->penalties->penaltyPeriod(
                    $schedule,
                    $asOf,
                    $penaltyType,
                    $repaymentFrequency,
                );
                $alreadyApplied = LoanPenalty::query()
                    ->where('loan_schedule_id', $schedule->id)
                    ->whereDate('calculated_for_date', '>=', $penaltyPeriodStart->toDateString())
                    ->whereDate('calculated_for_date', '<=', $penaltyPeriodEnd->toDateString())
                    ->exists();

                $penaltyAmount = $alreadyApplied ? 0 : $this->penalties->calculate(
                    $schedule,
                    $penaltyRate,
                    $penaltyType,
                    $days,
                    $repaymentFrequency,
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
     * @return array{allocations:list<array{loan_schedule_id:int, penalty_paid:float, fees_paid:float, interest_paid:float, principal_paid:float, total_paid:float}>, totals:array{penalty:float, fees:float, interest:float, principal:float}, applied:float}
     */
    private function allocateSchedulePayments(Loan $loan, float $amount): array
    {
        $remaining = $amount;
        $allocations = [];
        $totals = [
            'penalty' => 0.0,
            'fees' => 0.0,
            'interest' => 0.0,
            'principal' => 0.0,
        ];

        foreach ($loan->schedules()->where('status', '!=', 'paid')->orderBy('due_date')->lockForUpdate()->get() as $schedule) {
            if ($remaining <= 0) {
                break;
            }

            $penaltyPaid = $this->allocateBucket($remaining, (float) $schedule->penalty_due - (float) $schedule->penalty_paid);
            $feesPaid = $this->allocateBucket($remaining, (float) $schedule->fees_due - (float) $schedule->fees_paid);
            $interestPaid = $this->allocateBucket($remaining, (float) $schedule->interest_due - (float) $schedule->interest_paid);
            $principalPaid = $this->allocateBucket($remaining, (float) $schedule->principal_due - (float) $schedule->principal_paid);
            $totalPaid = $penaltyPaid + $feesPaid + $interestPaid + $principalPaid;

            if ($totalPaid <= 0) {
                continue;
            }

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

            $allocations[] = [
                'loan_schedule_id' => $schedule->id,
                'penalty_paid' => $penaltyPaid,
                'fees_paid' => $feesPaid,
                'interest_paid' => $interestPaid,
                'principal_paid' => $principalPaid,
                'total_paid' => $totalPaid,
            ];

            $totals['penalty'] += $penaltyPaid;
            $totals['fees'] += $feesPaid;
            $totals['interest'] += $interestPaid;
            $totals['principal'] += $principalPaid;
        }

        return [
            'allocations' => $allocations,
            'totals' => $totals,
            'applied' => array_sum($totals),
        ];
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

    private function effectiveWorkflowDate(Loan $loan): CarbonInterface
    {
        $applicationDate = $loan->application_date
            ? CarbonImmutable::parse($loan->application_date->toDateString())
            : null;

        if ($applicationDate && $applicationDate->lt(today())) {
            return $applicationDate;
        }

        return CarbonImmutable::parse(today()->toDateString());
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
            default => 'Credit Committee / Supervisor',
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
