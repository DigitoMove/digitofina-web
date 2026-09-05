<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Support\ApiSecurity;
use App\Support\RoleCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $currentUser = $request->user();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'organization_id' => ['nullable', 'integer', $this->organizationFilterRule($request)],
            'role_id' => ['nullable', 'integer', $this->roleFilterRule($request)],
            'branch_id' => ['nullable', 'integer', $this->branchFilterRule($request)],
            'status' => ['nullable', 'in:active,suspended,disabled'],
        ]);
        $selectedOrganizationId = $currentUser->hasGlobalOrganizationAccess()
            ? (filled($filters['organization_id'] ?? null) ? (int) $filters['organization_id'] : app(TenantContext::class)->id())
            : null;
        $selectedBranchId = filled($filters['branch_id'] ?? null) ? (int) $filters['branch_id'] : null;

        $users = User::query()
            ->staff()
            ->with(['branch.organization', 'organization', 'role'])
            ->when(! $currentUser->hasRole('super-admin'), fn ($query) => $query->whereDoesntHave('role', fn ($query) => $query->where('slug', 'super-admin')))
            ->when($currentUser->hasGlobalOrganizationAccess() && $selectedOrganizationId, fn ($query) => $query->where('organization_id', $selectedOrganizationId))
            ->when(! $currentUser->hasGlobalOrganizationAccess() && $currentUser->organization_id, fn ($query) => $query->where('organization_id', $currentUser->organization_id))
            ->when(! ApiSecurity::hasGlobalBranchAccess($currentUser) && $currentUser->branch_id, fn ($query) => $query->where('branch_id', $currentUser->branch_id))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->whereAny(['name', 'email', 'phone', 'position'], 'like', "%{$search}%"))
            ->when($filters['role_id'] ?? null, fn ($query, int|string $roleId) => $query->where('role_id', $roleId))
            ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'roles' => Role::query()
                ->where('slug', '!=', RoleCatalog::MEMBER_SLUG)
                ->when(! $currentUser->hasRole('super-admin'), fn ($query) => $query->where('slug', '!=', 'super-admin'))
                ->when($currentUser->hasGlobalOrganizationAccess() && $selectedOrganizationId, fn ($query) => $query->where(function ($query) use ($selectedOrganizationId): void {
                    $query->where('organization_id', $selectedOrganizationId)->orWhereNull('organization_id');
                }))
                ->when(! $currentUser->hasGlobalOrganizationAccess() && $currentUser->organization_id, fn ($query) => $query->where(function ($query) use ($currentUser): void {
                    $query->where('organization_id', $currentUser->organization_id)->orWhereNull('organization_id');
                }))
                ->orderBy('name')
                ->get(),
            'branches' => Branch::query()
                ->with('organization')
                ->when($currentUser->hasGlobalOrganizationAccess() && $selectedOrganizationId, fn ($query) => $query->where('organization_id', $selectedOrganizationId))
                ->when(! $currentUser->hasGlobalOrganizationAccess() && $currentUser->organization_id, fn ($query) => $query->where('organization_id', $currentUser->organization_id))
                ->when(! ApiSecurity::hasGlobalBranchAccess($currentUser) && $currentUser->branch_id, fn ($query) => $query->whereKey($currentUser->branch_id))
                ->orderBy('name')
                ->get(),
            'organizations' => $currentUser->hasGlobalOrganizationAccess()
                ? Organization::query()->orderBy('name')->get()
                : Organization::query()->whereKey($currentUser->organization_id)->get(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', User::class);

        return view('users.create', $this->formData(new User([
            'organization_id' => $request->integer('organization_id') ?: app(TenantContext::class)->id(),
        ])));
    }

    public function store(StoreUserRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validated();

        DB::transaction(function () use ($data, $request, $auditLogger): void {
            $role = Role::query()->findOrFail($data['role_id']);
            $data = $this->normalizeScope($data, $role, $request->user());

            $user = User::create(Arr::except($data, ['password']) + [
                'password' => Hash::make($data['password']),
                'is_active' => $data['status'] === 'active',
            ]);

            $auditLogger->record('user.created', $user, $user->only(['id', 'name', 'email', 'role_id', 'branch_id', 'status']));
        });

        return redirect()->route('users.index')->with('status', 'Staff user created.');
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);

        return view('users.edit', $this->formData($user));
    }

    public function update(UpdateUserRequest $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('update', $user);

        $old = $user->only(['name', 'email', 'phone', 'position', 'role_id', 'branch_id', 'status']);
        $data = $request->validated();
        $role = Role::query()->findOrFail($data['role_id']);
        $data = $this->normalizeScope($data, $role, $request->user());
        $payload = Arr::except($data, ['password', 'change_password']);
        $payload['is_active'] = $data['status'] === 'active';

        if ($request->boolean('change_password') && filled($data['password'] ?? null)) {
            $payload['password'] = Hash::make($data['password']);
        }

        $user->update($payload);
        $auditLogger->record('user.updated', $user, $user->fresh()->only(['name', 'email', 'phone', 'position', 'role_id', 'branch_id', 'status']), $old);

        return redirect()->route('users.index')->with('status', 'Staff user updated.');
    }

    /**
     * @return array{user: User, roles: Collection<int, Role>, branches: Collection<int, Branch>, organizations: Collection<int, Organization>}
     */
    private function formData(User $user): array
    {
        $currentUser = request()->user();
        $requestedOrganizationId = request()->old('organization_id', $user->organization_id);
        $targetOrganizationId = $currentUser->hasGlobalOrganizationAccess()
            ? ($requestedOrganizationId ? (int) $requestedOrganizationId : null)
            : ($currentUser->organization_id ? (int) $currentUser->organization_id : null);

        return [
            'user' => $user,
            'roles' => Role::query()
                ->assignableToOrganization($targetOrganizationId)
                ->orderBy('name')
                ->get(),
            'branches' => Branch::query()
                ->when($currentUser->hasGlobalOrganizationAccess(), fn ($query) => $query->withoutGlobalScope('tenant'))
                ->with('organization')
                ->where('status', 'active')
                ->when($targetOrganizationId !== null, fn ($query) => $query->where('organization_id', $targetOrganizationId))
                ->when($targetOrganizationId === null, fn ($query) => $query->whereRaw('1 = 0'))
                ->when(! ApiSecurity::hasGlobalBranchAccess($currentUser) && $currentUser->branch_id, fn ($query) => $query->whereKey($currentUser->branch_id))
                ->orderBy('name')
                ->get(),
            'organizations' => $currentUser->hasGlobalOrganizationAccess()
                ? Organization::query()->orderBy('name')->get()
                : Organization::query()->whereKey($currentUser->organization_id)->get(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeScope(array $data, Role $role, User $actor): array
    {
        $data['organization_id'] = $actor->hasGlobalOrganizationAccess()
            ? ($data['organization_id'] ?? null)
            : $actor->organization_id;

        if ($role->slug === 'organization-admin') {
            $data['branch_id'] = null;
        }

        return $data;
    }

    private function organizationFilterRule(Request $request): Exists
    {
        $currentUser = $request->user();

        return Rule::exists('organizations', 'id')->where(function ($query) use ($currentUser): void {
            if (! $currentUser->hasGlobalOrganizationAccess() && $currentUser->organization_id) {
                $query->where('id', $currentUser->organization_id);
            }
        });
    }

    private function roleFilterRule(Request $request): Exists
    {
        $currentUser = $request->user();

        return Rule::exists('roles', 'id')->where(function ($query) use ($currentUser, $request): void {
            if (! $currentUser->hasRole('super-admin')) {
                $query->where('slug', '!=', 'super-admin');
            }

            if ($currentUser->hasGlobalOrganizationAccess() && $request->filled('organization_id')) {
                $query->where(function ($query) use ($request): void {
                    $query->where('organization_id', $request->integer('organization_id'))->orWhereNull('organization_id');
                });
            }

            if (! $currentUser->hasGlobalOrganizationAccess() && $currentUser->organization_id) {
                $query->where(function ($query) use ($currentUser): void {
                    $query->where('organization_id', $currentUser->organization_id)->orWhereNull('organization_id');
                });
            }
        });
    }

    private function branchFilterRule(Request $request): Exists
    {
        $currentUser = $request->user();

        return Rule::exists('branches', 'id')->where(function ($query) use ($currentUser, $request): void {
            if ($currentUser->hasGlobalOrganizationAccess() && $request->filled('organization_id')) {
                $query->where('organization_id', $request->integer('organization_id'));
            }

            if (! $currentUser->hasGlobalOrganizationAccess() && $currentUser->organization_id) {
                $query->where('organization_id', $currentUser->organization_id);
            }

            if (! ApiSecurity::hasGlobalBranchAccess($currentUser) && $currentUser->branch_id) {
                $query->where('id', $currentUser->branch_id);
            }
        });
    }
}
