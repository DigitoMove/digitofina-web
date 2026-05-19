<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchCashReconciliation;
use App\Models\DrawerSession;
use App\Models\FinancialDay;
use App\Models\InvestmentAccount;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\MakerCheckerRequest;
use App\Models\RoleFinancialLimit;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\SuspiciousTransactionAlert;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FinancialControlService
{
    public const SENSITIVE_ACTIONS = [
        'savings_withdrawal',
        'savings_transaction_reversal',
        'transaction_reversal',
        'loan_approval',
        'loan_disbursement',
        'journal_posting',
        'investment_approval',
        'loan_write_off',
        'settings_update',
        'loan_product_update',
        'savings_product_update',
        'interest_rate_change',
        'penalty_waiver',
    ];

    public function __construct(
        private AuditLogger $auditLogger,
        private LoanService $loans,
        private JournalEntryService $journals,
        private TransactionReversalService $transactionReversals,
    ) {}

    public function requiresMakerChecker(User $user, string $action, ?float $amount = null): bool
    {
        if ($user->canPerform('override-maker-checker')) {
            return false;
        }

        if ($action === 'savings_withdrawal') {
            return $amount !== null && $amount >= $this->largeWithdrawalThreshold($user);
        }

        return in_array($action, self::SENSITIVE_ACTIONS, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $oldValues
     */
    public function requestAction(User $maker, string $action, ?Model $subject, array $payload, ?float $amount, string $requiredPermission, ?string $reason = null, ?array $oldValues = null): MakerCheckerRequest
    {
        if (! in_array($action, self::SENSITIVE_ACTIONS, true)) {
            throw new InvalidArgumentException('Unsupported maker-checker action.');
        }

        $request = MakerCheckerRequest::create([
            'organization_id' => $subject?->getAttribute('organization_id') ?: $maker->organization_id,
            'branch_id' => $subject?->getAttribute('branch_id') ?: $maker->branch_id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'requested_by' => $maker->id,
            'action' => $action,
            'required_permission' => $requiredPermission,
            'amount' => $amount,
            'status' => 'pending',
            'old_values' => $oldValues,
            'payload' => $payload,
            'reason' => $reason,
        ]);

        $this->auditLogger->record('financial_control.maker_checker_requested', $request, [
            'action' => $action,
            'subject_type' => $request->subject_type,
            'subject_id' => $request->subject_id,
            'amount' => $amount,
        ]);

        return $request;
    }

    public function ensureFinancialDayOpen(User $user, int $branchId, CarbonInterface|string|null $transactionDate = null): CarbonImmutable
    {
        $businessDate = $transactionDate instanceof CarbonInterface
            ? CarbonImmutable::parse($transactionDate->toDateString())
            : CarbonImmutable::parse($transactionDate ?: today()->toDateString());

        $this->ensureBackdateAllowed($user, $businessDate);

        $financialDay = FinancialDay::query()
            ->where('branch_id', $branchId)
            ->whereDate('business_date', $businessDate->toDateString())
            ->first();

        if ($financialDay && in_array($financialDay->status, ['closed', 'locked'], true)) {
            throw new InvalidArgumentException('The financial day is closed and locked for this branch.');
        }

        return $businessDate;
    }

    public function enforceTransactionLimit(User $user, float $amount, CarbonInterface $businessDate): void
    {
        $limit = $this->limitFor($user);

        if (! $limit) {
            return;
        }

        if ($limit->single_transaction_limit !== null && $amount > (float) $limit->single_transaction_limit) {
            throw new InvalidArgumentException('Transaction exceeds the maximum amount allowed for this role.');
        }

        if ($limit->daily_transaction_limit !== null) {
            $postedToday = SavingsTransaction::query()
                ->where('teller_id', $user->id)
                ->whereDate('transaction_date', $businessDate->toDateString())
                ->where('status', 'successful')
                ->sum('amount');

            if (((float) $postedToday + $amount) > (float) $limit->daily_transaction_limit) {
                throw new InvalidArgumentException('Transaction exceeds the daily amount allowed for this role.');
            }
        }
    }

    public function enforceLoanApprovalLimit(User $user, float $amount): void
    {
        $limit = $this->limitFor($user);

        if ($limit?->loan_approval_limit !== null && $amount > (float) $limit->loan_approval_limit) {
            throw new InvalidArgumentException('Loan amount exceeds this role approval limit.');
        }
    }

    public function approveMakerChecker(MakerCheckerRequest $request, User $checker, ?string $comments = null): MakerCheckerRequest
    {
        return DB::transaction(function () use ($request, $checker, $comments): MakerCheckerRequest {
            $request = MakerCheckerRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($request->status !== 'pending') {
                throw new InvalidArgumentException('Only pending maker-checker requests can be approved.');
            }

            if ((int) $request->requested_by === (int) $checker->id && ! $checker->canPerform('override-maker-checker')) {
                throw new InvalidArgumentException('The maker cannot check their own request.');
            }

            if ($request->required_permission && ! $checker->canPerform($request->required_permission) && ! $checker->canPerform('override-maker-checker')) {
                throw new InvalidArgumentException('You do not have permission to approve this request.');
            }

            $this->executeApprovedAction($request, $checker);

            $request->update([
                'status' => 'approved',
                'checked_by' => $checker->id,
                'checker_comments' => $comments,
                'checked_at' => now(),
            ]);

            $this->auditLogger->record('financial_control.maker_checker_approved', $request, [
                'action' => $request->action,
                'comments' => $comments,
            ]);

            return $request->refresh();
        });
    }

    public function rejectMakerChecker(MakerCheckerRequest $request, User $checker, string $comments): MakerCheckerRequest
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException('Only pending maker-checker requests can be rejected.');
        }

        if ($request->required_permission && ! $checker->canPerform($request->required_permission) && ! $checker->canPerform('override-maker-checker')) {
            throw new InvalidArgumentException('You do not have permission to reject this request.');
        }

        $request->update([
            'status' => 'rejected',
            'checked_by' => $checker->id,
            'checker_comments' => $comments,
            'checked_at' => now(),
        ]);

        $this->auditLogger->record('financial_control.maker_checker_rejected', $request, [
            'action' => $request->action,
            'comments' => $comments,
        ]);

        return $request->refresh();
    }

    public function recordSuspiciousTransaction(User $user, Model $record, float $amount, string $type, ?int $branchId = null): ?SuspiciousTransactionAlert
    {
        $threshold = $this->suspiciousThreshold($user);

        if ($threshold <= 0 || $amount < $threshold) {
            return null;
        }

        $alert = SuspiciousTransactionAlert::query()->firstOrCreate(
            [
                'alertable_type' => $record->getMorphClass(),
                'alertable_id' => $record->getKey(),
                'type' => $type,
            ],
            [
                'organization_id' => $user->organization_id,
                'branch_id' => $branchId ?: $user->branch_id,
                'user_id' => $user->id,
                'severity' => $amount >= ($threshold * 2) ? 'high' : 'medium',
                'amount' => $amount,
                'threshold' => $threshold,
                'status' => 'open',
                'message' => 'Transaction amount meets or exceeds the configured suspicious transaction threshold.',
                'metadata' => ['role_id' => $user->role_id, 'role' => $user->role?->slug],
            ],
        );

        if ($alert->wasRecentlyCreated) {
            $this->auditLogger->record('financial_control.suspicious_transaction_alerted', $alert, [
                'amount' => $amount,
                'threshold' => $threshold,
                'type' => $type,
            ]);
        }

        return $alert;
    }

    public function closeDrawer(DrawerSession $session, User $user, float $closingBalance): DrawerSession
    {
        if ($closingBalance < 0) {
            throw new InvalidArgumentException('Closing balance cannot be negative.');
        }

        if ($closingBalance > 0) {
            CurrencyDenomination::assertValid($closingBalance, 'Closing balance');
        }

        return DB::transaction(function () use ($session, $user, $closingBalance): DrawerSession {
            $session = DrawerSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $drawer = $session->drawer()->lockForUpdate()->firstOrFail();
            $expected = (float) $drawer->current_balance;

            if ($session->status !== 'open') {
                throw new InvalidArgumentException('Only open drawer sessions can be closed.');
            }

            $session->update([
                'closing_balance' => $closingBalance,
                'expected_balance' => $expected,
                'variance' => $closingBalance - $expected,
                'status' => 'closed',
                'closed_at' => now(),
            ]);

            $drawer->update(['status' => 'closed', 'current_balance' => $closingBalance]);

            if (abs($closingBalance - $expected) > 0) {
                $this->recordSuspiciousTransaction($user, $session, abs($closingBalance - $expected), 'drawer_closing_variance', $drawer->branch_id);
            }

            $this->auditLogger->record('financial_control.drawer_closed', $session, [
                'expected_balance' => $expected,
                'closing_balance' => $closingBalance,
            ]);

            return $session->refresh();
        });
    }

    public function reconcileBranchCash(Branch $branch, User $user, CarbonInterface|string|null $businessDate, float $countedCash, ?string $notes = null): BranchCashReconciliation
    {
        $date = $businessDate instanceof CarbonInterface ? CarbonImmutable::parse($businessDate->toDateString()) : CarbonImmutable::parse($businessDate ?: today()->toDateString());

        if ($countedCash > 0) {
            CurrencyDenomination::assertValid($countedCash, 'Counted cash');
        }

        return DB::transaction(function () use ($branch, $user, $date, $countedCash, $notes): BranchCashReconciliation {
            $financialDay = FinancialDay::query()->firstOrCreate(
                ['branch_id' => $branch->id, 'business_date' => $date->toDateString()],
                ['organization_id' => $branch->organization_id, 'status' => 'open', 'opened_by' => $user->id, 'opened_at' => now()],
            );
            $expectedCash = (float) $branch->drawers()->sum('current_balance');
            $variance = $countedCash - $expectedCash;

            $reconciliation = BranchCashReconciliation::query()->updateOrCreate(
                ['branch_id' => $branch->id, 'business_date' => $date->toDateString()],
                [
                    'organization_id' => $branch->organization_id,
                    'financial_day_id' => $financialDay->id,
                    'expected_cash' => $expectedCash,
                    'counted_cash' => $countedCash,
                    'variance' => $variance,
                    'status' => abs($variance) > 0 ? 'variance' : 'balanced',
                    'prepared_by' => $user->id,
                    'notes' => $notes,
                ],
            );

            if (abs($variance) > 0) {
                $this->recordSuspiciousTransaction($user, $reconciliation, abs($variance), 'branch_cash_variance', $branch->id);
            }

            $this->auditLogger->record('financial_control.branch_cash_reconciled', $reconciliation, [
                'expected_cash' => $expectedCash,
                'counted_cash' => $countedCash,
                'variance' => $variance,
            ]);

            return $reconciliation->refresh();
        });
    }

    public function closeFinancialDay(Branch $branch, User $user, CarbonInterface|string|null $businessDate, float $countedCash, ?string $notes = null): FinancialDay
    {
        $date = $businessDate instanceof CarbonInterface ? CarbonImmutable::parse($businessDate->toDateString()) : CarbonImmutable::parse($businessDate ?: today()->toDateString());

        return DB::transaction(function () use ($branch, $user, $date, $countedCash, $notes): FinancialDay {
            $openSessions = DrawerSession::query()
                ->whereHas('drawer', fn ($query) => $query->where('branch_id', $branch->id))
                ->where('status', 'open')
                ->exists();

            if ($openSessions) {
                throw new InvalidArgumentException('All teller sessions must be closed before closing the financial day.');
            }

            $reconciliation = $this->reconcileBranchCash($branch, $user, $date, $countedCash, $notes);
            $financialDay = FinancialDay::query()->whereKey($reconciliation->financial_day_id)->lockForUpdate()->firstOrFail();

            $financialDay->update(['status' => 'closed', 'closed_by' => $user->id, 'closed_at' => now(), 'notes' => $notes]);
            $reconciliation->update([
                'status' => abs((float) $reconciliation->variance) > 0 ? 'closed_with_variance' : 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            $this->auditLogger->record('financial_control.financial_day_closed', $financialDay, [
                'business_date' => $date->toDateString(),
                'branch_id' => $branch->id,
            ]);

            return $financialDay->refresh();
        });
    }

    public function limitFor(User $user): ?RoleFinancialLimit
    {
        if (! $user->role_id) {
            return null;
        }

        return RoleFinancialLimit::query()
            ->where('role_id', $user->role_id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $user->organization_id))
            ->latest('organization_id')
            ->first();
    }

    private function executeApprovedAction(MakerCheckerRequest $request, User $checker): void
    {
        match ($request->action) {
            'savings_withdrawal' => $this->performSavingsWithdrawal($request, $checker),
            'savings_transaction_reversal' => $this->performSavingsReversal($request, $checker),
            'transaction_reversal' => $this->transactionReversals->approve($request, $checker),
            'loan_approval' => $this->performLoanApproval($request, $checker),
            'loan_disbursement' => $this->loans->disburseToSavings(Loan::findOrFail($request->subject_id), $checker, $request->payload['payment_method'] ?? 'transfer_to_savings'),
            'journal_posting' => $this->performJournalPosting($request, $checker),
            'investment_approval' => app(InvestmentService::class)->approveInvestment(InvestmentAccount::findOrFail($request->subject_id), $checker, $request->payload['payment_method'] ?? 'cash', $request->payload['narration'] ?? null),
            'loan_write_off' => $this->performLoanWriteOff($request, $checker),
            'settings_update' => $this->performSettingsUpdate($request, $checker),
            'loan_product_update', 'interest_rate_change' => $this->performProductUpdate(LoanProduct::findOrFail($request->subject_id), $request, $checker),
            'savings_product_update' => $this->performProductUpdate(SavingsProduct::findOrFail($request->subject_id), $request, $checker),
            'penalty_waiver' => $this->performPenaltyWaiver($request, $checker),
            default => throw new InvalidArgumentException('Unsupported maker-checker action.'),
        };
    }

    private function performSavingsWithdrawal(MakerCheckerRequest $request, User $checker): void
    {
        $payload = $request->payload ?? [];
        $account = SavingsAccount::query()->with('product')->whereKey($request->subject_id)->lockForUpdate()->firstOrFail();
        $amount = (float) $payload['amount'];
        $this->ensureFinancialDayOpen($checker, (int) $account->branch_id, $payload['transaction_date'] ?? today());
        $this->enforceTransactionLimit($checker, $amount, today());

        if ($account->status !== 'active') {
            throw new InvalidArgumentException('Savings account is not active.');
        }

        $charge = (float) $account->product->withdrawal_charge;
        $balanceBefore = (float) $account->current_balance;
        $balanceAfter = $balanceBefore - ($amount + $charge);

        if (! $account->product->allows_overdraft && $balanceAfter < (float) $account->product->minimum_balance) {
            throw new InvalidArgumentException('Withdrawal would breach the minimum balance.');
        }

        $account->update(['current_balance' => $balanceAfter, 'available_balance' => $balanceAfter]);
        $transaction = SavingsTransaction::create([
            'organization_id' => $account->organization_id,
            'branch_id' => $account->branch_id,
            'client_id' => $account->client_id,
            'savings_account_id' => $account->id,
            'teller_id' => $checker->id,
            'reference' => 'ST'.now()->format('ymdHis').Str::upper(Str::random(5)),
            'type' => 'withdrawal',
            'amount' => $amount,
            'charges' => $charge,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'payment_method' => $payload['payment_method'] ?? 'cash',
            'narration' => $payload['narration'] ?? null,
            'transaction_date' => $payload['transaction_date'] ?? now()->toDateString(),
            'value_date' => $payload['transaction_date'] ?? now()->toDateString(),
            'status' => 'successful',
        ]);

        $lines = [
            ['account_code' => '2100', 'debit' => $amount + $charge, 'narration' => 'Client savings liability reduced'],
            ['account_code' => '1000', 'credit' => $amount, 'narration' => 'Savings withdrawal cash paid'],
        ];

        if ($charge > 0) {
            $lines[] = ['account_code' => '4200', 'credit' => $charge, 'narration' => 'Savings withdrawal charge'];
        }

        $this->journals->post($lines, "Savings withdrawal {$transaction->reference}", $checker, $transaction->branch_id);
        $request->update(['subject_type' => $transaction->getMorphClass(), 'subject_id' => $transaction->id]);
        $this->recordSuspiciousTransaction($checker, $transaction, $amount, 'large_withdrawal', $transaction->branch_id);
    }

    private function performLoanApproval(MakerCheckerRequest $request, User $checker): void
    {
        $amount = (float) $request->payload['approved_amount'];
        $this->enforceLoanApprovalLimit($checker, $amount);
        $this->loans->approve(Loan::findOrFail($request->subject_id), $checker, $amount, $request->payload['comments'] ?? null);
    }

    private function performSavingsReversal(MakerCheckerRequest $request, User $checker): void
    {
        $transaction = SavingsTransaction::query()->whereKey($request->subject_id)->lockForUpdate()->firstOrFail();
        $account = SavingsAccount::query()->whereKey($transaction->savings_account_id)->lockForUpdate()->firstOrFail();

        if ($transaction->status !== 'successful') {
            throw new InvalidArgumentException('Only successful transactions can be reversed.');
        }

        $this->ensureFinancialDayOpen($checker, (int) $transaction->branch_id, today());
        $account->update(['current_balance' => $transaction->balance_before, 'available_balance' => $transaction->balance_before]);
        $transaction->update(['status' => 'reversed', 'reversed_by' => $checker->id, 'reversal_reason' => $request->reason]);
    }

    private function performJournalPosting(MakerCheckerRequest $request, User $checker): void
    {
        $payload = $request->payload ?? [];
        $journal = $this->journals->post($payload['lines'], $payload['description'], $checker, $payload['branch_id'] ?? $checker->branch_id);
        $request->update(['subject_type' => $journal->getMorphClass(), 'subject_id' => $journal->id]);
    }

    private function performLoanWriteOff(MakerCheckerRequest $request, User $checker): void
    {
        $loan = Loan::query()->whereKey($request->subject_id)->lockForUpdate()->firstOrFail();

        if (! in_array($loan->status, ['active', 'in_arrears', 'restructured'], true)) {
            throw new InvalidArgumentException('Only active, in-arrears, or restructured loans can be written off.');
        }

        $loan->update(['status' => 'written_off']);
        $this->auditLogger->record('loan.written_off_checked', $loan, ['reason' => $request->payload['reason'] ?? $request->reason]);
    }

    private function performSettingsUpdate(MakerCheckerRequest $request, User $checker): void
    {
        foreach (($request->payload['settings'] ?? []) as $key => $value) {
            $setting = SystemSetting::query()
                ->where('key', $key)
                ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $checker->organization_id))
                ->latest('organization_id')
                ->firstOrFail();

            $old = $setting->toArray();
            $setting->update(['value' => $value, 'updated_by' => $checker->id]);
            $this->auditLogger->record('setting.updated_checked', $setting, ['key' => $setting->key], $old);
        }
    }

    private function performProductUpdate(Model $product, MakerCheckerRequest $request, User $checker): void
    {
        $old = $product->toArray();
        $product->update(($request->payload ?? []) + ['updated_by' => $checker->id]);
        $this->auditLogger->record('product.updated_checked', $product, ['action' => $request->action], $old);
    }

    private function performPenaltyWaiver(MakerCheckerRequest $request, User $checker): void
    {
        $loan = Loan::query()->whereKey($request->subject_id)->lockForUpdate()->firstOrFail();
        $amount = min((float) ($request->payload['amount'] ?? 0), (float) $loan->penalty_balance);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Penalty waiver amount must be greater than zero.');
        }

        $loan->decrement('penalty_balance', $amount);
        $this->auditLogger->record('loan.penalty_waived_checked', $loan->fresh(), ['amount' => $amount, 'reason' => $request->reason]);
    }

    private function ensureBackdateAllowed(User $user, CarbonImmutable $businessDate): void
    {
        if ($businessDate->isSameDay(today()) || $businessDate->isFuture()) {
            return;
        }

        if (! $user->canPerform('post-backdated-transactions')) {
            throw new InvalidArgumentException('Backdated transactions require permission.');
        }

        $limit = $this->limitFor($user);
        $daysBack = (int) $businessDate->diffInDays(today());

        if ($limit && (int) $limit->max_backdate_days > 0 && $daysBack > (int) $limit->max_backdate_days) {
            throw new InvalidArgumentException('Backdated transaction date is outside this role limit.');
        }
    }

    private function largeWithdrawalThreshold(User $user): float
    {
        $limit = $this->limitFor($user);

        if ($limit?->maker_checker_threshold !== null) {
            return (float) $limit->maker_checker_threshold;
        }

        $setting = SystemSetting::query()
            ->where('key', 'large_withdrawal_maker_checker_threshold')
            ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $user->organization_id))
            ->latest('organization_id')
            ->first();

        return (float) ($setting?->value ?? 5_000_000);
    }

    private function suspiciousThreshold(User $user): float
    {
        $limit = $this->limitFor($user);

        if ($limit?->suspicious_transaction_threshold !== null) {
            return (float) $limit->suspicious_transaction_threshold;
        }

        $setting = SystemSetting::query()
            ->where('key', 'suspicious_transaction_threshold')
            ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $user->organization_id))
            ->latest('organization_id')
            ->first();

        return (float) ($setting?->value ?? 0);
    }
}
