<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoanRepaymentResource;
use App\Http\Resources\LoanScheduleResource;
use App\Models\Account;
use App\Models\Branch;
use App\Models\BranchDailySummary;
use App\Models\ChartAccount;
use App\Models\FinancialDay;
use App\Models\Loan;
use App\Models\LoanSavingsDeductionRun;
use App\Models\LoanSavingsDeductionSchedule;
use App\Models\LoanSchedule;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Services\EndOfDayService;
use App\Services\FinancialControlService;
use App\Services\FinancialRelationshipService;
use App\Services\LedgerChargeService;
use App\Services\LoanService;
use App\Services\ScheduledSavingsLoanDeductionService;
use App\Services\TransactionPostingService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use App\Support\CurrencyDenomination;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

class MobileOperationsController extends Controller
{
    public function savingsLoanSources(Request $request): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'post-repayments');
        $data = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);
        $search = '%'.$data['search'].'%';

        $accounts = $this->savingsAccounts($request)
            ->where(function (Builder $query) use ($search): void {
                $query->whereAny(['account_number', 'account_name'], 'like', $search)
                    ->orWhereHas('client', fn (Builder $query): Builder => $query->whereAny(['client_number', 'first_name', 'last_name', 'business_name', 'phone', 'alternative_phone'], 'like', $search));
            })
            ->orderBy('account_number')->limit(30)->get()
            ->map(fn (Account $account): array => $this->accountPayload($account));

        return ApiResponse::data($accounts, 'Savings accounts retrieved.');
    }

    public function payableLoans(Request $request, Account $account): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'post-repayments');
        $account = $this->savingsAccounts($request)->whereKey($account->id)->firstOrFail();
        $loans = Loan::query()->with(['product', 'schedules' => fn (Builder $query): Builder => $query->where('status', '!=', 'paid')->where('outstanding_amount', '>', 0)->orderBy('due_date')])
            ->where('client_id', $account->client_id)->whereIn('status', Loan::OPERATIONAL_STATUSES)
            ->whereHas('schedules', fn (Builder $query): Builder => $query->where('status', '!=', 'paid')->where('outstanding_amount', '>', 0))
            ->orderBy('loan_number')->get()
            ->map(fn (Loan $loan): array => [
                'id' => $loan->id, 'loan_number' => $loan->loan_number, 'product' => $loan->product?->name, 'status' => $loan->status,
                'unpaid_amount' => round((float) $loan->schedules->sum('outstanding_amount'), 2), 'next_due_date' => $loan->schedules->first()?->due_date?->toDateString(),
            ]);

        return ApiResponse::data(['source_account' => $this->accountPayload($account), 'loans' => $loans], 'Payable client loans retrieved.');
    }

    public function repayLoanFromAccountSavings(Request $request, Loan $loan, FinancialRelationshipService $relationships): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'post-repayments');
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
        ]);
        $account = $this->savingsAccounts($request)->findOrFail($data['account_id']);

        try {
            $repayment = $relationships->repayLoanFromAccountSavings($loan, $account, (float) $data['amount'], $request->user());
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }

        return ApiResponse::resource(new LoanRepaymentResource($repayment->load('receivedBy')), 'Loan repayment deducted from savings.', 201);
    }

    public function scheduledDeductions(Request $request): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'post-repayments');
        $schedules = ApiSecurity::scopeTenant(LoanSavingsDeductionSchedule::query(), $request->user())
            ->with(['client', 'loan.product', 'account'])
            ->latest('id')
            ->paginate(30);

        return ApiResponse::data($schedules->getCollection()->map(fn (LoanSavingsDeductionSchedule $schedule): array => $this->schedulePayload($schedule)), 'Scheduled deductions retrieved.', meta: $this->pagination($schedules));
    }

    public function createScheduledDeduction(Request $request, ScheduledSavingsLoanDeductionService $deductions): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'post-repayments');
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'], 'loan_id' => ['required', 'integer', 'exists:loans,id'],
            'amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'frequency' => ['required', 'in:daily,weekly,biweekly,monthly'], 'next_run_at' => ['required', 'date'],
        ]);
        $account = $this->savingsAccounts($request)->findOrFail($data['account_id']);
        $loan = Loan::query()->findOrFail($data['loan_id']);
        ApiSecurity::assertTenantAccess($request->user(), $loan);

        try {
            $schedule = $deductions->create($account, $loan, (float) $data['amount'], $data['frequency'], CarbonImmutable::parse($data['next_run_at']), $request->user());
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }

        return ApiResponse::data($this->schedulePayload($schedule->load(['client', 'loan.product', 'account'])), 'Scheduled deduction created and awaiting administrator activation.', 201);
    }

    public function activateScheduledDeduction(Request $request, LoanSavingsDeductionSchedule $schedule, ScheduledSavingsLoanDeductionService $deductions): JsonResponse
    {
        try {
            $schedule = $deductions->activate($schedule, $request->user());
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }

        return ApiResponse::data($this->schedulePayload($schedule->load(['client', 'loan.product', 'account'])), 'Scheduled deduction activated.');
    }

    public function disableScheduledDeduction(Request $request, LoanSavingsDeductionSchedule $schedule, ScheduledSavingsLoanDeductionService $deductions): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:disabled,cancelled']]);

        try {
            $schedule = $deductions->disable($schedule, $request->user(), $data['status']);
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }

        return ApiResponse::data(
            $this->schedulePayload($schedule->load(['client', 'loan.product', 'account'])),
            $data['status'] === 'cancelled' ? 'Scheduled deduction cancelled.' : 'Scheduled deduction disabled.'
        );
    }

    public function scheduledDeductionRuns(Request $request, LoanSavingsDeductionSchedule $schedule): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'post-repayments');
        ApiSecurity::assertTenantAccess($request->user(), $schedule);

        $runs = $schedule->runs()->latest('id')->paginate(30);

        return ApiResponse::data(
            $runs->getCollection()->map(fn (LoanSavingsDeductionRun $run): array => [
                'id' => $run->id,
                'amount' => (float) $run->amount,
                'due_at' => $run->due_at?->toIso8601String(),
                'processed_at' => $run->processed_at?->toIso8601String(),
                'status' => $run->status,
                'failure_reason' => $run->failure_reason,
                'loan_repayment_id' => $run->loan_repayment_id,
            ]),
            'Scheduled deduction run history retrieved.',
            meta: $this->pagination($runs)
        );
    }

    public function writeOffArrears(Request $request, Loan $loan, LoanService $loans): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole(['super-admin', 'organization-admin']), 403);
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()]]);

        try {
            $result = $loans->writeOffArrears($loan, (float) $data['amount'], $request->user());
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }

        return ApiResponse::data($result + ['loan_id' => $loan->id], 'Arrears partially written off. Remaining arrears remain payable.');
    }

    public function dueRepayments(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-loans') || $request->user()->canPerform('view-reports'), 403);
        $schedules = LoanSchedule::query()->with('loan.client')->excludingCancelledLoans()->where('status', '!=', 'paid')
            ->where('outstanding_amount', '>', 0)->whereDate('due_date', '<=', today())
            ->whereHas('loan', fn (Builder $query): Builder => ApiSecurity::scopeTenant($query, $request->user())->whereIn('status', Loan::OPERATIONAL_STATUSES))
            ->orderBy('due_date')->paginate(30);

        return ApiResponse::collection(LoanScheduleResource::collection($schedules), 'Due repayments retrieved.');
    }

    public function nonCashOptions(Request $request): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'post-transactions');

        return ApiResponse::data([
            'transaction_types' => TransactionType::query()->where('is_active', true)->get(['id', 'name', 'code', 'direction']),
            'ledgers' => $this->ledgers($request)->get(['id', 'code', 'name', 'type', 'normal_balance']),
        ], 'Non-cash options retrieved.');
    }

    public function nonCashAccounts(Request $request): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'post-transactions');
        $data = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);
        $search = '%'.$data['search'].'%';
        $accounts = ApiSecurity::scopeTenant(Account::query(), $request->user())
            ->with('client')
            ->where('status', 'active')
            ->where(function (Builder $query) use ($search): void {
                $query->whereAny(['account_number', 'account_name'], 'like', $search)
                    ->orWhereHas('client', fn (Builder $query): Builder => $query->whereAny(['client_number', 'first_name', 'last_name', 'business_name', 'phone'], 'like', $search));
            })
            ->orderBy('account_number')
            ->limit(30)
            ->get()
            ->map(fn (Account $account): array => $this->accountPayload($account));

        return ApiResponse::data($accounts, 'Accounts retrieved.');
    }

    public function postNonCash(Request $request, TransactionPostingService $posting): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'post-transactions');
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'], 'chart_account_id' => ['required', 'integer', 'exists:chart_accounts,id'],
            'transaction_type_id' => ['required', 'integer', 'exists:transaction_types,id'],
            'amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'charges' => ['nullable', 'numeric', 'min:0'], 'charge_mode' => ['nullable', 'in:add_to_amount,deduct_from_amount'],
            'description' => ['required', 'string', 'max:1000'], 'transaction_date' => ['required', 'date'],
        ]);
        $account = ApiSecurity::scopeTenant(Account::query(), $request->user())->with('accountType')->findOrFail($data['account_id']);
        $ledger = $this->ledgers($request)->findOrFail($data['chart_account_id']);
        $type = TransactionType::query()->where('is_active', true)->findOrFail($data['transaction_type_id']);

        try {
            $transaction = $posting->postNonCash($account, $type, $ledger, (float) $data['amount'], $request->user(), $data['description'], CarbonImmutable::parse($data['transaction_date']), (float) ($data['charges'] ?? 0), LedgerChargeService::normalizeMode($data['charge_mode'] ?? null));
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }

        return ApiResponse::data($this->transactionPayload($transaction->load(['account.client', 'type', 'user'])), 'Non-cash transaction posted.', 201);
    }

    public function nonCashTransactions(Request $request): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'post-transactions');
        $transactions = ApiSecurity::scopeTenant(Transaction::query(), $request->user())->nonCash()->with(['account.client', 'type', 'user'])->latest('posted_at')->paginate(30);

        return ApiResponse::data($transactions->getCollection()->map(fn (Transaction $transaction): array => $this->transactionPayload($transaction)), 'Non-cash transactions retrieved.', meta: $this->pagination($transactions));
    }

    public function requestNonCashReversal(Request $request, Transaction $transaction, FinancialControlService $controls): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $transaction);
        abort_unless($this->canRequestReversal($request, $transaction), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        abort_unless($transaction->status === 'posted' && $transaction->reversed_by === null, 422, 'Only posted transactions that have not been reversed can be requested for reversal.');
        abort_if($transaction->reversalRequests()->where('status', 'pending')->exists(), 422, 'A reversal request is already pending for this transaction.');

        try {
            $approval = $controls->requestAction(
                maker: $request->user(),
                action: 'transaction_reversal',
                subject: $transaction,
                payload: ['reason' => $data['reason']],
                amount: (float) $transaction->amount,
                requiredPermission: 'approve-reversals',
                reason: $data['reason'],
                oldValues: $transaction->only(['status', 'amount', 'balance_after', 'reference']),
            );
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }

        return ApiResponse::data([
            'id' => $approval->id,
            'status' => $approval->status,
            'reference' => $transaction->reference,
        ], 'Reversal request submitted for approval.', 201);
    }

    public function endOfDay(Request $request): JsonResponse
    {
        $this->assertEndOfDayPermission($request);
        $branch = $this->endOfDayBranch($request);
        $date = CarbonImmutable::parse($request->input('business_date', today()->toDateString()))->toDateString();
        $financialDay = FinancialDay::query()->where('branch_id', $branch->id)->whereDate('business_date', $date)->first();
        $summary = BranchDailySummary::query()->where('branch_id', $branch->id)->whereDate('business_date', $date)->first();

        return ApiResponse::data([
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'business_date' => $date,
            'financial_day_status' => $financialDay?->status ?? 'not_opened',
            'summary' => $summary ? [
                'expected_cash' => (float) $summary->expected_cash,
                'counted_cash' => (float) $summary->counted_cash,
                'cash_variance' => (float) $summary->cash_variance,
                'open_teller_sessions' => $summary->open_teller_sessions,
                'loan_repayments' => (float) $summary->loan_repayments,
                'savings_deposits' => (float) $summary->savings_deposits,
            ] : null,
        ], 'End-of-day status retrieved.');
    }

    public function closeEndOfDay(Request $request, EndOfDayService $endOfDay): JsonResponse
    {
        $this->assertEndOfDayPermission($request);
        $data = $request->validate([
            'business_date' => ['required', 'date'],
            'counted_cash' => ['required', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $financialDay = $endOfDay->close(
                $this->endOfDayBranch($request),
                $request->user(),
                $data['business_date'],
                (float) $data['counted_cash'],
                $data['notes'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }

        return ApiResponse::data([
            'business_date' => $financialDay->business_date?->toDateString(),
            'status' => $financialDay->status,
            'closed_at' => $financialDay->closed_at?->toIso8601String(),
        ], 'Business day closed and processed.');
    }

    /** @return Builder<Account> */
    private function savingsAccounts(Request $request): Builder
    {
        return ApiSecurity::scopeTenant(Account::query(), $request->user())->with(['client', 'accountType'])->where('status', 'active')
            ->whereHas('accountType', fn (Builder $query): Builder => $query->where('category', 'savings'));
    }

    /** @return Builder<ChartAccount> */
    private function ledgers(Request $request): Builder
    {
        return ApiSecurity::scopeTenant(ChartAccount::query(), $request->user())->where('is_active', true)->where('is_cash_account', false)
            ->where('type', 'asset')->where('normal_balance', 'debit');
    }

    /** @return array<string, mixed> */
    private function accountPayload(Account $account): array
    {
        return ['id' => $account->id, 'account_number' => $account->account_number, 'account_name' => $account->account_name, 'balance' => (float) $account->balance,
            'client' => ['id' => $account->client?->id, 'name' => $account->client?->full_name, 'number' => $account->client?->client_number, 'phone' => $account->client?->phone]];
    }

    /** @return array<string, mixed> */
    private function schedulePayload(LoanSavingsDeductionSchedule $schedule): array
    {
        return ['id' => $schedule->id, 'account_number' => $schedule->account?->account_number, 'client_name' => $schedule->client?->full_name,
            'loan_number' => $schedule->loan?->loan_number, 'loan_product' => $schedule->loan?->product?->name, 'amount' => (float) $schedule->amount,
            'frequency' => $schedule->frequency, 'next_run_at' => $schedule->next_run_at?->toIso8601String(), 'status' => $schedule->status,
            'last_result' => $schedule->last_result, 'last_message' => $schedule->last_message];
    }

    /** @return array<string, mixed> */
    private function transactionPayload(Transaction $transaction): array
    {
        return ['id' => $transaction->id, 'reference' => $transaction->reference, 'amount' => (float) $transaction->amount,
            'charges' => (float) $transaction->charges, 'balance_after' => (float) $transaction->balance_after, 'description' => $transaction->description,
            'posted_at' => $transaction->posted_at?->toIso8601String(), 'type' => $transaction->type?->name,
            'account' => ['number' => $transaction->account?->account_number, 'name' => $transaction->account?->account_name, 'client' => $transaction->account?->client?->full_name],
            'posted_by' => $transaction->user?->name];
    }

    /** @return array{pagination: array{current_page: int, last_page: int, total: int}} */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return ['pagination' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()]];
    }

    private function canRequestReversal(Request $request, Transaction $transaction): bool
    {
        $user = $request->user();

        if ($user->isLoanOfficer() || $user->isLoanRecoveryOfficer()) {
            return false;
        }

        if ((int) $transaction->branch_id !== (int) $user->branch_id && ! ApiSecurity::hasGlobalBranchAccess($user)) {
            return false;
        }

        if ($user->isTellerCashier()) {
            return (int) $transaction->user_id === (int) $user->id
                && ($transaction->posted_at ?? $transaction->created_at)->isToday();
        }

        return $user->hasRole('super-admin')
            || $user->hasRole('organization-admin')
            || $user->canPerform('request-reversals')
            || $user->canPerform('approve-reversals')
            || $user->canPerform('override-maker-checker');
    }

    private function assertEndOfDayPermission(Request $request): void
    {
        abort_unless(
            $request->user()->canPerform('manage-end-of-day')
                || $request->user()->canPerform('manage-accounting')
                || $request->user()->canPerform('manage-drawers'),
            403
        );
    }

    private function endOfDayBranch(Request $request): Branch
    {
        $branch = Branch::query()->findOrFail($request->integer('branch_id') ?: $request->user()->branch_id);
        ApiSecurity::assertTenantAccess($request->user(), $branch);
        abort_unless(ApiSecurity::hasGlobalBranchAccess($request->user()) || (int) $branch->id === (int) $request->user()->branch_id, 403);

        return $branch;
    }
}
