<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('users.create', $this->formData(new User));
    }

    public function store(StoreUserRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validated();
        $user = User::create(Arr::except($data, ['password']) + [
            'organization_id' => $request->user()->organization_id,
            'password' => Hash::make($data['password']),
            'is_active' => $data['status'] === 'active',
        ]);

        $auditLogger->record('user.created', $user, $user->only(['id', 'name', 'email', 'role_id', 'branch_id', 'status']));

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
     * @return array{user: User, roles: Collection<int, Role>, branches: Collection<int, Branch>}
     */
    private function formData(User $user): array
    {
        return [
            'user' => $user,
            'roles' => Role::orderBy('name')->get(),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ];
    }
}
