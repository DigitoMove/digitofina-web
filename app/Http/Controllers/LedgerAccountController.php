<?php

namespace App\Http\Controllers;

use App\Http\Requests\MergeLedgerAccountsRequest;
use App\Http\Requests\StoreLedgerOpeningBalanceRequest;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Services\AuditLogger;
use App\Services\LedgerAccountMergeService;
use App\Services\LedgerAccountStatementService;
use App\Services\LedgerOpeningBalanceService;
use App\Services\TenantContext;
use App\Support\ApiSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class LedgerAccountController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($this->canManageLedgerAccounts($request), 403);

        $search = $request->string('search')->trim()->toString();
        $type = $this->ledgerType($request->string('type')->toString());

        $accounts = ChartAccount::query()
            ->with(['branch.drawers', 'parent', 'mergedInto'])
            ->withCount(['children', 'journalEntries', 'journalLines'])
            ->withSum(['journalLines as posted_line_debit_total' => fn (Builder $query): Builder => $query->whereHas('journalEntry', fn (Builder $query): Builder => $query->where('status', 'posted'))], 'debit')
            ->withSum(['journalLines as posted_line_credit_total' => fn (Builder $query): Builder => $query->whereHas('journalEntry', fn (Builder $query): Builder => $query->where('status', 'posted'))], 'credit')
            ->withSum(['journalEntries as posted_legacy_debit_total' => fn (Builder $query): Builder => $this->legacyLedgerEntryTotals($query, 'debit')], 'amount')
            ->withSum(['journalEntries as posted_legacy_credit_total' => fn (Builder $query): Builder => $this->legacyLedgerEntryTotals($query, 'credit')], 'amount')
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $request->user()->branch_id)->orWhereNull('branch_id')))
            ->when($type, fn (Builder $query, string $type): Builder => $query->where('type', $type))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->whereAny(['code', 'name', 'type', 'normal_balance', 'drawer_direction'], 'like', "%{$search}%")
                        ->orWhereHas('branch', fn (Builder $query): Builder => $query->whereAny(['code', 'name'], 'like', "%{$search}%"))
                        ->orWhereHas('parent', fn (Builder $query): Builder => $query->whereAny(['code', 'name'], 'like', "%{$search}%"));
                });
            })
            ->orderBy('branch_id')
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();

        $branches = Branch::query()
            ->with('drawers')
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), fn (Builder $query): Builder => $query->whereKey($request->user()->branch_id))
            ->orderBy('name')
            ->get();

        $parentAccounts = ChartAccount::query()
            ->with('branch')
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $request->user()->branch_id)->orWhereNull('branch_id')))
            ->orderBy('branch_id')
            ->orderBy('code')
            ->get();

        return view('ledger-accounts.index', [
            'accounts' => $accounts,
            'branches' => $branches,
            'parentAccounts' => $parentAccounts,
            'search' => $search,
            'type' => $type,
            'accountTypes' => $this->accountTypes(),
            'canDeleteLedgerAccounts' => $this->canDeleteLedgerAccounts($request),
            'canCreateGlobalLedgerAccounts' => $this->canMergeLedgerAccounts($request),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($this->canManageLedgerAccounts($request), 403);

        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'suffix' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'normal_balance' => ['required', Rule::in(['debit', 'credit'])],
            'drawer_direction' => ['required', Rule::in(['cash_in', 'cash_out', 'both', 'none'])],
        ]);

        $branch = filled($data['branch_id'] ?? null) ? Branch::query()->findOrFail($data['branch_id']) : null;

        if ($branch) {
            $this->authorizeLedgerBranch($request, $branch);
        } else {
            abort_unless($this->canMergeLedgerAccounts($request), 403);
        }

        $code = Str::upper($branch ? $branch->code.'-'.$data['suffix'] : $data['suffix']);

        if (ChartAccount::query()->where('code', $code)->exists()) {
            return back()->withErrors(['ledger_account' => 'A ledger account with code '.$code.' already exists.'])->withInput();
        }

        $attributes = [
            'organization_id' => $branch?->organization_id ?? $this->activeOrganizationId($request),
            'branch_id' => $branch?->id,
            'code' => $code,
            'name' => $data['name'],
            'type' => $data['type'],
            'normal_balance' => $data['normal_balance'],
            'drawer_direction' => $data['drawer_direction'],
            'is_cash_account' => false,
            'is_system' => false,
            'is_active' => true,
        ];
        $chartAccount = $branch
            ? ChartAccount::query()->create($attributes)
            : ChartAccount::withoutEvents(fn (): ChartAccount => ChartAccount::query()->create($attributes));

        $auditLogger->record('ledger_account.created', $chartAccount, $chartAccount->toArray());

        return redirect()->route('ledger-accounts.index', ['type' => $chartAccount->type])->with('status', 'Ledger account created.');
    }

    public function update(Request $request, ChartAccount $chartAccount, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($this->canManageLedgerAccounts($request), 403);
        $this->authorizeLedgerAccountAccess($request, $chartAccount);

        if ($chartAccount->merged_into_id) {
            return back()->withErrors(['ledger_account' => 'A merged source ledger is retained for audit history and cannot be edited.']);
        }
        $request->merge(['code' => Str::upper((string) $request->input('code'))]);

        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'parent_id' => ['nullable', 'exists:chart_accounts,id'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('chart_accounts', 'code')->ignore($chartAccount->id)],
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'normal_balance' => ['required', Rule::in(['debit', 'credit'])],
            'drawer_direction' => ['nullable', Rule::in(['cash_in', 'cash_out', 'both', 'none'])],
            'is_cash_account' => ['nullable', 'boolean'],
            'is_system' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $branch = filled($data['branch_id'] ?? null)
            ? Branch::query()->findOrFail($data['branch_id'])
            : null;

        if ($branch) {
            $this->authorizeLedgerBranch($request, $branch);
        } elseif (! ApiSecurity::hasGlobalBranchAccess($request->user()) && $request->user()->branch_id) {
            abort(403);
        }

        if (filled($data['parent_id'] ?? null)) {
            if ((int) $data['parent_id'] === (int) $chartAccount->id) {
                return back()->withErrors(['ledger_account' => 'A ledger account cannot be its own parent.'])->withInput();
            }

            $parentAccount = ChartAccount::query()->findOrFail($data['parent_id']);
            $this->authorizeLedgerAccountAccess($request, $parentAccount);
        }

        $oldValues = $chartAccount->only([
            'organization_id',
            'branch_id',
            'parent_id',
            'code',
            'name',
            'type',
            'normal_balance',
            'drawer_direction',
            'is_cash_account',
            'is_system',
            'is_active',
        ]);

        $chartAccount->update([
            'organization_id' => $branch?->organization_id ?? $this->activeOrganizationId($request),
            'branch_id' => $branch?->id,
            'parent_id' => $data['parent_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'normal_balance' => $data['normal_balance'],
            'drawer_direction' => $data['drawer_direction'] ?? null,
            'is_cash_account' => $request->boolean('is_cash_account'),
            'is_system' => $request->boolean('is_system'),
            'is_active' => $request->boolean('is_active'),
        ]);

        $auditLogger->record('ledger_account.updated', $chartAccount, $chartAccount->fresh()->only(array_keys($oldValues)), $oldValues);

        return redirect()->route('ledger-accounts.index', $request->only('search', 'type'))->with('status', 'Ledger account updated.');
    }

    public function destroy(Request $request, ChartAccount $chartAccount, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($this->canDeleteLedgerAccounts($request), 403);
        $this->authorizeLedgerAccountAccess($request, $chartAccount);

        if ($chartAccount->merged_into_id) {
            return back()->withErrors(['ledger_account' => 'Merged source ledgers are retained as audit records and cannot be deleted.']);
        }

        $chartAccount->loadCount(['children', 'journalEntries', 'journalLines']);

        if ($chartAccount->is_system || $chartAccount->is_cash_account) {
            return back()->withErrors(['ledger_account' => 'System and cash ledger accounts cannot be deleted. Deactivate them if they should no longer be used.']);
        }

        if ($chartAccount->children_count > 0) {
            return back()->withErrors(['ledger_account' => 'Delete or move child ledger accounts before deleting this account.']);
        }

        if ($chartAccount->journal_entries_count > 0 || $chartAccount->journal_lines_count > 0) {
            return back()->withErrors(['ledger_account' => 'This ledger account has accounting activity and cannot be deleted. Deactivate it instead.']);
        }

        $auditLogger->record('ledger_account.deleted', $chartAccount, $chartAccount->toArray());
        $chartAccount->delete();

        return back()->with('status', 'Ledger account deleted.');
    }

    public function postOpeningBalance(StoreLedgerOpeningBalanceRequest $request, ChartAccount $chartAccount, LedgerOpeningBalanceService $openingBalances): RedirectResponse
    {
        $this->authorizeLedgerAccountAccess($request, $chartAccount);

        if ($chartAccount->merged_into_id) {
            return back()->withErrors(['opening_balance' => 'Opening balances cannot be posted to a merged source ledger.']);
        }
        $data = $request->validated();

        try {
            $journal = $openingBalances->post(
                $chartAccount,
                (float) $data['amount'],
                $data['entry_type'],
                CarbonImmutable::parse($data['opening_date']),
                $request->user(),
                $data['description'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['opening_balance' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Opening balance posted as '.$journal->reference.'.');
    }

    public function show(Request $request, ChartAccount $chartAccount, LedgerAccountStatementService $statements): View
    {
        abort_unless($this->canManageLedgerAccounts($request), 403);
        $this->authorizeLedgerAccountAccess($request, $chartAccount);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'search' => ['nullable', 'string', 'max:191'],
        ]);
        $from = CarbonImmutable::parse($filters['from'] ?? now()->startOfMonth()->toDateString());
        $to = CarbonImmutable::parse($filters['to'] ?? today()->toDateString());
        $branchId = filled($filters['branch_id'] ?? null) ? (int) $filters['branch_id'] : null;

        if ($branchId) {
            $branch = Branch::query()->findOrFail($branchId);
            $this->authorizeLedgerBranch($request, $branch);
            abort_unless((int) $branch->organization_id === (int) $chartAccount->organization_id, 404);
        }

        $chartAccount->load(['branch', 'mergedInto', 'mergedBy', 'mergedSources.branch']);
        $mergeCandidates = collect();

        if ($this->canMergeLedgerAccounts($request) && $chartAccount->branch_id === null && ! $chartAccount->merged_into_id) {
            $mergeCandidates = ChartAccount::query()
                ->with('branch')
                ->where('organization_id', $chartAccount->organization_id)
                ->whereKeyNot($chartAccount->id)
                ->where('type', $chartAccount->type)
                ->where('normal_balance', $chartAccount->normal_balance)
                ->whereNull('merged_into_id')
                ->where('is_cash_account', false)
                ->where('is_system', false)
                ->whereDoesntHave('children')
                ->orderByRaw('branch_id is null')
                ->orderBy('code')
                ->get();
        }

        return view('ledger-accounts.show', [
            'chartAccount' => $chartAccount,
            'statement' => $statements->build($chartAccount, $from, $to, $branchId, trim((string) ($filters['search'] ?? ''))),
            'branches' => Branch::query()->where('organization_id', $chartAccount->organization_id)->orderBy('name')->get(),
            'branchId' => $branchId,
            'search' => trim((string) ($filters['search'] ?? '')),
            'mergeCandidates' => $mergeCandidates,
            'canMergeLedgerAccounts' => $this->canMergeLedgerAccounts($request),
        ]);
    }

    public function merge(MergeLedgerAccountsRequest $request, ChartAccount $chartAccount, LedgerAccountMergeService $merger): RedirectResponse
    {
        $this->authorizeLedgerAccountAccess($request, $chartAccount);
        $data = $request->validated();

        try {
            $result = $merger->merge($chartAccount, $data['source_account_ids'], $request->user(), $data['reason'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['ledger_merge' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('ledger-accounts.show', $chartAccount)->with('status', "Merged {$result['source_accounts']} ledger account(s) and moved {$result['journal_entries']} journal entries.");
    }

    private function authorizeLedgerBranch(Request $request, Branch $branch): void
    {
        abort_unless($request->user()->canAccessBranch($branch->id, $branch->organization_id), 403);
    }

    private function authorizeLedgerAccountAccess(Request $request, ChartAccount $chartAccount): void
    {
        abort_unless($request->user()->canAccessBranch($chartAccount->branch_id, $chartAccount->organization_id), 403);
    }

    private function canManageLedgerAccounts(Request $request): bool
    {
        return $request->user()->canPerform('manage-accounting')
            || ($request->user()->canPerform('manage-drawers') && $request->user()->canPerform('manage-users'));
    }

    private function canDeleteLedgerAccounts(Request $request): bool
    {
        return $request->user()->hasRole('super-admin')
            || $request->user()->hasRole('organization-admin');
    }

    private function canMergeLedgerAccounts(Request $request): bool
    {
        return $request->user()->hasAnyRole(['super-admin', 'organization-admin']);
    }

    private function activeOrganizationId(Request $request): ?int
    {
        return app(TenantContext::class)->id() ?: $request->user()->organization_id;
    }

    /**
     * @return array<string, string>
     */
    private function accountTypes(): array
    {
        return [
            'expense' => 'Expenses',
            'income' => 'Income',
            'asset' => 'Assets',
            'liability' => 'Liabilities',
            'equity' => 'Capital',
        ];
    }

    private function ledgerType(string $type): ?string
    {
        return array_key_exists($type, $this->accountTypes()) ? $type : null;
    }

    private function legacyLedgerEntryTotals(Builder $query, string $entryType): Builder
    {
        return $query
            ->where('status', 'posted')
            ->where('entry_type', $entryType)
            ->whereDoesntHave('lines', fn (Builder $query): Builder => $query->whereColumn('journal_lines.chart_account_id', 'journal_entries.chart_account_id'));
    }
}
