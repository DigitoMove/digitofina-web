<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchCashReconciliation;
use App\Models\DrawerSession;
use App\Models\FinancialDay;
use App\Models\MakerCheckerRequest;
use App\Models\RoleFinancialLimit;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\SuspiciousTransactionAlert;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FinancialControlService
{
    public function __construct(private AuditLogger $auditLogger) {}

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

    public function enforceTransactionLimit(User $user, float $amount, string $transactionType, CarbonInterface $businessDate): void
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

        if ($limit->maker_checker_threshold !== null && $amount > (float) $limit->maker_checker_threshold) {
            throw new InvalidArgumentException('Transaction requires maker-checker approval before posting.');
        }
    }

    public function enforceLoanApprovalLimit(User $user, float $amount): void
    {
        $limit = $this->limitFor($user);

        if ($limit?->loan_approval_limit !== null && $amount > (float) $limit->loan_approval_limit) {
            throw new InvalidArgumentException('Loan amount exceeds this role approval limit.');
        }
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
                'metadata' => [
                    'role_id' => $user->role_id,
                    'role' => $user->role?->slug,
                ],
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

    public function requestSavingsReversal(SavingsTransaction $transaction, User $maker, string $reason): MakerCheckerRequest
    {
        if (! $maker->canPerform('request-reversals') && ! $maker->canPerform('post-transactions')) {
            throw new InvalidArgumentException('You do not have permission to request transaction reversals.');
        }

        if ($transaction->status !== 'successful') {
            throw new InvalidArgumentException('Only successful transactions can be submitted for reversal.');
        }

        if (! in_array($transaction->type, ['deposit', 'withdrawal'], true)) {
            throw new InvalidArgumentException('Only deposits and withdrawals can be reversed automatically.');
        }

        $request = MakerCheckerRequest::query()->firstOrCreate(
            [
                'subject_type' => $transaction->getMorphClass(),
                'subject_id' => $transaction->id,
                'action' => 'savings_transaction_reversal',
                'status' => 'pending',
            ],
            [
                'organization_id' => $transaction->organization_id,
                'branch_id' => $transaction->branch_id,
                'requested_by' => $maker->id,
                'required_permission' => 'approve-reversals',
                'amount' => $transaction->amount,
                'old_values' => $transaction->only(['status', 'balance_before', 'balance_after']),
                'payload' => ['reason' => $reason],
                'reason' => $reason,
            ],
        );

        $this->auditLogger->record('financial_control.reversal_requested', $request, [
            'transaction_reference' => $transaction->reference,
            'reason' => $reason,
        ]);

        return $request;
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

            if ($request->required_permission && ! $checker->canPerform($request->required_permission)) {
                throw new InvalidArgumentException('You do not have permission to approve this request.');
            }

            if ($request->action === 'savings_transaction_reversal') {
                $this->performSavingsReversal($request, $checker);
            }

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

        if ($request->required_permission && ! $checker->canPerform($request->required_permission)) {
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

    public function closeDrawer(DrawerSession $session, User $user, float $closingBalance): DrawerSession
    {
        if ($closingBalance < 0) {
            throw new InvalidArgumentException('Closing balance cannot be negative.');
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

            $drawer->update([
                'status' => 'closed',
                'current_balance' => $closingBalance,
            ]);

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
        $date = $businessDate instanceof CarbonInterface
            ? CarbonImmutable::parse($businessDate->toDateString())
            : CarbonImmutable::parse($businessDate ?: today()->toDateString());

        return DB::transaction(function () use ($branch, $user, $date, $countedCash, $notes): BranchCashReconciliation {
            $financialDay = FinancialDay::query()->firstOrCreate(
                ['branch_id' => $branch->id, 'business_date' => $date->toDateString()],
                [
                    'organization_id' => $branch->organization_id,
                    'status' => 'open',
                    'opened_by' => $user->id,
                    'opened_at' => now(),
                ],
            );
            $expectedCash = (float) $branch->drawers()->sum('current_balance');
            $variance = $countedCash - $expectedCash;

            $reconciliation = BranchCashReconciliation::query()->updateOrCreate(
                [
                    'branch_id' => $branch->id,
                    'business_date' => $date->toDateString(),
                ],
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
        $date = $businessDate instanceof CarbonInterface
            ? CarbonImmutable::parse($businessDate->toDateString())
            : CarbonImmutable::parse($businessDate ?: today()->toDateString());

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

            $financialDay->update([
                'status' => 'closed',
                'closed_by' => $user->id,
                'closed_at' => now(),
                'notes' => $notes,
            ]);

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
            ->where(function ($query) use ($user): void {
                $query->whereNull('organization_id')->orWhere('organization_id', $user->organization_id);
            })
            ->latest('organization_id')
            ->first();
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

    private function suspiciousThreshold(User $user): float
    {
        $limit = $this->limitFor($user);

        if ($limit?->suspicious_transaction_threshold !== null) {
            return (float) $limit->suspicious_transaction_threshold;
        }

        $setting = SystemSetting::query()
            ->where('key', 'suspicious_transaction_threshold')
            ->where(function ($query) use ($user): void {
                $query->whereNull('organization_id')->orWhere('organization_id', $user->organization_id);
            })
            ->latest('organization_id')
            ->first();

        return (float) ($setting?->value ?? 0);
    }

    private function performSavingsReversal(MakerCheckerRequest $request, User $checker): void
    {
        $transaction = SavingsTransaction::query()->whereKey($request->subject_id)->lockForUpdate()->firstOrFail();
        $account = SavingsAccount::query()->whereKey($transaction->savings_account_id)->lockForUpdate()->firstOrFail();

        if ($transaction->status !== 'successful') {
            throw new InvalidArgumentException('Only successful transactions can be reversed.');
        }

        $this->ensureFinancialDayOpen($checker, (int) $transaction->branch_id, today());

        $account->update([
            'current_balance' => $transaction->balance_before,
            'available_balance' => $transaction->balance_before,
        ]);

        $transaction->update([
            'status' => 'reversed',
            'reversed_by' => $checker->id,
            'reversal_reason' => $request->reason,
        ]);

        $this->auditLogger->record('savings.transaction_reversed_checked', $transaction, [
            'reason' => $request->reason,
            'maker_checker_request_id' => $request->id,
        ]);
    }
}
