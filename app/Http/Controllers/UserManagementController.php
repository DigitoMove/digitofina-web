<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiSecurity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->with(['branch', 'role'])
            ->when(! $request->user()->hasGlobalOrganizationAccess() && $request->user()->organization_id, fn ($query) => $query->where('organization_id', $request->user()->organization_id))
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()) && $request->user()->branch_id, fn ($query) => $query->where('branch_id', $request->user()->branch_id))
            ->when($request->search, fn ($query, string $search) => $query->whereAny(['name', 'email', 'phone', 'position'], 'like', "%{$search}%"))
            ->when($request->role_id, fn ($query, string $roleId) => $query->where('role_id', $roleId))
            ->when($request->branch_id, fn ($query, string $branchId) => $query->where('branch_id', $branchId))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'roles' => Role::orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', User::class);

        return view('users.create', $this->formData(new User([
            'organization_id' => $request->integer('organization_id') ?: null,
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
        $payload = Arr::except($data, ['password']);
        $payload['is_active'] = $data['status'] === 'active';

        if (filled($data['password'] ?? null)) {
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

        return [
            'user' => $user,
            'roles' => Role::query()
                ->when(! $currentUser->hasGlobalOrganizationAccess() && $currentUser->organization_id, fn ($query) => $query->where(function ($query) use ($currentUser): void {
                    $query->where('organization_id', $currentUser->organization_id)->orWhereNull('organization_id');
                }))
                ->orderBy('name')
                ->get(),
            'branches' => Branch::query()
                ->with('organization')
                ->where('status', 'active')
                ->when(! $currentUser->hasGlobalOrganizationAccess() && $currentUser->organization_id, fn ($query) => $query->where('organization_id', $currentUser->organization_id))
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
        if ($role->slug === 'super-admin') {
            $data['organization_id'] = null;
            $data['branch_id'] = null;

            return $data;
        }

        $data['organization_id'] = $actor->hasGlobalOrganizationAccess()
            ? ($data['organization_id'] ?? null)
            : $actor->organization_id;

        if ($role->slug === 'organization-admin') {
            $data['branch_id'] = null;
        }

        return $data;
    }
}
