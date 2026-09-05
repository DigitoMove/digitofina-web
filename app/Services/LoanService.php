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
use App\Models\LoanWriteOff;
use App\Models\RoleFinancialLimit;
use App\Models\SystemSetting;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\ApiSecurity;
use App\Support\CurrencyDenomination;
use App\Support\RoleCatalog;
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
        private BranchGeneralLedgerService $generalLedger,
    ) {}

    public function approve(Loan $loan, User $approver, float $approvedAmount, ?string $comments = null): Loan
    {
        CurrencyDenomination::assertValid($approvedAmount, 'Approved amount');

        if ($approver->hasRole('teller-cashier') || $approver->hasRole('member')) {
            throw new InvalidArgumentException('Tellers/cashiers and members cannot approve loans.');
        }

        return DB::transaction(function () use ($loan, $approver, $approvedAmount, $comments) {
            $loan = Loan::query()->with(['client', 'product', 'loanGuarantors', 'collaterals'])->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if (! in_array($loan->status, ['submitted', 'under_review', 'recommended', 'pending_guarantor_approval', 'pending_collateral_verification'], true)) {
                throw new InvalidArgumentException('Only submitted loans can be approved.');
            }

            $this->ensureLoanClientBioComplete($loan);

            if ($approvedAmount < (float) $loan->product->min_amount || $approvedAmount > (float) $loan->product->max_amount) {
                throw new InvalidArgumentException('Approved amount is outside the product amount limits.');
            }

            if ($loan->product->require_guarantors && $loan->loanGuarantors()->where('status', 'accepted')->count() < (int) $loan->product->minimum_guarantors) {
                throw new InvalidArgumentException('This product requires accepted guarantors before approval.');
            }

            $this->ensureMinimumGuarantorsAttached($loan);

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
            $loan->loanGuarantors()
                ->whereNotIn('status', ['rejected', 'released'])
                ->update(['guaranteed_amount' => $approvedAmount]);

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
                ['actor_id' => $approver->id],
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
                ['loan_id' => $freshLoan->id, 'loan_number' => $freshLoan->loan_number, 'actor_id' => $approver->id],
            );

            return $freshLoan;
        });
    }

    public function reject(Loan $loan, User $user, string $reason): Loan
    {
        return DB::transaction(function () use ($loan, $user, $reason) {
            $loan = Loan::query()->with(['branch', 'client.accounts.accountType', 'savingsAccount'])->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if (in_array($loan->status, ['disbursed', 'active', 'fully_paid', 'closed'], true)) {
                throw new InvalidArgumentException('This loan can no longer be rejected.');
            }

            $applicationFee = $this->collectRejectedLoanApplicationFee($loan, $user);

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

            $this->auditLogger->record('loan.rejected', $loan, [
                'reason' => $reason,
                'application_fee' => $applicationFee,
                'application_fee_basis' => (float) $loan->requested_amount,
            ]);
            $freshLoan = $loan->refresh()->loadMissing('client');
            $this->notifications->notifyLoanOfficer(
                $freshLoan,
                'Loan rejected',
                "Loan {$freshLoan->loan_number} was rejected. Reason: {$reason}",
                route('loans.show', $freshLoan),
                'loan',
                'danger',
                ['actor_id' => $user->id],
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

    private function collectRejectedLoanApplicationFee(Loan $loan, User $user): float
    {
        $requestedAmountForFee = (float) $loan->requested_amount;
        $fee = round($this->applicationFees->feeFor($requestedAmountForFee, $loan->organization_id ? (int) $loan->organization_id : null), 2);

        if ($fee <= 0) {
            return 0.0;
        }

        CurrencyDenomination::assertValid($fee, 'Application fee');

        $savings = $loan->savingsAccount ?: $loan->client->accounts
            ->first(fn (Account $account): bool => $account->accountType?->category === 'savings');

        if (! $savings) {
            throw new InvalidArgumentException('Client needs a savings account with enough balance to deduct the application fee before rejection.');
        }

        $savings = Account::query()->with('accountType')->whereKey($savings->id)->lockForUpdate()->firstOrFail();

        if ($savings->status !== 'active') {
            throw new InvalidArgumentException('Client savings account must be active before rejecting this loan.');
        }

        if ($savings->accountType?->category !== 'savings') {
            throw new InvalidArgumentException('Application fee can only be deducted from a savings account.');
        }

        if ((float) $savings->balance < $fee) {
            throw new InvalidArgumentException('Client savings account does not have enough money to deduct the application fee before rejection.');
        }

        $postedAt = $this->effectiveWorkflowDate($loan);
        $savings->decrement('balance', $fee);
        $savings->refresh();
        $type = TransactionType::query()->firstOrCreate(
            ['code' => 'loan_application_fee'],
            ['name' => 'Loan Application Fee', 'direction' => 'debit'],
        );

        $transaction = Transaction::create([
            'organization_id' => $savings->organization_id,
            'account_id' => $savings->id,
            'transaction_type_id' => $type->id,
            'branch_id' => $savings->branch_id,
            'user_id' => $user->id,
            'reference' => 'TX'.now()->format('ymdHis').Str::upper(Str::random(5)),
            'amount' => $fee,
            'balance_after' => $savings->balance,
            'description' => "Loan application fee for rejected loan {$loan->loan_number}",
            'posted_at' => $postedAt,
            'created_at' => $postedAt,
            'updated_at' => now(),
        ]);

        foreach ([
            ['account_code' => '2100', 'entry_type' => 'debit', 'memo' => 'Client savings liability reduced for loan application fee'],
            ['account_code' => $this->applicationFeeIncomeCode($loan), 'entry_type' => 'credit', 'memo' => 'Loan application fee income on rejected loan'],
        ] as $journal) {
            $chartAccount = ChartAccount::query()
                ->where('organization_id', $savings->organization_id)
                ->where('code', $journal['account_code'])
                ->first();

            JournalEntry::create([
                'organization_id' => $savings->organization_id,
                'branch_id' => $savings->branch_id,
                'created_by' => $user->id,
                'posted_by' => $user->id,
                'journal_number' => 'JE'.now()->format('ymdHis').Str::upper(Str::random(4)),
                'journal_date' => $postedAt->toDateString(),
                'description' => "Loan application fee for rejected loan {$loan->loan_number}",
                'status' => 'posted',
                'posted_at' => $postedAt,
                'chart_account_id' => $chartAccount?->id,
                'account_name' => $chartAccount?->name,
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'entry_type' => $journal['entry_type'],
                'amount' => $fee,
                'memo' => $journal['memo'],
                'created_at' => $postedAt,
                'updated_at' => now(),
            ]);
        }

        return $fee;
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
                ['actor_id' => $user->id],
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

            if (! in_array($assignedOfficer->role?->slug, RoleCatalog::LOAN_ASSIGNABLE_SLUGS, true)) {
                throw new InvalidArgumentException('Select an active organization admin, branch head, or Loan Officer.');
            }

            if ($loan->organization_id && $assignedOfficer->organization_id && (int) $loan->organization_id !== (int) $assignedOfficer->organization_id) {
                throw new InvalidArgumentException('The assigned officer must belong to this organization.');
            }

            if ($loan->branch_id && $assignedOfficer->branch_id && (int) $loan->branch_id !== (int) $assignedOfficer->branch_id && ! ApiSecurity::hasGlobalBranchAccess($user)) {
                throw new InvalidArgumentException('The assigned officer must belong to this branch.');
            }

            $oldOfficerId = $loan->officer_id;
            $loan->update(['officer_id' => $assignedOfficer->id]);
            $loan->client?->update(['officer_id' => $assignedOfficer->id]);

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
                ['loan_id' => $freshLoan->id, 'loan_number' => $freshLoan->loan_number, 'actor_id' => $user->id],
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

    /**
     * @param  array<string, mixed>  $data
     */
    public function repairTermsAndSchedule(Loan $loan, User $user, array $data): Loan
    {
        abort_unless($user->canAdministerLoanRepairs(), 403);

        return DB::transaction(function () use ($loan, $user, $data): Loan {
            $loan = Loan::query()
                ->with(['client.branch', 'product', 'loanAccount'])
                ->whereKey($loan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($loan->status, ['approved', 'disbursed', 'active', 'in_arrears'], true)) {
                throw new InvalidArgumentException('Only approved, disbursed, active, or in-arrears loans can have terms repaired.');
            }

            $product = LoanProduct::query()->whereKey($data['loan_product_id'])->firstOrFail();
            $approvedAmount = (float) $data['approved_amount'];
            $termPeriods = (int) $data['term_periods'];
            $repaymentFrequency = (string) $data['repayment_frequency'];
            $scheduleStartDate = CarbonImmutable::parse($data['schedule_start_date'] ?? $loan->disbursed_at?->toDateString() ?? $loan->approved_at?->toDateString() ?? today()->toDateString());

            CurrencyDenomination::assertValid($approvedAmount, 'Approved amount');

            if ($approvedAmount < (float) $product->min_amount || $approvedAmount > (float) $product->max_amount) {
                throw new InvalidArgumentException('Approved amount is outside the selected product limits.');
            }

            if ($termPeriods < (int) $product->min_term || $termPeriods > (int) $product->max_term) {
                throw new InvalidArgumentException('Installments are outside the selected product term limits.');
            }

            if ($loan->repayments()->where('status', 'successful')->exists()) {
                if (! $this->isDateOnlyScheduleRepair($loan, $product, $approvedAmount, $termPeriods, $repaymentFrequency)) {
                    throw new InvalidArgumentException('Reverse successful repayments before changing loan amount, product, frequency, or installment count. You can still repair schedule dates without reversing repayments.');
                }

                return $this->repairScheduleDatesOnly($loan, $user, $scheduleStartDate, (string) $data['reason']);
            }

            $loan->update([
                'loan_product_id' => $product->id,
                'requested_amount' => $approvedAmount,
                'approved_amount' => $approvedAmount,
                'principal_balance' => $approvedAmount,
                'fees_balance' => 0,
                'penalty_balance' => 0,
                'interest_rate' => $product->annual_interest_rate,
                'term_periods' => $termPeriods,
                'repayment_frequency' => $repaymentFrequency,
                'required_savings_amount' => round($approvedAmount * ((float) $product->required_savings_percentage / 100), 2),
            ]);
            $loan->loanGuarantors()
                ->whereNotIn('status', ['rejected', 'released'])
                ->update(['guaranteed_amount' => $approvedAmount]);

            $this->generateSchedule($loan->refresh()->load('product'), $scheduleStartDate);
            $freshLoan = $loan->refresh();
            $loanBalance = (float) $freshLoan->principal_balance + (float) $freshLoan->interest_balance + (float) $freshLoan->fees_balance + (float) $freshLoan->penalty_balance;

            if ($freshLoan->loan_account_id) {
                Account::query()
                    ->whereKey($freshLoan->loan_account_id)
                    ->lockForUpdate()
                    ->update([
                        'balance' => $loanBalance,
                        'status' => 'active',
                    ]);
            }

            $this->auditLogger->record('loan.terms_repaired', $freshLoan, [
                'reason' => $data['reason'],
                'loan_product_id' => $product->id,
                'approved_amount' => $approvedAmount,
                'term_periods' => $termPeriods,
                'repayment_frequency' => $repaymentFrequency,
                'schedule_start_date' => $scheduleStartDate->toDateString(),
                'repaired_by' => $user->id,
            ], actor: $user);

            return $freshLoan;
        });
    }

    private function isDateOnlyScheduleRepair(Loan $loan, LoanProduct $product, float $approvedAmount, int $termPeriods, string $repaymentFrequency): bool
    {
        $currentAmount = round((float) ($loan->approved_amount ?: $loan->requested_amount), 2);

        return (int) $loan->loan_product_id === (int) $product->id
            && abs($currentAmount - round($approvedAmount, 2)) < 0.01
            && (int) $loan->term_periods === $termPeriods
            && (string) $loan->repayment_frequency === $repaymentFrequency;
    }

    private function repairScheduleDatesOnly(Loan $loan, User $user, CarbonInterface $scheduleStartDate, string $reason): Loan
    {
        $loan->loadMissing('schedules', 'loanAccount');
        $anchorDate = $this->scheduleAnchorDate($loan, $scheduleStartDate);
        $totals = $this->realignLoanScheduleDates($loan, $anchorDate, today());

        $freshLoan = $loan->refresh();
        $loanBalance = (float) $freshLoan->principal_balance + (float) $freshLoan->interest_balance + (float) $freshLoan->fees_balance + (float) $freshLoan->penalty_balance;

        if ($freshLoan->loan_account_id) {
            Account::query()
                ->whereKey($freshLoan->loan_account_id)
                ->lockForUpdate()
                ->update([
                    'balance' => $loanBalance,
                    'status' => 'active',
                ]);
        }

        $this->auditLogger->record('loan.schedule_dates_repaired', $freshLoan, [
            'reason' => $reason,
            'schedule_start_date' => $scheduleStartDate->toDateString(),
            'anchor_date' => $anchorDate->toDateString(),
            'updated_schedules' => $totals['schedules'],
            'penalties_cleared' => $totals['penalties_cleared'],
            'repaired_by' => $user->id,
            'preserved_repayments' => true,
        ], actor: $user);

        return $freshLoan;
    }

    public function generateSavingsAccount(Loan $loan, User $user): Account
    {
        abort_unless($user->canPerform('manage-accounts') || $user->canPerform('manage-loans') || $user->canManageCreatedLoanApplication($loan), 403);

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
        abort_unless($user->canPerform('manage-accounts') || $user->canPerform('manage-loans') || $user->canManageCreatedLoanApplication($loan), 403);

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
        abort_unless($user->canPerform('manage-loans') || $user->canManageCreatedLoanApplication($loan), 403);

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
                ['actor_id' => $user->id],
            );

            return $freshLoan;
        });
    }

    public function submitForApproval(Loan $loan, User $user): Loan
    {
        abort_unless($user->canPerform('manage-loans') || $user->canManageCreatedLoanApplication($loan), 403);

        return DB::transaction(function () use ($loan, $user): Loan {
            $loan = Loan::query()->with(['client', 'product', 'loanGuarantors'])->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($loan->status !== 'under_review') {
                throw new InvalidArgumentException('Only appraised loans can be submitted for approval.');
            }

            $this->ensureLoanClientBioComplete($loan);
            $this->ensureMinimumGuarantorsAttached($loan);

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
                ['loan_id' => $freshLoan->id, 'loan_number' => $freshLoan->loan_number, 'actor_id' => $user->id],
            );

            return $freshLoan;
        });
    }

    public function fastTrackDisbursement(Loan $loan, User $user, float $approvedAmount, string $paymentMethod = 'transfer_to_savings', ?string $comments = null): Loan
    {
        abort_unless(
            $user->canPerform('manage-loans')
            && $user->canPerform('approve-loans')
            && $user->canPerform('disburse-loans'),
            403
        );

        CurrencyDenomination::assertValid($approvedAmount, 'Approved amount');
        $loan = $loan->fresh();

        if ($loan->status === 'draft') {
            $this->generateSavingsAccount($loan, $user);
            $this->generateLoanAccount($loan->fresh(), $user);
            $loan = $this->submitForAppraisal($loan->fresh(), $user);
        }

        if ($loan->status === 'under_review') {
            $loan = $this->submitForApproval($loan, $user);
        }

        if (in_array($loan->status, ['submitted', 'recommended', 'pending_guarantor_approval', 'pending_collateral_verification'], true)) {
            $loan = $this->approve($loan, $user, $approvedAmount, $comments ?: 'Admin fast-track approval and disbursement.');
        }

        if ($loan->status === 'approved') {
            return $this->disburseToSavings($loan, $user, $paymentMethod);
        }

        throw new InvalidArgumentException('Only draft, appraisal, approval, or approved loans can be fast-tracked.');
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
            $fees = round($this->applicationFees->feeFor($requestedAmountForFee, $loan->organization_id ? (int) $loan->organization_id : null), 2);
            CurrencyDenomination::assertValid((float) $loan->approved_amount, 'Approved amount');

            if ($fees > 0) {
                CurrencyDenomination::assertValid($fees, 'Application fee');
            }

            if ($fees > (float) $loan->approved_amount) {
                throw new InvalidArgumentException('Application fee cannot exceed the approved loan amount.');
            }

            $approvedAmount = round((float) $loan->approved_amount, 2);
            $feeCoveredFromSavings = $fees > 0 && round((float) $savings->balance, 2) >= $fees;
            $feeDeductedFromProceeds = $feeCoveredFromSavings ? 0.0 : $fees;
            $netAmount = max(0, round($approvedAmount - $feeDeductedFromProceeds, 2));
            $type = TransactionType::query()->where('code', 'loan_disbursement')->firstOrFail();
            $applicationFeeIncomeCode = $this->applicationFeeIncomeCode($loan);
            $journals = [
                ['account_code' => '1200', 'entry_type' => 'debit', 'amount' => $approvedAmount, 'memo' => 'Loan principal raised'],
                ['account_code' => '2100', 'entry_type' => 'credit', 'amount' => $netAmount, 'memo' => 'Loan proceeds credited to savings'],
            ];

            if ($feeCoveredFromSavings) {
                $this->deductDisbursementApplicationFeeFromSavings($loan, $savings, $user, $fees);
            } elseif ($fees > 0) {
                $journals[] = ['account_code' => $applicationFeeIncomeCode, 'entry_type' => 'credit', 'amount' => $fees, 'memo' => 'Loan application fee deducted from proceeds'];
            }

            $disbursementDate = $this->effectiveWorkflowDate($loan);
            $transaction = $this->transactions->postNonCashCredit($savings, $type, $netAmount, $user, 'Loan disbursement to savings account', $journals, $disbursementDate);

            LoanDisbursement::create([
                'organization_id' => $loan->organization_id,
                'branch_id' => $loan->branch_id,
                'loan_id' => $loan->id,
                'disbursed_by' => $user->id,
                'disbursed_amount' => $approvedAmount,
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
                'application_fee_source' => $feeCoveredFromSavings ? 'savings' : 'loan_proceeds',
                'net_amount_credited' => $netAmount,
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
                ['actor_id' => $user->id],
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
                ['loan_id' => $freshLoan->id, 'loan_number' => $freshLoan->loan_number, 'actor_id' => $user->id],
            );

            return $freshLoan;
        });
    }

    private function deductDisbursementApplicationFeeFromSavings(Loan $loan, Account $savings, User $user, float $fee): Transaction
    {
        $postedAt = $this->effectiveWorkflowDate($loan);

        $savings->decrement('balance', $fee);
        $savings->refresh();

        $type = TransactionType::query()->firstOrCreate(
            ['code' => 'loan_application_fee'],
            ['name' => 'Loan Application Fee', 'direction' => 'debit'],
        );

        $transaction = Transaction::create([
            'organization_id' => $savings->organization_id,
            'account_id' => $savings->id,
            'transaction_type_id' => $type->id,
            'branch_id' => $savings->branch_id,
            'user_id' => $user->id,
            'reference' => 'TX'.now()->format('ymdHis').Str::upper(Str::random(5)),
            'amount' => $fee,
            'balance_after' => $savings->balance,
            'description' => "Loan application fee paid from savings for loan {$loan->loan_number}",
            'posted_at' => $postedAt,
            'created_at' => $postedAt,
            'updated_at' => now(),
        ]);

        foreach ([
            ['account_code' => '2100', 'entry_type' => 'debit', 'memo' => 'Client savings liability reduced for loan application fee'],
            ['account_code' => $this->applicationFeeIncomeCode($loan), 'entry_type' => 'credit', 'memo' => 'Loan application fee income paid from savings'],
        ] as $journal) {
            $chartAccount = ChartAccount::query()
                ->where('organization_id', $savings->organization_id)
                ->where('code', $journal['account_code'])
                ->first();

            JournalEntry::create([
                'organization_id' => $savings->organization_id,
                'branch_id' => $savings->branch_id,
                'created_by' => $user->id,
                'posted_by' => $user->id,
                'journal_number' => 'JE'.now()->format('ymdHis').Str::upper(Str::random(4)),
                'journal_date' => $postedAt->toDateString(),
                'description' => "Loan application fee paid from savings for loan {$loan->loan_number}",
                'status' => 'posted',
                'posted_at' => $postedAt,
                'chart_account_id' => $chartAccount?->id,
                'account_name' => $chartAccount?->name,
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'entry_type' => $journal['entry_type'],
                'amount' => $fee,
                'memo' => $journal['memo'],
                'created_at' => $postedAt,
                'updated_at' => now(),
            ]);
        }

        return $transaction;
    }

    public function generateSchedule(Loan $loan, CarbonInterface $startDate): void
    {
        $loan->loadMissing('product');
        $loan->schedules()->delete();

        $startDate = $this->scheduleAnchorDate($loan, $startDate);
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
            $dueDate = $this->calendar->dueDate($startDate, $i, $loan->repayment_frequency, $loan->organization_id ? (int) $loan->organization_id : null);
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

    public function scheduleAnchorDate(Loan $loan, CarbonInterface $fallbackDate): CarbonInterface
    {
        if ($loan->repayment_frequency === 'monthly' && $loan->application_date) {
            return CarbonImmutable::parse($loan->application_date->toDateString());
        }

        return CarbonImmutable::parse($fallbackDate->toDateString());
    }

    /**
     * @return array{loans:int, schedules:int, penalties_cleared:float}
     */
    public function repairMonthlyScheduleDates(?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ? CarbonImmutable::parse($asOf->toDateString()) : CarbonImmutable::today();
        $totals = ['loans' => 0, 'schedules' => 0, 'penalties_cleared' => 0.0];

        Loan::withoutGlobalScopes()
            ->where('repayment_frequency', 'monthly')
            ->whereNotNull('application_date')
            ->whereIn('status', ['approved', 'disbursed', 'active', 'in_arrears', 'restructured'])
            ->chunkById(100, function ($loans) use (&$totals, $asOf): void {
                foreach ($loans as $loan) {
                    DB::transaction(function () use ($loan, &$totals, $asOf): void {
                        $loan = Loan::withoutGlobalScopes()
                            ->with(['schedules' => fn ($query) => $query->orderBy('installment_number')])
                            ->whereKey($loan->id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        $changed = false;
                        $anchorDate = $this->scheduleAnchorDate($loan, $loan->disbursed_at ?: today());
                        $repairTotals = $this->realignLoanScheduleDates($loan, $anchorDate, $asOf);
                        $changed = $repairTotals['schedules'] > 0;

                        if (! $changed) {
                            return;
                        }

                        $totals['schedules'] += $repairTotals['schedules'];
                        $totals['penalties_cleared'] += $repairTotals['penalties_cleared'];
                        $totals['loans']++;
                    });
                }
            });

        return $totals;
    }

    /**
     * @return array{schedules:int, penalties_cleared:float}
     */
    private function realignLoanScheduleDates(Loan $loan, CarbonInterface $anchorDate, CarbonInterface $asOf): array
    {
        $totals = ['schedules' => 0, 'penalties_cleared' => 0.0];

        foreach ($loan->schedules()->orderBy('installment_number')->lockForUpdate()->get() as $schedule) {
            $expectedDueDate = $this->calendar->dueDate(
                $anchorDate,
                (int) $schedule->installment_number,
                $loan->repayment_frequency,
                $loan->organization_id ? (int) $loan->organization_id : null,
            );
            $isOverdue = $expectedDueDate->lt($asOf->copy()->subDay());
            $status = match (true) {
                (float) $schedule->outstanding_amount <= 0 => 'paid',
                $isOverdue => 'overdue',
                (float) $schedule->total_paid > 0 => 'partially_paid',
                default => 'pending',
            };
            $updates = [
                'due_date' => $expectedDueDate->toDateString(),
                'status' => $status,
                'days_overdue' => $isOverdue ? $this->calendar->businessDaysBetween($expectedDueDate, $asOf, $loan->organization_id ? (int) $loan->organization_id : null) : 0,
            ];

            $penaltyDue = (float) $schedule->penalty_due;
            $canClearPenalty = ! $isOverdue && $penaltyDue > 0 && (float) $schedule->penalty_paid <= 0;

            if ($canClearPenalty) {
                LoanPenalty::query()
                    ->where('loan_schedule_id', $schedule->id)
                    ->where('status', 'pending')
                    ->delete();

                $updates['penalty_due'] = 0;
                $updates['total_due'] = max(0, (float) $schedule->total_due - $penaltyDue);
                $updates['outstanding_amount'] = max(0, (float) $schedule->outstanding_amount - $penaltyDue);
                $updates['balance_due'] = max(0, (float) $schedule->balance_due - $penaltyDue);
                $totals['penalties_cleared'] += $penaltyDue;
            }

            $dirty = $schedule->due_date?->toDateString() !== $updates['due_date']
                || (string) $schedule->status !== (string) $updates['status']
                || (int) $schedule->days_overdue !== (int) $updates['days_overdue']
                || (array_key_exists('penalty_due', $updates) && abs((float) $schedule->penalty_due - (float) $updates['penalty_due']) > 0.01);

            if (! $dirty) {
                continue;
            }

            $schedule->update($updates);
            $totals['schedules']++;
        }

        if ($totals['penalties_cleared'] > 0) {
            $loan->update(['penalty_balance' => max(0, (float) $loan->penalty_balance - $totals['penalties_cleared'])]);
        }

        if (in_array($loan->status, ['active', 'in_arrears'], true)) {
            $hasArrears = $loan->schedules()
                ->eligibleForArrears($asOf)
                ->exists();

            $loan->update(['status' => $hasArrears ? 'in_arrears' : 'active']);
        }

        return $totals;
    }

    private function applicationFeeIncomeCode(Loan $loan): string
    {
        $branch = $loan->branch;
        if ($branch) {
            return $this->generalLedger->applicationFeesIncome($branch)->code;
        }

        return '4200';
    }

    public function repay(Loan $loan, float $amount, User $user, ?int $transactionId = null, string $paymentMethod = 'cash', ?string $reference = null, ?CarbonInterface $paidAt = null, string $counterAccountCode = '1000', string $journalMemo = 'Loan repayment allocation', ?float $counterEntryAmount = null): LoanRepayment
    {
        CurrencyDenomination::assertValid($amount);

        return DB::transaction(function () use ($loan, $amount, $user, $transactionId, $paymentMethod, $reference, $paidAt, $counterAccountCode, $journalMemo, $counterEntryAmount) {
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

            $journalNumber = 'LR'.now()->format('ymdHis').Str::upper(Str::random(4));
            $journalSequence = 0;
            $counterAmount = $counterEntryAmount ?? $penaltyPaid + $feesPaid + $interestPaid + $principalPaid;

            foreach ([
                [$counterAccountCode, 'debit', $counterAmount],
                ['4100', 'credit', $interestPaid],
                ['4200', 'credit', $feesPaid + $penaltyPaid],
                ['1200', 'credit', $principalPaid],
            ] as [$accountCode, $entryType, $entryAmount]) {
                if ($entryAmount > 0) {
                    $chartAccount = ChartAccount::query()->where('code', $accountCode)->first();

                    JournalEntry::create([
                        'organization_id' => $loan->organization_id,
                        'branch_id' => $loan->branch_id,
                        'created_by' => $user->id,
                        'posted_by' => $user->id,
                        'journal_number' => $journalNumber.'-'.str_pad((string) ++$journalSequence, 2, '0', STR_PAD_LEFT),
                        'journal_date' => $paymentDate->toDateString(),
                        'description' => $journalMemo,
                        'status' => 'posted',
                        'posted_at' => $paymentDate,
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
                ['actor_id' => $user->id, 'repayment_id' => $repayment->id, 'amount' => $amount],
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

            if (! in_array($loan->status, ['disbursed', 'active', 'in_arrears', 'closed', 'fully_paid'], true)) {
                throw new InvalidArgumentException('Only disbursed, active, in-arrears, closed, or fully paid loans can be reopened.');
            }

            if (! $loan->loan_account_id) {
                throw new InvalidArgumentException('This loan does not have a loan account to reopen.');
            }

            $clearPaidInstallments = in_array($loan->status, ['closed', 'fully_paid'], true);
            $principalBalance = $clearPaidInstallments ? (float) $loan->schedules->sum('principal_due') : (float) $loan->principal_balance;
            $interestBalance = $clearPaidInstallments ? (float) $loan->schedules->sum('interest_due') : (float) $loan->interest_balance;
            $feesBalance = $clearPaidInstallments ? (float) $loan->schedules->sum('fees_due') : (float) $loan->fees_balance;
            $penaltyBalance = $clearPaidInstallments ? (float) $loan->schedules->sum('penalty_due') : (float) $loan->penalty_balance;
            $totalBalance = $principalBalance + $interestBalance + $feesBalance + $penaltyBalance;

            if ($clearPaidInstallments) {
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
            }

            $loan->update([
                'principal_balance' => $principalBalance,
                'interest_balance' => $interestBalance,
                'fees_balance' => $feesBalance,
                'penalty_balance' => $penaltyBalance,
                'status' => $clearPaidInstallments
                    ? ($loan->schedules->contains(fn (LoanSchedule $schedule): bool => $schedule->due_date?->lt(today()) === true) ? 'in_arrears' : 'active')
                    : ($loan->status === 'disbursed' ? 'active' : $loan->status),
            ]);

            Account::query()
                ->whereKey($loan->loan_account_id)
                ->lockForUpdate()
                ->update([
                    'balance' => $totalBalance,
                    'status' => 'active',
                ]);

            $this->auditLogger->record('loan.reopened_installments_cleared', $loan->fresh(), [
                'reason' => $reason,
                'reopened_by' => $user->id,
                'restored_balance' => $totalBalance,
                'cleared_paid_installments' => $clearPaidInstallments,
            ], actor: $user);

            return $loan->refresh();
        });
    }

    public function closeFullyPaidLoan(Loan $loan, User $user, string $reason): Loan
    {
        return DB::transaction(function () use ($loan, $user, $reason): Loan {
            $loan = Loan::query()
                ->with(['loanAccount', 'schedules', 'loanGuarantors', 'collaterals'])
                ->whereKey($loan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($loan->status, ['closed', 'written_off'], true)) {
                throw new InvalidArgumentException('This loan is already closed.');
            }

            $outstandingBalance = $this->outstandingBalance($loan);

            if ($outstandingBalance > 0) {
                throw new InvalidArgumentException('This loan still has an outstanding balance. Use admin closure with balance coverage instead.');
            }

            $old = $loan->only(['status', 'principal_balance', 'interest_balance', 'fees_balance', 'penalty_balance']);

            $loan->schedules()
                ->where('status', '!=', 'paid')
                ->update([
                    'outstanding_amount' => 0,
                    'balance_due' => 0,
                    'status' => 'paid',
                    'updated_at' => now(),
                ]);

            $loan->update([
                'principal_balance' => 0,
                'interest_balance' => 0,
                'fees_balance' => 0,
                'penalty_balance' => 0,
                'status' => 'closed',
            ]);

            if ($loan->loan_account_id) {
                Account::query()
                    ->whereKey($loan->loan_account_id)
                    ->lockForUpdate()
                    ->update([
                        'balance' => 0,
                        'status' => 'closed',
                    ]);
            }

            $loan->loanGuarantors()->where('status', '!=', 'released')->update(['status' => 'released']);
            $loan->collaterals()->where('status', '!=', 'released')->update(['status' => 'released']);

            $this->auditLogger->record('loan.closed_fully_paid_admin', $loan->fresh(), [
                'reason' => $reason,
                'closed_by' => $user->id,
            ], $old, actor: $user);

            return $loan->refresh();
        });
    }

    public function closeWithBalanceCoverage(Loan $loan, User $user, string $coverageMethod, string $reason, ?string $reference = null): Loan
    {
        return DB::transaction(function () use ($loan, $user, $coverageMethod, $reason, $reference): Loan {
            $loan = Loan::query()
                ->with(['loanAccount', 'schedules', 'loanGuarantors', 'collaterals'])
                ->whereKey($loan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($loan->status, ['closed', 'fully_paid', 'written_off', 'cancelled', 'rejected'], true)) {
                throw new InvalidArgumentException('This loan cannot be closed with balance coverage.');
            }

            if ($coverageMethod === 'write_off' && ! $user->canWriteOffLoans()) {
                throw new InvalidArgumentException('You do not have permission to write off loans.');
            }

            $principalBalance = (float) $loan->principal_balance;
            $interestBalance = (float) $loan->interest_balance;
            $feesBalance = (float) $loan->fees_balance;
            $penaltyBalance = (float) $loan->penalty_balance;
            $outstandingBalance = $principalBalance + $interestBalance + $feesBalance + $penaltyBalance;

            if ($outstandingBalance <= 0) {
                throw new InvalidArgumentException('This loan has no outstanding balance. Use the fully paid closure action instead.');
            }

            $old = $loan->only(['status', 'principal_balance', 'interest_balance', 'fees_balance', 'penalty_balance']);
            $coverageLabel = str($coverageMethod)->replace('_', ' ')->headline()->toString();
            $coverageReason = trim($reason.($reference ? "\nReference: {$reference}" : '')."\nCoverage method: {$coverageLabel}");

            LoanWriteOff::create([
                'loan_id' => $loan->id,
                'approved_by' => $user->id,
                'principal_amount' => $principalBalance,
                'interest_amount' => $interestBalance,
                'fees_amount' => $feesBalance,
                'penalty_amount' => $penaltyBalance,
                'reason' => $coverageReason,
                'written_off_at' => today(),
                'status' => 'approved',
            ]);

            $loan->schedules()
                ->where('outstanding_amount', '>', 0)
                ->update([
                    'outstanding_amount' => 0,
                    'balance_due' => 0,
                    'status' => 'paid',
                    'updated_at' => now(),
                ]);

            $loan->update([
                'principal_balance' => 0,
                'interest_balance' => 0,
                'fees_balance' => 0,
                'penalty_balance' => 0,
                'status' => $coverageMethod === 'write_off' ? 'written_off' : 'closed',
            ]);

            if ($loan->loan_account_id) {
                Account::query()
                    ->whereKey($loan->loan_account_id)
                    ->lockForUpdate()
                    ->update([
                        'balance' => 0,
                        'status' => 'closed',
                    ]);
            }

            $loan->loanGuarantors()->where('status', '!=', 'released')->update(['status' => 'released']);
            $loan->collaterals()->where('status', '!=', 'released')->update(['status' => 'released']);

            $this->auditLogger->record('loan.closed_with_balance_coverage', $loan->fresh(), [
                'reason' => $reason,
                'reference' => $reference,
                'coverage_method' => $coverageMethod,
                'covered_amount' => $outstandingBalance,
                'closed_by' => $user->id,
            ], $old, actor: $user);

            return $loan->refresh();
        });
    }

    private function outstandingBalance(Loan $loan): float
    {
        return (float) $loan->principal_balance
            + (float) $loan->interest_balance
            + (float) $loan->fees_balance
            + (float) $loan->penalty_balance;
    }

    private function ensureLoanClientBioComplete(Loan $loan): void
    {
        $client = $loan->client;

        if (! $client || $client->client_type !== 'individual') {
            return;
        }

        if (blank($client->national_id) || blank($client->date_of_birth) || blank($client->gender)) {
            throw new InvalidArgumentException('The loan client must have National ID / NIN, date of birth, and gender before this loan can continue.');
        }
    }

    private function ensureMinimumGuarantorsAttached(Loan $loan): void
    {
        $hasGuarantor = $loan->loanGuarantors()
            ->whereNotIn('status', ['rejected', 'released'])
            ->exists();

        if (! $hasGuarantor) {
            throw new InvalidArgumentException('Attach at least one guarantor before sending this loan for approval.');
        }
    }

    public function applyOverduePenalties(?Loan $loan = null, ?CarbonInterface $asOf = null, ?int $branchId = null, ?int $officerId = null): int
    {
        return $this->applyOverduePenaltiesWithSummary($loan, $asOf, $branchId, $officerId)['checked'];
    }

    /**
     * @return array{checked:int, applied_schedules:int, amount:float}
     */
    public function applyOverduePenaltiesWithSummary(?Loan $loan = null, ?CarbonInterface $asOf = null, ?int $branchId = null, ?int $officerId = null): array
    {
        $asOf = $asOf ?: now();
        $summary = ['checked' => 0, 'applied_schedules' => 0, 'amount' => 0.0];

        $query = LoanSchedule::query()
            ->with('loan.product')
            ->excludingCancelledLoans()
            ->eligibleForArrears($asOf);

        if ($loan) {
            $query->where('loan_id', $loan->id);
        }

        if ($branchId) {
            $query->whereHas('loan', fn ($query) => $query->where('branch_id', $branchId));
        }

        if ($officerId) {
            $query->whereHas('loan', fn ($query) => $query->where('officer_id', $officerId));
        }

        foreach ($query->cursor() as $schedule) {
            DB::transaction(function () use ($schedule, $asOf, &$summary): void {
                $schedule = LoanSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
                $loan = Loan::query()->with(['loanAccount', 'product'])->whereKey($schedule->loan_id)->lockForUpdate()->firstOrFail();

                if (! in_array($loan->status, Loan::ARREARS_STATUSES, true)) {
                    return;
                }

                $daysOverdue = $this->calendar->businessDaysBetween($schedule->due_date, $asOf, $loan->organization_id ? (int) $loan->organization_id : null);
                $chargeableDays = max(0, $daysOverdue - 1);
                $penaltyRate = (float) SystemSetting::value('loan_arrears_penalty_rate', 2, $loan->organization_id ? (int) $loan->organization_id : null);
                $repaymentFrequency = $loan->repayment_frequency ?: $loan->product?->repayment_frequency;
                $alreadyChargedDays = (int) LoanPenalty::query()
                    ->where('loan_schedule_id', $schedule->id)
                    ->where('status', 'pending')
                    ->max('days_overdue');
                $daysToCharge = max(0, $chargeableDays - $alreadyChargedDays);
                $alreadyAppliedToday = LoanPenalty::query()
                    ->where('loan_schedule_id', $schedule->id)
                    ->where('status', 'pending')
                    ->whereDate('calculated_for_date', $asOf->toDateString())
                    ->exists();

                $penaltyAmount = ($daysToCharge <= 0 || $alreadyAppliedToday) ? 0 : $this->penalties->calculate(
                    $schedule,
                    $penaltyRate,
                    'percentage',
                    $daysToCharge,
                    $repaymentFrequency,
                );

                if ($penaltyAmount > 0) {
                    LoanPenalty::create([
                        'loan_id' => $loan->id,
                        'loan_schedule_id' => $schedule->id,
                        'amount' => $penaltyAmount,
                        'days_overdue' => $chargeableDays,
                        'calculated_for_date' => $asOf->toDateString(),
                        'status' => 'pending',
                    ]);

                    $schedule->increment('penalty_due', $penaltyAmount);
                    $schedule->increment('total_due', $penaltyAmount);
                    $schedule->increment('outstanding_amount', $penaltyAmount);
                    $schedule->increment('balance_due', $penaltyAmount);
                    $loan->increment('penalty_balance', $penaltyAmount);

                    if ($loan->loan_account_id) {
                        $loan->loanAccount()->update([
                            'balance' => max(0, (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance + $penaltyAmount),
                        ]);
                    }

                    $summary['applied_schedules']++;
                    $summary['amount'] += $penaltyAmount;
                }

                $schedule->update(['status' => 'overdue', 'days_overdue' => $chargeableDays]);
                $loan->update(['status' => 'in_arrears']);
                $summary['checked']++;
            });
        }

        return $summary;
    }

    /**
     * @return array{schedules:int, amount:float}
     */
    public function clearPendingPenalties(?Loan $loan = null, ?LoanSchedule $schedule = null, ?int $branchId = null, ?User $actor = null): array
    {
        $totals = ['schedules' => 0, 'amount' => 0.0];

        $query = LoanSchedule::query()
            ->with('loan.loanAccount')
            ->whereColumn('penalty_due', '>', 'penalty_paid');

        if ($loan) {
            $query->where('loan_id', $loan->id);
        }

        if ($schedule) {
            $query->whereKey($schedule->id);
        }

        if ($branchId) {
            $query->whereHas('loan', fn ($query) => $query->where('branch_id', $branchId));
        }

        if ($actor && ! $actor->hasGlobalOrganizationAccess() && $actor->organization_id !== null) {
            $query->whereHas('loan', fn ($query) => $query->where('organization_id', $actor->organization_id));
        }

        foreach ($query->cursor() as $candidate) {
            DB::transaction(function () use ($candidate, $actor, &$totals): void {
                $schedule = LoanSchedule::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                $loan = Loan::query()->with('loanAccount')->whereKey($schedule->loan_id)->lockForUpdate()->firstOrFail();
                $penaltyDue = (float) $schedule->penalty_due;
                $penaltyPaid = (float) $schedule->penalty_paid;
                $penaltyToClear = max(0, $penaltyDue - $penaltyPaid);

                if ($penaltyToClear <= 0) {
                    return;
                }

                $schedule->update([
                    'penalty_due' => $penaltyPaid,
                    'total_due' => max((float) $schedule->total_paid, (float) $schedule->total_due - $penaltyToClear),
                    'outstanding_amount' => max(0, (float) $schedule->outstanding_amount - $penaltyToClear),
                    'balance_due' => max(0, (float) $schedule->balance_due - $penaltyToClear),
                ]);

                $loan->update(['penalty_balance' => max(0, (float) $loan->penalty_balance - $penaltyToClear)]);

                if ($loan->loan_account_id) {
                    $loan->loanAccount()->update([
                        'balance' => max(0, (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + max(0, (float) $loan->penalty_balance - $penaltyToClear)),
                    ]);
                }

                LoanPenalty::query()
                    ->where('loan_schedule_id', $schedule->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'waived']);

                $totals['schedules']++;
                $totals['amount'] += $penaltyToClear;

                $this->auditLogger->record('loan.penalties_cleared', $loan->fresh(), [
                    'loan_schedule_id' => $schedule->id,
                    'amount' => $penaltyToClear,
                    'cleared_by' => $actor?->id,
                ], actor: $actor);
            });
        }

        return $totals;
    }

    /**
     * @return array{allocations:list<array{loan_schedule_id:int, penalty_paid:float, fees_paid:float, interest_paid:float, principal_paid:float, total_paid:float}>, totals:array{penalty:float, fees:float, interest:float, principal:float}, applied:float}
     */
    public function previewSchedulePayments(Loan $loan, float $amount): array
    {
        return $this->allocateSchedulePayments($loan, $amount, false);
    }

    private function allocateSchedulePayments(Loan $loan, float $amount, bool $persist = true): array
    {
        $remaining = $amount;
        $allocations = [];
        $totals = [
            'penalty' => 0.0,
            'fees' => 0.0,
            'interest' => 0.0,
            'principal' => 0.0,
        ];

        $schedules = $loan->schedules()->where('status', '!=', 'paid')->orderBy('due_date')->orderBy('id');
        if ($persist) {
            $schedules->lockForUpdate();
        }

        foreach ($schedules->get() as $schedule) {
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

            if ($persist) {
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
