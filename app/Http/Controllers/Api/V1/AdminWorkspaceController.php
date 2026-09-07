<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminRecordResource;
use App\Models\Account;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\CommercialQuote;
use App\Models\InvestmentAccount;
use App\Models\InvestorAccount;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\TellerDrawer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserActivity;
use App\Services\ModuleService;
use App\Services\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWorkspaceController extends Controller
{
    /** @return array<string, array{label: string, group: string, module: ?string}> */
    private function sections(): array
    {
        return [
            'organizations' => ['label' => 'Organizations', 'group' => 'Administration', 'module' => null],
            'users' => ['label' => 'Staff users', 'group' => 'Administration', 'module' => 'users'],
            'branches' => ['label' => 'Branches', 'group' => 'Administration', 'module' => 'users'],
            'roles' => ['label' => 'Roles & permissions', 'group' => 'Administration', 'module' => 'roles'],
            'modules' => ['label' => 'Modules', 'group' => 'Administration', 'module' => null],
            'packages' => ['label' => 'Packages', 'group' => 'Platform', 'module' => null],
            'quotes' => ['label' => 'Billing & quotes', 'group' => 'Administration', 'module' => null],
            'activity' => ['label' => 'System activity', 'group' => 'Administration', 'module' => null],
            'clients' => ['label' => 'Clients', 'group' => 'Clients', 'module' => 'clients'],
            'client-groups' => ['label' => 'Client groups', 'group' => 'Clients', 'module' => 'clients'],
            'loans' => ['label' => 'Loans', 'group' => 'Loans', 'module' => 'loans'],
            'loan-products' => ['label' => 'Loan products', 'group' => 'Loans', 'module' => 'loans'],
            'drawers' => ['label' => 'Teller drawers', 'group' => 'Operations', 'module' => 'teller'],
            'transactions' => ['label' => 'Transaction search', 'group' => 'Operations', 'module' => 'teller'],
            'accounts' => ['label' => 'Accounts register', 'group' => 'Accounts', 'module' => 'savings'],
            'savings-products' => ['label' => 'Savings products', 'group' => 'Accounts', 'module' => 'savings'],
            'ledger-accounts' => ['label' => 'Ledger accounts', 'group' => 'Accounts', 'module' => 'accounting'],
            'investments' => ['label' => 'Investment accounts', 'group' => 'Accounts', 'module' => 'investments'],
        ];
    }

    private function organization(Request $request): ?Organization
    {
        $user = $request->user();
        abort_unless($user->hasAnyRole(['super-admin', 'organization-admin']), 403);
        $data = $request->validate(['organization_id' => ['nullable', 'integer', 'min:1']]);
        if (! $user->hasRole('super-admin')) {
            abort_unless($user->organization_id, 403);
            abort_if(isset($data['organization_id']) && (int) $data['organization_id'] !== (int) $user->organization_id, 403);

            return $user->organization;
        }

        return isset($data['organization_id'])
            ? Organization::findOrFail($data['organization_id'])
            : app(TenantContext::class)->organization();
    }

    private function scoped(string $model, ?Organization $organization): Builder
    {
        return $model::query()->withoutGlobalScope('tenant')
            ->when($organization, fn (Builder $query): Builder => $query->where('organization_id', $organization->id));
    }

    private function allowed(Request $request, string $section, ?Organization $organization): bool
    {
        if (! isset($this->sections()[$section])) {
            return false;
        }
        if ($section === 'packages' && ! $request->user()->hasRole('super-admin')) {
            return false;
        }
        $module = $this->sections()[$section]['module'];

        return ! $module || ! $organization || app(ModuleService::class)->isEnabled($organization, $module);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $scope = fn (string $model): Builder => $this->scoped($model, $organization);
        $metrics = [
            ['label' => 'Organizations', 'value' => Organization::when($organization, fn (Builder $q): Builder => $q->whereKey($organization->id))->count(), 'section' => 'organizations', 'money' => false],
            ['label' => 'Active organizations', 'value' => Organization::where('status', 'active')->when($organization, fn (Builder $q): Builder => $q->whereKey($organization->id))->count(), 'section' => 'organizations', 'money' => false],
            ['label' => 'Suspended / disabled', 'value' => Organization::whereIn('status', ['suspended', 'disabled', 'inactive'])->when($organization, fn (Builder $q): Builder => $q->whereKey($organization->id))->count(), 'section' => 'organizations', 'money' => false],
            ['label' => 'Staff users', 'value' => $scope(User::class)->staff()->where('status', 'active')->count(), 'section' => 'users', 'money' => false],
            ['label' => 'Clients', 'value' => $scope(Client::class)->count(), 'section' => 'clients', 'money' => false],
            ['label' => 'Active branches', 'value' => $scope(Branch::class)->where('status', 'active')->count(), 'section' => 'branches', 'money' => false],
            ['label' => 'Active accounts', 'value' => $scope(Account::class)->where('status', 'active')->count(), 'section' => 'accounts', 'money' => false],
            ['label' => 'Loan portfolio', 'value' => (float) $scope(Loan::class)->whereIn('status', ['approved', 'disbursed', 'active', 'in_arrears', 'restructured'])->sum('principal_balance'), 'section' => 'loans', 'money' => true],
            ['label' => 'Savings balance', 'value' => (float) $scope(SavingsAccount::class)->sum('current_balance'), 'section' => 'accounts', 'money' => true],
            ['label' => 'Investment balance', 'value' => (float) $scope(InvestorAccount::class)->sum('current_investment_balance'), 'section' => 'investments', 'money' => true],
        ];
        $navigation = collect($this->sections())->filter(fn (array $section, string $key): bool => $this->allowed($request, $key, $organization))
            ->map(fn (array $section, string $key): array => ['key' => $key] + $section)->values();
        $metrics = array_values(array_filter($metrics, fn (array $metric): bool => $navigation->contains('key', $metric['section'])));

        return ApiResponse::data([
            'scope' => $organization?->only(['id', 'name', 'currency']),
            'role' => $request->user()->role?->slug,
            'metrics' => $metrics,
            'navigation' => $navigation,
            'recent_organizations' => AdminRecordResource::collection(Organization::query()->with('package')->when($organization, fn (Builder $q): Builder => $q->whereKey($organization->id))->latest()->limit(5)->get())->resolve($request),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    private function records(Request $request, string $section, ?Organization $organization): Builder
    {
        abort_unless(isset($this->sections()[$section]), 404);
        abort_unless($this->allowed($request, $section, $organization), 403, 'This module is unavailable for this organization.');

        return match ($section) {
            'organizations' => Organization::query()->with('package')->when($organization, fn (Builder $q): Builder => $q->whereKey($organization->id)),
            'users' => $this->scoped(User::class, $organization)->staff()->with(['role', 'organization', 'branch']),
            'branches' => $this->scoped(Branch::class, $organization)->with('organization'),
            'roles' => Role::query()->with('permissions')->when($organization, fn (Builder $q): Builder => $q
                ->where('slug', '!=', 'super-admin')
                ->where(fn (Builder $q): Builder => $q->whereNull('organization_id')->orWhere('organization_id', $organization->id)
                    ->orWhereHas('users', fn (Builder $q): Builder => $q->where('organization_id', $organization->id)))),
            'modules' => Module::query()->when($organization, fn (Builder $q): Builder => $q->whereIn('slug', app(ModuleService::class)->enabledSlugs($organization))),
            'packages' => Package::query(),
            'quotes' => $this->scoped(CommercialQuote::class, $organization)->with('organization'),
            'activity' => $this->scoped(UserActivity::class, $organization)->with(['user', 'organization']),
            'clients' => $this->scoped(Client::class, $organization)->with('branch'),
            'client-groups' => $this->scoped(ClientGroup::class, $organization)->with('branch'),
            'loan-products' => $this->scoped(LoanProduct::class, $organization),
            'drawers' => $this->scoped(TellerDrawer::class, $organization)->with('branch'),
            'transactions' => $this->scoped(Transaction::class, $organization)->with(['branch', 'user']),
            'savings-products' => $this->scoped(SavingsProduct::class, $organization),
            'ledger-accounts' => $this->scoped(ChartAccount::class, $organization)->with('branch'),
            'investments' => $this->scoped(InvestmentAccount::class, $organization)->with('branch'),
            'accounts' => $this->scoped(Account::class, $organization)->with('branch'),
            'loans' => $this->scoped(Loan::class, $organization)->with('branch'),
        };
    }

    public function index(Request $request, string $section): JsonResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = $this->records($request, $section, $organization);
        $columns = match ($section) {
            'users' => ['name', 'email', 'phone'],
            'clients' => ['first_name', 'last_name', 'client_number'],
            'accounts' => ['account_number'],
            'investments' => ['investment_number'],
            'transactions' => ['reference'],
            'loans' => ['loan_number'],
            'quotes' => ['quote_number'],
            'activity' => ['device_name', 'ip_address'],
            default => ['name'],
        };
        if (filled($data['search'] ?? null)) {
            $query->where(function (Builder $q) use ($columns, $data): void {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', '%'.$data['search'].'%');
                }
            });
        }

        return ApiResponse::collection(AdminRecordResource::collection($query->latest('id')->paginate(20)), 'Records retrieved.');
    }

    public function show(Request $request, string $section, int $id): JsonResponse
    {
        $organization = $this->organization($request);

        return ApiResponse::resource(new AdminRecordResource($this->records($request, $section, $organization)->findOrFail($id)));
    }
}
