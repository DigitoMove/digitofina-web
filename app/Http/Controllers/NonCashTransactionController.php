<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNonCashTransactionRequest;
use App\Http\Requests\StoreSavingsLoanRepaymentRequest;
use App\Http\Requests\StoreScheduledLoanSavingsDeductionRequest;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Loan;
use App\Models\LoanSavingsDeductionSchedule;
use App\Models\SavingsAccount;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Services\FinancialRelationshipService;
use App\Services\LedgerChargeService;
use App\Services\ScheduledSavingsLoanDeductionService;
use App\Services\TransactionPostingService;
use App\Support\ApiSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class NonCashTransactionController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(! $request->user()->isMember(), 403);

        $historyDates = $request->validate([
            'history_from' => ['nullable', 'date'],
            'history_to' => ['nullable', 'date'],
        ]);
        $branchId = $this->branchId($request);
        $accountSearch = $request->string('account_search')->trim()->toString();
        $ledgerSearch = $request->string('ledger_search')->trim()->toString();
        $historySearch = $request->string('history_search')->trim()->toString();
        $historyFrom = (string) ($historyDates['history_from'] ?? '');
        $historyTo = (string) ($historyDates['history_to'] ?? '');
        $selectedAccountTypeId = $request->integer('account_type_id') ?: null;
        $selectedRepaymentSavings = $request->integer('repayment_savings_account_id')
            ? SavingsAccount::query()
                ->with(['client', 'product'])
                ->where('status', 'active')
                ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                ->whereKey($request->integer('repayment_savings_account_id'))
                ->first()
            : null;

        $selectedAccount = $request->integer('account_id')
            ? Account::query()
                ->with(['client', 'accountType', 'branch'])
                ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                ->whereKey($request->integer('account_id'))
                ->first()
            : null;

        $selectedLedger = $request->integer('chart_account_id')
            ? ChartAccount::query()
                ->with('branch')
                ->when($branchId, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $branchId)->orWhereNull('branch_id')))
                ->whereKey($request->integer('chart_account_id'))
                ->first()
            : null;

        return view('non-cash.index', [
            'branches' => Branch::query()
                ->where('is_active', true)
                ->when(! $this->canSeeAllBranches($request), fn (Builder $query): Builder => $query->whereKey($request->user()->branch_id))
                ->orderBy('name')
                ->get(),
            'branchId' => $branchId,
            'accountTypes' => AccountType::query()->where('is_active', true)->orderBy('name')->get(),
            'transactionTypes' => TransactionType::query()->where('is_active', true)->orderBy('name')->get(),
            'chargeModes' => LedgerChargeService::modes(),
            'selectedAccountTypeId' => $selectedAccountTypeId,
            'selectedAccount' => $selectedAccount,
            'selectedLedger' => $selectedLedger,
            'accountResults' => Account::query()
                ->with(['client', 'accountType', 'branch'])
                ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                ->when($selectedAccountTypeId, fn (Builder $query, int $typeId): Builder => $query->where('account_type_id', $typeId))
                ->when($accountSearch !== '', function (Builder $query) use ($accountSearch): void {
                    $query
                        ->where(function (Builder $query): void {
                            $query->whereHas('accountType', fn (Builder $query): Builder => $query->where('category', '!=', 'loan'))
                                ->orWhereHas('loan', fn (Builder $query): Builder => $query->forOperationalListing());
                        })
                        ->where(function (Builder $query) use ($accountSearch): void {
                            $query->whereAny(['account_number', 'account_name', 'status'], 'like', "%{$accountSearch}%")
                                ->orWhereHas('client', function (Builder $query) use ($accountSearch): void {
                                    $query->whereAny(['client_number', 'first_name', 'middle_name', 'last_name', 'business_name', 'phone', 'alternative_phone'], 'like', "%{$accountSearch}%");
                                });
                        });
                })
                ->when($accountSearch === '', fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
                ->orderBy('account_number')
                ->limit(12)
                ->get(),
            'ledgerResults' => ChartAccount::query()
                ->with('branch')
                ->when($branchId, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $branchId)->orWhereNull('branch_id')))
                ->where('is_active', true)
                ->where('is_cash_account', false)
                ->when($ledgerSearch !== '', fn (Builder $query): Builder => $query->whereAny(['code', 'name', 'type'], 'like', "%{$ledgerSearch}%"))
                ->when($ledgerSearch === '', fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
                ->orderBy('type')
                ->orderBy('name')
                ->limit(12)
                ->get(),
            'recentTransactions' => Transaction::query()
                ->nonCash()
                ->with([
                    'account.client',
                    'account.branch',
                    'type',
                    'user',
                    'journalEntries.chartAccount',
                    'loanRepayment.loan',
                    'reversalRequests' => fn ($query) => $query->where('status', 'pending')->latest(),
                ])
                ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                ->when($historyFrom !== '', fn (Builder $query): Builder => $query->whereDate('posted_at', '>=', $historyFrom))
                ->when($historyTo !== '', fn (Builder $query): Builder => $query->whereDate('posted_at', '<=', $historyTo))
                ->when($historySearch !== '', fn (Builder $query): Builder => $this->searchTransactions($query, $historySearch))
                ->latest('posted_at')
                ->limit(50)
                ->get(),
            'historySearch' => $historySearch,
            'historyFrom' => $historyFrom,
            'historyTo' => $historyTo,
            'receiptTransaction' => $this->receiptTransaction($request),
            'canPostSavingsLoanRepayment' => $request->user()->canPerform('post-repayments'),
            'selectedRepaymentSavings' => $selectedRepaymentSavings,
            'selectedRepaymentLoan' => $request->integer('repayment_loan_id')
                ? Loan::query()
                    ->with(['client', 'product'])
                    ->whereIn('status', ['active', 'in_arrears'])
                    ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                    ->when($selectedRepaymentSavings, fn (Builder $query, SavingsAccount $account): Builder => $query->where('client_id', $account->client_id))
                    ->whereKey($request->integer('repayment_loan_id'))
                    ->first()
                : null,
            'repaymentSavingsAccounts' => SavingsAccount::query()
                ->with(['client', 'product'])
                ->where('status', 'active')
                ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                ->orderBy('account_number')
                ->get(),
            'repaymentLoans' => Loan::query()
                ->with(['client', 'product'])
                ->whereIn('status', ['active', 'in_arrears'])
                ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                ->when($selectedRepaymentSavings, fn (Builder $query, SavingsAccount $account): Builder => $query->where('client_id', $account->client_id))
                ->orderBy('loan_number')
                ->get(),
            'scheduledDeductionSchedules' => LoanSavingsDeductionSchedule::query()
                ->with(['client', 'loan.product', 'savingsAccount', 'creator', 'activator', 'runs' => fn ($query) => $query->latest('due_at')->limit(5)])
                ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                ->whereIn('status', LoanSavingsDeductionSchedule::STATUSES)
                ->latest('id')
                ->limit(50)
                ->get(),
            'canConfigureScheduledDeductions' => $request->user()->canPerform('post-repayments'),
            'canAdministerScheduledDeductions' => $request->user()->hasAnyRole(['super-admin', 'organization-admin']),
        ]);
    }

    public function repayLoanFromSavings(StoreSavingsLoanRepaymentRequest $request, FinancialRelationshipService $relationships): RedirectResponse
    {
        $branchId = $this->branchId($request);
        $savingsAccount = SavingsAccount::query()
            ->where('status', 'active')
            ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->findOrFail($request->integer('savings_account_id'));
        $loan = Loan::query()
            ->whereIn('status', ['active', 'in_arrears'])
            ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->findOrFail($request->integer('loan_id'));

        ApiSecurity::assertTenantAccess($request->user(), $savingsAccount);
        ApiSecurity::assertTenantAccess($request->user(), $loan);

        try {
            $repayment = $relationships->repayLoanFromSavings($loan, $savingsAccount, (float) $request->validated('amount'), $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('non-cash.index')
            ->with('status', 'Savings funds transferred to the selected loan as a non-cash repayment.')
            ->with('repayment_id', $repayment->id);
    }

    public function storeScheduledDeduction(StoreScheduledLoanSavingsDeductionRequest $request, ScheduledSavingsLoanDeductionService $deductions): RedirectResponse
    {
        $branchId = $this->branchId($request);
        $savingsAccount = SavingsAccount::query()
            ->where('status', 'active')
            ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->findOrFail($request->integer('savings_account_id'));
        $loan = Loan::query()
            ->whereIn('status', Loan::OPERATIONAL_STATUSES)
            ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->findOrFail($request->integer('loan_id'));

        try {
            $schedule = $deductions->create(
                $savingsAccount,
                $loan,
                (float) $request->validated('amount'),
                (string) $request->validated('frequency'),
                CarbonImmutable::parse($request->validated('next_run_at')),
                $request->user(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('non-cash.index')->with('status', 'Scheduled UGX '.number_format((float) $schedule->amount, 2).' savings deduction created. An administrator must activate it before it can run.');
    }

    public function activateScheduledDeduction(LoanSavingsDeductionSchedule $schedule, Request $request, ScheduledSavingsLoanDeductionService $deductions): RedirectResponse
    {
        try {
            $deductions->activate($schedule, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['schedule' => $exception->getMessage()]);
        }

        return back()->with('status', 'Scheduled savings deduction activated.');
    }

    public function disableScheduledDeduction(LoanSavingsDeductionSchedule $schedule, Request $request, ScheduledSavingsLoanDeductionService $deductions): RedirectResponse
    {
        try {
            $deductions->disable($schedule, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['schedule' => $exception->getMessage()]);
        }

        return back()->with('status', 'Scheduled savings deduction disabled.');
    }

    public function cancelScheduledDeduction(LoanSavingsDeductionSchedule $schedule, Request $request, ScheduledSavingsLoanDeductionService $deductions): RedirectResponse
    {
        try {
            $deductions->disable($schedule, $request->user(), 'cancelled');
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['schedule' => $exception->getMessage()]);
        }

        return back()->with('status', 'Scheduled savings deduction cancelled.');
    }

    public function store(StoreNonCashTransactionRequest $request, TransactionPostingService $posting): RedirectResponse
    {
        $data = $request->validated();
        $branchId = $this->branchId($request);

        try {
            $account = Account::query()
                ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                ->findOrFail($data['account_id']);
            $chartAccount = ChartAccount::query()
                ->when($branchId, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $branchId)->orWhereNull('branch_id')))
                ->findOrFail($data['chart_account_id']);
            $type = TransactionType::query()->findOrFail($data['transaction_type_id']);

            $transaction = $posting->postNonCash(
                $account,
                $type,
                $chartAccount,
                (float) $data['amount'],
                $request->user(),
                $data['description'],
                CarbonImmutable::parse($data['transaction_date']),
                (float) ($data['charges'] ?? 0),
                LedgerChargeService::normalizeMode($data['charge_mode'] ?? null),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('non-cash.index')
            ->with('status', 'Non-cash transaction posted.')
            ->with('receipt_transaction_id', $transaction->id);
    }

    private function canSeeAllBranches(Request $request): bool
    {
        return ApiSecurity::hasGlobalBranchAccess($request->user());
    }

    private function branchId(Request $request): ?int
    {
        if ($this->canSeeAllBranches($request)) {
            return $request->integer('branch_id') ?: null;
        }

        return $request->user()->branch_id;
    }

    private function receiptTransaction(Request $request): ?Transaction
    {
        $transactionId = (int) session('receipt_transaction_id');

        if (! $transactionId) {
            return null;
        }

        return Transaction::query()
            ->with(['account.client', 'type', 'user'])
            ->whereKey($transactionId)
            ->when(! $this->canSeeAllBranches($request), fn (Builder $query): Builder => $query->where('branch_id', $request->user()->branch_id))
            ->first();
    }

    private function searchTransactions(Builder $query, string $search): Builder
    {
        $likeSearch = "%{$search}%";

        return $query->where(function (Builder $query) use ($likeSearch): void {
            $query->whereAny(['description', 'reference', 'status'], 'like', $likeSearch)
                ->orWhereHas('account', function (Builder $query) use ($likeSearch): void {
                    $query->whereAny(['account_number', 'account_name'], 'like', $likeSearch);
                })
                ->orWhereHas('account.client', function (Builder $query) use ($likeSearch): void {
                    $query->whereAny(['client_number', 'first_name', 'middle_name', 'last_name', 'business_name', 'phone'], 'like', $likeSearch);
                })
                ->orWhereHas('journalEntries.chartAccount', function (Builder $query) use ($likeSearch): void {
                    $query->whereAny(['code', 'name', 'type'], 'like', $likeSearch);
                });
        });
    }
}
