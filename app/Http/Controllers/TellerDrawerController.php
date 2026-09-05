<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDrawerTransferRequest;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\JournalEntry;
use App\Models\MakerCheckerRequest;
use App\Models\SystemSetting;
use App\Models\TellerDrawer;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DrawerTransferService;
use App\Services\DueRepaymentDemandService;
use App\Services\FinancialControlService;
use App\Services\LedgerAccountStatementService;
use App\Services\LedgerChargeService;
use App\Services\TellerService;
use App\Services\TenantContext;
use App\Services\TransactionPostingService;
use App\Support\ApiSecurity;
use App\Support\CurrencyDenomination;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class TellerDrawerController extends Controller
{
    public function index(Request $request, DueRepaymentDemandService $dueRepayments): View
    {
        abort_unless(
            $request->user()->canPerform('post-transactions')
                || $request->user()->canPerform('post-repayments')
                || $this->canOpenForTellers($request),
            403
        );

        $organizationId = $this->activeOrganizationId($request);
        $canOpenForTellers = $this->canOpenForTellers($request);
        $isCashier = $request->user()->isTellerCashier();
        $cashierDrawerCountEnabled = $this->cashierDrawerCountEnabled($request);
        $canApproveReversals = $request->user()->canPerform('approve-reversals') || $request->user()->canPerform('override-maker-checker');
        $today = CarbonImmutable::today();
        $session = DrawerSession::with(['drawer.branch', 'denominations'])->where('user_id', auth()->id())->where('status', 'open')->latest()->first();
        $branchId = $session?->drawer?->branch_id ?? $request->user()->branch_id;
        $lookupScope = $request->string('lookup_scope')->toString();
        $accountSearch = $request->string('account_search')->trim()->toString();
        $transactionSearch = $request->string('transaction_search')->trim()->toString();
        $selectedAccountTypeId = Str::startsWith($lookupScope, 'account_type:')
            ? (int) Str::after($lookupScope, 'account_type:')
            : $request->integer('account_type_id');
        $isGeneralLedgerLookup = $lookupScope === 'general_ledger';
        $shouldSearchClientAccounts = $accountSearch !== '' && ! $isGeneralLedgerLookup;
        $shouldSearchGeneralLedger = $accountSearch !== '' && $isGeneralLedgerLookup;
        $account = $request->account_number
            ? $this->clientAccountQuery($request, $branchId, $organizationId)
                ->with(['client', 'accountType', 'branch', 'loan'])
                ->where('account_number', $request->account_number)
                ->when($selectedAccountTypeId, fn ($query, int $typeId) => $query->where('account_type_id', $typeId))
                ->where(fn (Builder $query): Builder => $this->availableClientAccount($query))
                ->first()
            : null;
        $accountResults = $this->clientAccountQuery($request, $branchId, $organizationId)
            ->with(['client', 'accountType', 'branch', 'loan'])
            ->when($selectedAccountTypeId, fn (Builder $query, int $typeId): Builder => $query->where('account_type_id', $typeId))
            ->where(fn (Builder $query): Builder => $this->availableClientAccount($query))
            ->when($accountSearch, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->whereAny(['account_number', 'account_name', 'status'], 'like', "%{$search}%")
                        ->orWhereHas('client', function (Builder $query) use ($search): void {
                            $query->whereAny(['client_number', 'first_name', 'middle_name', 'last_name', 'business_name', 'phone', 'alternative_phone'], 'like', "%{$search}%");
                        });
                });
            })
            ->when(! $shouldSearchClientAccounts, fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->orderBy('account_number')
            ->limit(50)
            ->get();
        $selectedChartAccount = $request->integer('chart_account_id')
            ? ChartAccount::query()
                ->when($branchId, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $branchId)->orWhereNull('branch_id')))
                ->whereKey($request->integer('chart_account_id'))
                ->first()
            : null;
        $todayMovements = $session
            ? DrawerMovement::query()
                ->where('drawer_session_id', $session->id)
                ->whereDate('created_at', $today->toDateString())
                ->get()
            : collect();
        $cashIn = (float) $todayMovements->where('type', 'cash_in')->sum('amount');
        $cashOut = (float) $todayMovements->where('type', 'cash_out')->sum('amount');
        $dueTodayPayload = $dueRepayments->build($request->user(), $today, (int) $branchId);
        $dueToday = $dueTodayPayload['demands']->take(8)->values();
        $drawers = TellerDrawer::query()
            ->withoutGlobalScope('tenant')
            ->with(['branch', 'assignedUser'])
            ->when($organizationId, fn (Builder $query, int $organizationId): Builder => $this->scopeDrawersToOrganization($query, $organizationId))
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()) && $request->user()->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $request->user()->branch_id))
            ->when($isCashier, fn (Builder $query): Builder => $query->where('assigned_user_id', $request->user()->id))
            ->when(! $canOpenForTellers && ! $isCashier, function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('assigned_user_id', auth()->id())
                        ->orWhereNull('assigned_user_id');
                });
            })
            ->orderBy('branch_id')
            ->orderBy('code')
            ->get();
        $branches = Branch::query()
            ->withoutGlobalScope('tenant')
            ->where('is_active', true)
            ->when($organizationId, fn (Builder $query, int $organizationId): Builder => $query->where('organization_id', $organizationId))
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), fn (Builder $query): Builder => $query->where('id', $request->user()->branch_id))
            ->orderBy('name')
            ->get();
        $recentMovements = $session
            ? DrawerMovement::query()
                ->with('session.drawer')
                ->where('drawer_session_id', $session->id)
                ->whereDate('created_at', $today->toDateString())
                ->latest()
                ->limit(50)
                ->get()
            : collect();
        $recentMovementReferences = $recentMovements->pluck('reference')->filter()->unique()->values();
        $recentTransactions = $this->transactionHistoryQuery($request)
            ->with([
                'account.client',
                'drawerSession.drawer',
                'journalEntries.chartAccount',
                'type',
                'reversalRequests' => fn ($query) => $query->where('status', 'pending')->latest(),
            ])
            ->when($session, function (Builder $query, DrawerSession $session) use ($recentMovementReferences): Builder {
                return $query->where(function (Builder $query) use ($session, $recentMovementReferences): void {
                    $query->where('drawer_session_id', $session->id);

                    if ($recentMovementReferences->isNotEmpty()) {
                        $query->orWhereIn('reference', $recentMovementReferences);
                    }
                });
            })
            ->when(! $session, fn (Builder $query): Builder => $query->where('user_id', $request->user()->id))
            ->when($isCashier, fn (Builder $query): Builder => $query->whereDate('posted_at', $today->toDateString()))
            ->when($isCashier, function (Builder $query) use ($request, $session, $recentMovementReferences): Builder {
                return $query->where(function (Builder $query) use ($request, $session, $recentMovementReferences): void {
                    $query->where('user_id', $request->user()->id);

                    if ($session) {
                        $query->orWhere('drawer_session_id', $session->id);
                    }

                    if ($recentMovementReferences->isNotEmpty()) {
                        $query->orWhereIn('reference', $recentMovementReferences);
                    }
                });
            })
            ->when($transactionSearch !== '', fn (Builder $query): Builder => $this->searchPostedTransactions($query, $transactionSearch))
            ->latest('posted_at')
            ->limit(50)
            ->get();

        return view('teller.index', [
            'session' => $session,
            'drawer' => $session?->drawer,
            'drawers' => $drawers,
            'branches' => $branches,
            'branchesWithoutDrawers' => $branches->reject(fn (Branch $branch): bool => $drawers->contains('branch_id', $branch->id)),
            'openDrawerSessions' => DrawerSession::query()
                ->with(['drawer.branch', 'user'])
                ->where('status', 'open')
                ->whereHas('drawer', function (Builder $query) use ($organizationId, $request): void {
                    $query
                        ->when($organizationId, fn (Builder $query, int $organizationId): Builder => $this->scopeDrawersToOrganization($query, $organizationId))
                        ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()) && $request->user()->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $request->user()->branch_id));
                })
                ->orderBy('id')
                ->get(),
            'tellers' => $canOpenForTellers
                ? User::query()
                    ->with(['branch', 'role'])
                    ->when($organizationId, fn (Builder $query, int $organizationId): Builder => $query->where('organization_id', $organizationId))
                    ->when(! $organizationId, fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
                    ->where('status', 'active')
                    ->whereHas('role', fn ($query) => $query->where('slug', 'teller-cashier'))
                    ->orderBy('name')
                    ->get()
                : collect(),
            'canOpenForTellers' => $canOpenForTellers,
            'canApproveReversals' => $canApproveReversals,
            'accountTypes' => AccountType::where('is_active', true)->get(),
            'chartAccounts' => ChartAccount::query()
                ->when($branchId, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $branchId)->orWhereNull('branch_id')))
                ->where('is_active', true)
                ->where('is_cash_account', false)
                ->where(fn (Builder $query): Builder => $query->whereNull('drawer_direction')->orWhere('drawer_direction', '!=', 'none'))
                ->whereIn('type', ['expense', 'income', 'asset', 'liability'])
                ->when($accountSearch, function (Builder $query, string $search): void {
                    $query->whereAny(['code', 'name', 'type'], 'like', "%{$search}%");
                })
                ->when(! $shouldSearchGeneralLedger, fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
                ->orderBy('type')
                ->orderBy('name')
                ->limit(25)
                ->get(),
            'transactionTypes' => TransactionType::where('is_active', true)->get(),
            'chargeModes' => LedgerChargeService::modes(),
            'account' => $account,
            'accountResults' => $accountResults,
            'selectedChartAccount' => $selectedChartAccount,
            'lookupScope' => $lookupScope,
            'selectedAccountTypeId' => $selectedAccountTypeId,
            'isGeneralLedgerLookup' => $isGeneralLedgerLookup,
            'hasLookupSearch' => $accountSearch !== '',
            'recentMovements' => $recentMovements,
            'recentTransactions' => $recentTransactions,
            'transactionSearch' => $transactionSearch,
            'isCashier' => $isCashier,
            'cashierDrawerCountEnabled' => $cashierDrawerCountEnabled,
            'pendingReversalRequests' => $canApproveReversals
                ? MakerCheckerRequest::query()
                    ->with(['requestedBy', 'branch', 'subject'])
                    ->where('action', 'transaction_reversal')
                    ->where('status', 'pending')
                    ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), fn (Builder $query): Builder => $query->where('branch_id', $request->user()->branch_id))
                    ->latest()
                    ->limit(10)
                    ->get()
                : collect(),
            'cashIn' => $cashIn,
            'cashOut' => $cashOut,
            'netCashMovement' => $cashIn - $cashOut,
            'dueToday' => $dueToday,
            'dueTodayAmount' => (float) ($dueTodayPayload['summary']['total_amount'] ?? 0),
            'expectedBalance' => (float) ($session?->opening_balance ?? 0) + $cashIn - $cashOut,
            'receiptTransaction' => $this->receiptTransaction($request),
            'ugxDenominations' => CurrencyDenomination::ugxDenominations(),
            'currentDrawerReport' => $session ? $this->drawerEndOfDayReport($session) : null,
            'closedDrawerReport' => $this->closedDrawerReport($request),
        ]);
    }

    private function searchPostedTransactions(Builder $query, string $search): Builder
    {
        $likeSearch = "%{$search}%";

        return $query->where(function (Builder $query) use ($likeSearch): void {
            $query->whereAny(['description', 'reference', 'cheque_number', 'status'], 'like', $likeSearch)
                ->orWhereHas('account', function (Builder $query) use ($likeSearch): void {
                    $query->whereAny(['account_number', 'account_name'], 'like', $likeSearch);
                })
                ->orWhereHas('account.client', function (Builder $query) use ($likeSearch): void {
                    $query->whereAny(['client_number', 'first_name', 'middle_name', 'last_name', 'business_name', 'phone', 'alternative_phone'], 'like', $likeSearch);
                })
                ->orWhereHas('type', function (Builder $query) use ($likeSearch): void {
                    $query->whereAny(['name', 'code'], 'like', $likeSearch);
                })
                ->orWhereHas('journalEntries', function (Builder $query) use ($likeSearch): void {
                    $query->whereAny(['description', 'reference', 'memo', 'account_name'], 'like', $likeSearch)
                        ->orWhereHas('chartAccount', function (Builder $query) use ($likeSearch): void {
                            $query->whereAny(['code', 'name'], 'like', $likeSearch);
                        });
                });
        });
    }

    public function postManagedDrawer(Request $request, TransactionPostingService $posting): RedirectResponse
    {
        abort_unless($this->canManageLedgerAccounts($request), 403);

        $data = $request->validate([
            'drawer_session_id' => ['required', 'exists:drawer_sessions,id'],
            'chart_account_id' => ['required', 'exists:chart_accounts,id'],
            'drawer_direction' => ['required', Rule::in(['cash_in', 'cash_out'])],
            'amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'charges' => ['nullable', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'charge_mode' => ['nullable', Rule::in(array_keys(LedgerChargeService::modes()))],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $session = DrawerSession::query()->with('drawer.branch')->findOrFail($data['drawer_session_id']);
        $chartAccount = ChartAccount::query()->findOrFail($data['chart_account_id']);

        try {
            $this->authorizeDrawerBranch($request, $session->drawer->branch);

            if ((int) $chartAccount->organization_id !== (int) $session->drawer->branch->organization_id
                || ($chartAccount->branch_id !== null && (int) $chartAccount->branch_id !== (int) $session->drawer->branch_id)) {
                throw new InvalidArgumentException('Select an organization-level ledger or a ledger from the same branch as the drawer.');
            }

            $entryType = $this->ledgerEntryType($chartAccount, $data['drawer_direction']);

            if (! $entryType) {
                throw new InvalidArgumentException('Select whether this posting adds to or takes from the drawer.');
            }

            $posting->postGeneralLedgerCash($chartAccount, $session, $entryType, (float) $data['amount'], $request->user(), $data['description'] ?? null, CarbonImmutable::parse($data['transaction_date']), (float) ($data['charges'] ?? 0), LedgerChargeService::normalizeMode($data['charge_mode'] ?? null));
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['managed_drawer_posting' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Drawer ledger posting completed.');
    }

    public function storeDrawer(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($this->canOpenForTellers($request), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:40', 'unique:teller_drawers,code'],
            'assigned_user_id' => ['nullable', 'exists:users,id'],
        ]);

        $branch = Branch::query()->findOrFail($data['branch_id']);

        try {
            $this->authorizeDrawerBranch($request, $branch);
            $this->assertAssignedTellerBelongsToBranch($data['assigned_user_id'] ?? null, $branch);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['drawer_management' => $exception->getMessage()])->withInput();
        }

        $drawer = TellerDrawer::create([
            'branch_id' => $branch->id,
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'name' => $data['name'],
            'code' => $data['code'],
            'currency' => 'UGX',
            'current_balance' => 0,
            'status' => 'closed',
        ]);

        $auditLogger->record('drawer.created', $drawer, $drawer->toArray());

        return back()->with('status', 'Drawer created.');
    }

    public function updateDrawer(Request $request, TellerDrawer $drawer, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($this->canOpenForTellers($request), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:40', Rule::unique('teller_drawers', 'code')->ignore($drawer->id)],
            'currency' => ['required', 'string', 'max:10'],
            'assigned_user_id' => ['nullable', 'exists:users,id'],
        ]);

        $branch = Branch::query()->findOrFail($data['branch_id']);
        $old = $drawer->only(['branch_id', 'assigned_user_id', 'name', 'code', 'currency']);

        try {
            $this->authorizeDrawerBranch($request, $drawer->branch);
            $this->authorizeDrawerBranch($request, $branch);
            $this->assertBranchCanChange($drawer, $branch);
            $this->assertAssignmentCanChange($drawer, $data['assigned_user_id'] ?? null);
            $this->assertAssignedTellerBelongsToBranch($data['assigned_user_id'] ?? null, $branch);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['drawer_management' => $exception->getMessage()])->withInput();
        }

        $drawer->update([
            'branch_id' => $branch->id,
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'name' => $data['name'],
            'code' => $data['code'],
            'currency' => strtoupper($data['currency']),
        ]);

        $auditLogger->record('drawer.updated', $drawer, [
            'old' => $old,
            'new' => $drawer->fresh()->only(['branch_id', 'assigned_user_id', 'name', 'code', 'currency']),
        ]);

        return back()->with('status', 'Drawer updated.');
    }

    public function open(Request $request, TellerService $tellers, FinancialControlService $financialControls, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'teller_drawer_id' => ['required', 'exists:teller_drawers,id'],
            'teller_user_id' => ['nullable', 'exists:users,id'],
            'opening_balance' => ['required', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
        ]);

        try {
            $drawer = TellerDrawer::query()
                ->withoutGlobalScope('tenant')
                ->with('branch')
                ->find($data['teller_drawer_id']);

            if (! $drawer) {
                throw new InvalidArgumentException('The selected drawer was not found. Refresh the page and choose an available branch drawer.');
            }

            if ($request->user()->isTellerCashier() && (int) $drawer->assigned_user_id !== (int) $request->user()->id) {
                throw new InvalidArgumentException('You can only open the teller drawer assigned to you.');
            }

            $this->authorizeDrawerBranch($request, $drawer->branch);
            $financialControls->ensureFinancialDayOpen($request->user(), (int) $drawer->branch_id, today());
            $teller = $this->resolveOpeningTeller($request, $data['teller_user_id'] ?? null);
            $this->assertAssignedTellerBelongsToBranch($teller->id, $drawer->branch);
            $openingBalance = $request->user()->isTellerCashier() ? (float) $drawer->current_balance : (float) $data['opening_balance'];
            $session = $tellers->openDrawer($drawer, $teller, $openingBalance, $request->user());
            $auditLogger->record('drawer.opened', $session, $session->toArray() + ['opened_by' => $request->user()->id]);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['opening_balance' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Drawer opened.');
    }

    public function post(Request $request, TransactionPostingService $posting): RedirectResponse
    {
        abort_if($request->user()->isLoanOfficer() || $request->user()->isLoanRecoveryOfficer(), 403);

        $data = $request->validate([
            'account_scope' => ['required', 'in:client_account,general_ledger'],
            'account_number' => ['nullable', 'required_if:account_scope,client_account', 'exists:accounts,account_number'],
            'chart_account_id' => ['nullable', 'required_if:account_scope,general_ledger', 'exists:chart_accounts,id'],
            'gl_entry_type' => ['nullable', 'in:debit,credit'],
            'transaction_type_id' => ['nullable', 'required_if:account_scope,client_account', 'exists:transaction_types,id'],
            'amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'charges' => ['nullable', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'charge_mode' => ['nullable', Rule::in(array_keys(LedgerChargeService::modes()))],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'cheque_number' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
        ]);

        $postedAt = CarbonImmutable::parse($data['transaction_date']);
        $submissionGuardKey = null;

        try {
            $session = DrawerSession::query()
                ->with('drawer')
                ->where('user_id', auth()->id())
                ->where('status', 'open')
                ->latest()
                ->first();

            if (! $session) {
                throw new InvalidArgumentException('Open your teller drawer before posting a transaction.');
            }

            if (! $session->drawer) {
                throw new InvalidArgumentException('Your open teller drawer is no longer available. Close and reopen your drawer before posting a transaction.');
            }

            if ($data['account_scope'] === 'general_ledger') {
                $chartAccount = ChartAccount::query()
                    ->whereKey($data['chart_account_id'])
                    ->where(function (Builder $query) use ($session): void {
                        $query->where('branch_id', $session->drawer->branch_id)->orWhereNull('branch_id');
                    })
                    ->first();

                if (! $chartAccount) {
                    throw new InvalidArgumentException('The selected ledger account was not found for this organization or drawer branch.');
                }

                $entryType = $this->ledgerEntryType($chartAccount, $data['gl_entry_type'] ?? null);

                if (! $entryType) {
                    throw new InvalidArgumentException('Select whether the money is coming in or going out.');
                }

                $submissionGuardKey = $this->acquireSubmissionGuard($request, 'post', [
                    'scope' => 'general_ledger',
                    'drawer_session_id' => $session->id,
                    'chart_account_id' => $chartAccount->id,
                    'entry_type' => $entryType,
                    'amount' => (float) $data['amount'],
                    'charges' => (float) ($data['charges'] ?? 0),
                    'charge_mode' => LedgerChargeService::normalizeMode($data['charge_mode'] ?? null),
                    'transaction_date' => $postedAt->toDateString(),
                    'description' => $data['description'] ?? null,
                ]);

                $journal = $posting->postGeneralLedgerCash($chartAccount, $session, $entryType, (float) $data['amount'], auth()->user(), $data['description'] ?? null, $postedAt, (float) ($data['charges'] ?? 0), LedgerChargeService::normalizeMode($data['charge_mode'] ?? null));

                return redirect()
                    ->route('teller.index', [
                        'lookup_scope' => 'general_ledger',
                        'chart_account_id' => $chartAccount->id,
                    ])
                    ->with('status', 'General ledger transaction posted.')
                    ->with('receipt_transaction_id', $journal->transaction_id);
            }

            $account = $this->clientAccountQuery($request, (int) $session->drawer->branch_id, (int) $session->drawer->organization_id)
                ->with('accountType')
                ->where('account_number', $data['account_number'])
                ->first();
            $type = TransactionType::query()->find($data['transaction_type_id']);

            if (! $account) {
                throw new InvalidArgumentException('The selected account was not found.');
            }

            if ((int) $account->branch_id !== (int) $session->drawer->branch_id
                && ($account->accountType?->category !== 'savings' || ! $request->user()->canPerform('transact-savings-across-branches'))) {
                throw new InvalidArgumentException('This account belongs to a different branch from your open drawer.');
            }

            if (! $type) {
                throw new InvalidArgumentException('The selected transaction type was not found.');
            }

            if ($type->code === 'loan_repayment') {
                abort_unless($request->user()->canPerform('post-repayments'), 403);
            } else {
                abort_unless($request->user()->canPerform('post-transactions'), 403);
            }

            $submissionGuardKey = $this->acquireSubmissionGuard($request, 'post', [
                'scope' => 'client_account',
                'drawer_session_id' => $session->id,
                'account_id' => $account->id,
                'transaction_type_id' => $type->id,
                'amount' => (float) $data['amount'],
                'charges' => (float) ($data['charges'] ?? 0),
                'transaction_date' => $postedAt->toDateString(),
                'cheque_number' => $data['cheque_number'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            $transaction = $posting->postCash($account, $session, $type, (float) $data['amount'], auth()->user(), $data['description'] ?? null, $data['cheque_number'] ?? null, $postedAt, (float) ($data['charges'] ?? 0));
        } catch (InvalidArgumentException $exception) {
            if ($submissionGuardKey) {
                $this->releaseSubmissionGuard($submissionGuardKey);
            }

            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('teller.index', [
                'lookup_scope' => 'client_account',
                'account_number' => $account->account_number,
                'account_type_id' => $account->account_type_id,
            ])
            ->with('status', 'Transaction posted.')
            ->with('receipt_transaction_id', $transaction->id);
    }

    public function close(Request $request, FinancialControlService $financialControls): RedirectResponse
    {
        abort_unless(! $request->user()->isTellerCashier(), 403, 'Cashiers cannot close drawers. Ask a drawer administrator to close it.');

        $data = $request->validate([
            'closing_balance' => ['nullable', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $session = DrawerSession::query()->where('user_id', auth()->id())->where('status', 'open')->latest()->first();

            if (! $session) {
                throw new InvalidArgumentException('Open your teller drawer before closing it.');
            }

            if ($request->user()->isTellerCashier() && ! $this->cashierDrawerCountEnabled($request)) {
                $data['denominations'] = [];
            }

            [$closingBalance, $denominations] = $this->closingBalanceFromRequest($data);
            $closedSession = $financialControls->closeDrawer($session, $request->user(), $closingBalance, $denominations);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['closing_balance' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('teller.index', ['closed_session_id' => $closedSession->id])
            ->with('status', 'Drawer closed. End-of-day report generated.');
    }

    public function transfer(StoreDrawerTransferRequest $request, DrawerTransferService $transfers): RedirectResponse
    {
        $data = $request->validated();
        $sourceSession = DrawerSession::query()->with('drawer.branch')->findOrFail($data['source_drawer_session_id']);
        $destinationSession = DrawerSession::query()->with('drawer.branch')->findOrFail($data['destination_drawer_session_id']);

        $this->authorizeDrawerBranch($request, $sourceSession->drawer->branch);
        $this->authorizeDrawerBranch($request, $destinationSession->drawer->branch);

        $submissionGuardKey = null;

        try {
            $postedAt = CarbonImmutable::parse($data['transaction_date']);
            $submissionGuardKey = $this->acquireSubmissionGuard($request, 'transfer', [
                'source_drawer_session_id' => $sourceSession->id,
                'destination_drawer_session_id' => $destinationSession->id,
                'amount' => (float) $data['amount'],
                'charges' => (float) ($data['charges'] ?? 0),
                'transaction_date' => $postedAt->toDateString(),
                'description' => $data['description'] ?? null,
            ]);

            $transaction = $transfers->transfer(
                $sourceSession,
                $destinationSession,
                (float) $data['amount'],
                (float) ($data['charges'] ?? 0),
                $request->user(),
                $data['description'] ?? null,
                $postedAt,
            );
        } catch (InvalidArgumentException $exception) {
            if ($submissionGuardKey) {
                $this->releaseSubmissionGuard($submissionGuardKey);
            }

            return back()->withErrors(['drawer_transfer' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Drawer transfer '.$transaction->reference.' completed.');
    }

    public function closeDrawer(Request $request, TellerDrawer $drawer, FinancialControlService $financialControls): RedirectResponse
    {
        abort_unless($this->canOpenForTellers($request), 403);

        $data = $request->validate(['closing_balance' => ['required', 'numeric', 'min:0', CurrencyDenomination::validationRule()]]);

        try {
            $this->authorizeDrawerBranch($request, $drawer->branch);
            $session = $drawer->sessions()->where('status', 'open')->latest()->first();

            if (! $session) {
                throw new InvalidArgumentException('This drawer does not have an open session.');
            }

            $financialControls->closeDrawer($session, $request->user(), (float) $data['closing_balance']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['drawer_management' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Drawer closed.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function closedDrawerReport(Request $request): ?array
    {
        $closedSessionId = $request->integer('closed_session_id');

        if (! $closedSessionId) {
            return null;
        }

        $session = DrawerSession::query()
            ->with(['drawer.branch', 'user', 'denominations'])
            ->find($closedSessionId);

        if (! $session) {
            return null;
        }

        if ($request->user()->isTellerCashier() && (int) $session->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        $this->authorizeDrawerBranch($request, $session->drawer->branch);

        return $this->drawerEndOfDayReport($session);
    }

    /**
     * @return array<string, mixed>
     */
    private function drawerEndOfDayReport(DrawerSession $session): array
    {
        $session->loadMissing(['drawer.branch', 'user', 'denominations']);
        $movements = DrawerMovement::query()
            ->where('drawer_session_id', $session->id)
            ->orderBy('created_at')
            ->get();
        $denominationRows = $this->denominationRows($session);
        $hasClosingDenominations = $session->denominations->where('context', 'closing')->isNotEmpty();
        $countedCash = (float) collect($denominationRows)->sum('total');

        if (! $hasClosingDenominations && $session->status === 'closed') {
            $countedCash = (float) $session->closing_balance;
        }

        $expectedCash = (float) ($session->expected_balance ?? $session->drawer?->current_balance ?? 0);

        return [
            'session' => $session,
            'business_date' => CarbonImmutable::parse($session->closed_at ?? $session->opened_at ?? today())->toDateString(),
            'cash_in' => (float) $movements->where('type', 'cash_in')->sum('amount'),
            'cash_out' => (float) $movements->where('type', 'cash_out')->sum('amount'),
            'net_cash' => (float) $movements->where('type', 'cash_in')->sum('amount') - (float) $movements->where('type', 'cash_out')->sum('amount'),
            'expected_cash' => $expectedCash,
            'counted_cash' => $countedCash,
            'variance' => (float) ($session->variance ?? ($countedCash - $expectedCash)),
            'denominations' => $denominationRows,
            'has_closing_denominations' => $hasClosingDenominations,
            'ledger_rows' => $this->drawerLedgerRows($session),
        ];
    }

    /**
     * @return array<int, array{denomination: int, label: string, quantity: int, total: float}>
     */
    private function denominationRows(DrawerSession $session): array
    {
        $saved = $session->denominations->where('context', 'closing')->keyBy('denomination');

        return collect(CurrencyDenomination::ugxDenominations())
            ->map(function (string $label, int $denomination) use ($saved): array {
                $row = $saved->get($denomination);
                $quantity = (int) ($row?->quantity ?? 0);

                return [
                    'denomination' => $denomination,
                    'label' => $label,
                    'quantity' => $quantity,
                    'total' => (float) ($row?->total ?? ($denomination * $quantity)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function drawerLedgerRows(DrawerSession $session): array
    {
        $entries = JournalEntry::query()
            ->with(['lines.chartAccount', 'transaction.type'])
            ->where('status', 'posted')
            ->whereHas('transaction', fn (Builder $query): Builder => $query->where('drawer_session_id', $session->id))
            ->get();

        $ledgerLines = $entries
            ->flatMap(fn (JournalEntry $entry) => $entry->lines->map(function ($line) use ($entry): ?array {
                $account = $line->chartAccount;

                if (! $account || $account->is_cash_account || $account->drawer_direction === 'none') {
                    return null;
                }

                $intoDrawer = (float) $line->credit;
                $outOfDrawer = (float) $line->debit;

                if ($intoDrawer <= 0 && $outOfDrawer <= 0) {
                    return null;
                }

                return [
                    'account' => $account,
                    'reference' => $entry->reference,
                    'into_drawer' => $intoDrawer,
                    'out_of_drawer' => $outOfDrawer,
                ];
            })->filter())
            ->groupBy(fn (array $row): int => (int) $row['account']->id);

        $statement = app(LedgerAccountStatementService::class);
        $from = CarbonImmutable::parse('1900-01-01');
        $to = CarbonImmutable::today();

        return $ledgerLines
            ->map(function ($rows) use ($statement, $from, $to, $session): array {
                $account = $rows->first()['account'];
                $intoDrawer = (float) $rows->sum('into_drawer');
                $outOfDrawer = (float) $rows->sum('out_of_drawer');
                $branchId = $account->branch_id ?: $session->drawer?->branch_id;
                $statementPayload = $statement->build($account, $from, $to, $branchId);

                return [
                    'account' => $account,
                    'transaction_count' => $rows->count(),
                    'into_drawer' => $intoDrawer,
                    'out_of_drawer' => $outOfDrawer,
                    'net_drawer_movement' => $intoDrawer - $outOfDrawer,
                    'ledger_balance' => (float) ($statementPayload['closing_balance'] ?? 0),
                ];
            })
            ->sortBy(fn (array $row): string => $row['account']->name)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: float, 1: array<int|string, mixed>}
     */
    private function closingBalanceFromRequest(array $data): array
    {
        $denominations = $data['denominations'] ?? null;

        $filledDenominations = is_array($denominations)
            ? array_filter($denominations, fn (mixed $quantity): bool => $quantity !== null && $quantity !== '')
            : [];

        if ($filledDenominations !== []) {
            $total = collect(CurrencyDenomination::ugxDenominations())
                ->sum(fn (string $label, int $denomination): int => $denomination * (int) max(0, (int) ($denominations[$denomination] ?? $denominations[(string) $denomination] ?? 0)));

            return [(float) $total, $denominations];
        }

        if (! array_key_exists('closing_balance', $data) || $data['closing_balance'] === null || $data['closing_balance'] === '') {
            throw new InvalidArgumentException('Enter the closing balance or count the drawer denominations.');
        }

        return [(float) $data['closing_balance'], []];
    }

    public function destroyDrawer(Request $request, TellerDrawer $drawer, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($this->canOpenForTellers($request), 403);

        $this->authorizeDrawerBranch($request, $drawer->branch);

        if ($drawer->status === 'open') {
            return back()->withErrors(['drawer_management' => 'Close the drawer before deleting it.']);
        }

        if ($drawer->sessions()->exists()) {
            return back()->withErrors(['drawer_management' => 'This drawer has session history and cannot be deleted. Keep it closed instead.']);
        }

        $auditLogger->record('drawer.deleted', $drawer, $drawer->toArray());
        $drawer->delete();

        return back()->with('status', 'Drawer deleted.');
    }

    private function resolveOpeningTeller(Request $request, ?int $tellerUserId): User
    {
        $canOpenForTellers = $this->canOpenForTellers($request);

        if ($canOpenForTellers && ! $tellerUserId) {
            throw new InvalidArgumentException('Select the teller or cashier to open this drawer for.');
        }

        if (! $tellerUserId) {
            return $request->user();
        }

        if (! $canOpenForTellers) {
            throw new InvalidArgumentException('You cannot open drawers for another teller.');
        }

        $teller = User::query()
            ->with(['branch', 'role'])
            ->whereKey($tellerUserId)
            ->when($this->activeOrganizationId($request), fn (Builder $query, int $organizationId): Builder => $query->where('organization_id', $organizationId))
            ->where('status', 'active')
            ->first();

        if (! $teller) {
            throw new InvalidArgumentException('The selected teller/cashier was not found.');
        }

        if ($teller->role?->slug !== 'teller-cashier') {
            throw new InvalidArgumentException('Select an active teller/cashier.');
        }

        return $teller;
    }

    private function authorizeDrawerBranch(Request $request, Branch $branch): void
    {
        abort_unless($request->user()->canAccessBranch($branch->id, $branch->organization_id), 403);
    }

    private function authorizeLedgerAccountAccess(Request $request, ChartAccount $chartAccount): void
    {
        abort_unless($request->user()->canAccessBranch($chartAccount->branch_id, $chartAccount->organization_id), 403);
    }

    private function canOpenForTellers(Request $request): bool
    {
        return $request->user()->hasRole('super-admin')
            || $request->user()->hasRole('organization-admin')
            || $request->user()->canPerform('manage-organization')
            || ($request->user()->canPerform('manage-drawers') && $request->user()->canPerform('manage-users'));
    }

    private function cashierDrawerCountEnabled(Request $request): bool
    {
        return filter_var(
            SystemSetting::value('cashier_drawer_count_enabled', '0', $this->activeOrganizationId($request)),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    private function activeOrganizationId(Request $request): ?int
    {
        return app(TenantContext::class)->id() ?: $request->user()->organization_id;
    }

    private function scopeDrawersToOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where(function (Builder $query) use ($organizationId): void {
            $query->where('organization_id', $organizationId)
                ->orWhereHas('branch', fn (Builder $query): Builder => $query->where('organization_id', $organizationId));
        });
    }

    private function canManageLedgerAccounts(Request $request): bool
    {
        return $request->user()->canPerform('manage-accounting')
            || ($request->user()->canPerform('manage-drawers') && $request->user()->canPerform('manage-users'));
    }

    /**
     * @return Builder<Account>
     */
    private function clientAccountQuery(Request $request, ?int $branchId, ?int $organizationId): Builder
    {
        $canTransactAcrossBranches = $request->user()->canPerform('transact-savings-across-branches');
        $query = $canTransactAcrossBranches
            ? Account::query()->withoutGlobalScope('tenant')
            : Account::query();

        return $query
            ->when($organizationId, fn (Builder $query, int $organizationId): Builder => $query->where('organization_id', $organizationId))
            ->when($branchId, function (Builder $query, int $branchId) use ($canTransactAcrossBranches): void {
                $query->where(function (Builder $query) use ($branchId, $canTransactAcrossBranches): void {
                    $query->where('branch_id', $branchId);

                    if ($canTransactAcrossBranches) {
                        $query->orWhereIn('account_type_id', AccountType::query()
                            ->withoutGlobalScope('tenant')
                            ->where('category', 'savings')
                            ->select('id'));
                    }
                });
            });
    }

    private function availableClientAccount(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereHas('accountType', fn (Builder $query): Builder => $query->where('category', '!=', 'loan'))
                ->orWhereHas('loan', fn (Builder $query): Builder => $query->forOperationalListing());
        });
    }

    private function receiptTransaction(Request $request): ?Transaction
    {
        $transactionId = (int) session('receipt_transaction_id');

        if (! $transactionId) {
            return null;
        }

        return $this->transactionHistoryQuery($request)
            ->with(['account.client', 'journalEntries.chartAccount', 'type', 'user'])
            ->whereKey($transactionId)
            ->first();
    }

    /**
     * @return Builder<Transaction>
     */
    private function transactionHistoryQuery(Request $request): Builder
    {
        if (! $request->user()->canPerform('transact-savings-across-branches')) {
            return Transaction::query();
        }

        return Transaction::query()
            ->withoutGlobalScope('tenant')
            ->when($this->activeOrganizationId($request), fn (Builder $query, int $organizationId): Builder => $query->where('organization_id', $organizationId));
    }

    private function ledgerEntryType(ChartAccount $chartAccount, ?string $requestedDirection): ?string
    {
        if (in_array($chartAccount->drawer_direction, ['cash_in', 'cash_out'], true)) {
            return $chartAccount->drawerEntryType($chartAccount->drawer_direction);
        }

        if (in_array($requestedDirection, ['debit', 'credit'], true)) {
            return $requestedDirection;
        }

        if (in_array($requestedDirection, ['cash_in', 'cash_out'], true)) {
            return $chartAccount->drawerEntryType($requestedDirection);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function acquireSubmissionGuard(Request $request, string $action, array $payload): string
    {
        $key = 'teller-submission:'.$request->user()->id.':'.$action.':'.sha1(json_encode($this->normaliseGuardPayload($payload), JSON_THROW_ON_ERROR));

        if (! Cache::add($key, true, now()->addSeconds(15))) {
            throw new InvalidArgumentException('This transaction request is already being processed. Refresh the page before posting it again.');
        }

        return $key;
    }

    private function releaseSubmissionGuard(string $key): void
    {
        Cache::forget($key);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normaliseGuardPayload(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normaliseGuardPayload($value);
            }
        }

        return $payload;
    }

    private function assertAssignedTellerBelongsToBranch(?int $tellerUserId, Branch $branch): void
    {
        if (! $tellerUserId) {
            return;
        }

        $teller = User::query()->find($tellerUserId);

        if (! $teller) {
            throw new InvalidArgumentException('The selected teller/cashier was not found.');
        }

        if ($teller->branch_id && (int) $teller->branch_id !== (int) $branch->id) {
            throw new InvalidArgumentException('Select a teller/cashier from the drawer branch.');
        }
    }

    private function assertBranchCanChange(TellerDrawer $drawer, Branch $branch): void
    {
        if ((int) $drawer->branch_id === (int) $branch->id) {
            return;
        }

        if ($drawer->status === 'open') {
            throw new InvalidArgumentException('Close the drawer before moving it to another branch.');
        }

        if ($drawer->sessions()->exists()) {
            throw new InvalidArgumentException('This drawer has session history, so its branch cannot be changed. Create a new drawer for the other branch instead.');
        }
    }

    private function assertAssignmentCanChange(TellerDrawer $drawer, ?int $assignedUserId): void
    {
        if ((int) $drawer->assigned_user_id === (int) $assignedUserId) {
            return;
        }

        if ($drawer->status === 'open' || $drawer->sessions()->where('status', 'open')->exists()) {
            throw new InvalidArgumentException('Close the drawer before assigning it to another teller/cashier.');
        }
    }
}
