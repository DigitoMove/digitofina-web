<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Support\ApiSecurity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LedgerAccountController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($this->canManageLedgerAccounts($request), 403);

        $search = $request->string('search')->trim()->toString();

        $accounts = ChartAccount::query()
            ->with(['branch', 'parent'])
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), fn (Builder $query): Builder => $query->where('branch_id', $request->user()->branch_id))
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
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), fn (Builder $query): Builder => $query->whereKey($request->user()->branch_id))
            ->orderBy('name')
            ->get();

        $parentAccounts = ChartAccount::query()
            ->with('branch')
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), fn (Builder $query): Builder => $query->where('branch_id', $request->user()->branch_id))
            ->orderBy('branch_id')
            ->orderBy('code')
            ->get();

        return view('ledger-accounts.index', [
            'accounts' => $accounts,
            'branches' => $branches,
            'parentAccounts' => $parentAccounts,
            'search' => $search,
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($this->canManageLedgerAccounts($request), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'suffix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'normal_balance' => ['required', Rule::in(['debit', 'credit'])],
            'drawer_direction' => ['required', Rule::in(['cash_in', 'cash_out', 'both'])],
        ]);

        $branch = Branch::query()->findOrFail($data['branch_id']);
        $this->authorizeLedgerBranch($request, $branch);

        $code = Str::upper($branch->code.'-'.$data['suffix']);

        if (ChartAccount::query()->where('code', $code)->exists()) {
            return back()->withErrors(['ledger_account' => 'A ledger account with code '.$code.' already exists.'])->withInput();
        }

        $chartAccount = ChartAccount::create([
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'code' => $code,
            'name' => $data['name'],
            'type' => $data['type'],
            'normal_balance' => $data['normal_balance'],
            'drawer_direction' => $data['drawer_direction'],
            'is_cash_account' => false,
            'is_system' => false,
            'is_active' => true,
        ]);

        $auditLogger->record('ledger_account.created', $chartAccount, $chartAccount->toArray());

        return redirect()->route('ledger-accounts.index')->with('status', 'Ledger account created.');
    }

    public function update(Request $request, ChartAccount $chartAccount, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($this->canManageLedgerAccounts($request), 403);
        $this->authorizeLedgerAccountAccess($request, $chartAccount);
        $request->merge(['code' => Str::upper((string) $request->input('code'))]);

        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'parent_id' => ['nullable', 'exists:chart_accounts,id'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('chart_accounts', 'code')->ignore($chartAccount->id)],
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'normal_balance' => ['required', Rule::in(['debit', 'credit'])],
            'drawer_direction' => ['nullable', Rule::in(['cash_in', 'cash_out', 'both'])],
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

        return redirect()->route('ledger-accounts.index', $request->only('search'))->with('status', 'Ledger account updated.');
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

    private function activeOrganizationId(Request $request): ?int
    {
        return app(TenantContext::class)->id() ?: $request->user()->organization_id;
    }
}
