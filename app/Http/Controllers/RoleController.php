<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Role::class);

        return view('roles.index', [
            'roles' => Role::withCount(['permissions', 'users'])->orderBy('name')->paginate(15),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Role::class);

        return view('roles.create', ['role' => new Role, 'permissions' => $this->permissions()]);
    }

    public function store(StoreRoleRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('create', Role::class);

        $role = Role::create($request->safe()->except('permissions') + [
            'organization_id' => $request->user()->organization_id,
        ]);
        $role->permissions()->sync($request->validated('permissions', []));
        $auditLogger->record('role.created', $role, $role->load('permissions')->toArray());

        return redirect()->route('roles.index')->with('status', 'Role created.');
    }

    public function edit(Role $role): View
    {
        Gate::authorize('update', $role);

        return view('roles.edit', ['role' => $role->load('permissions'), 'permissions' => $this->permissions()]);
    }

    public function update(UpdateRoleRequest $request, Role $role, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('update', $role);

        $old = $role->load('permissions')->toArray();
        $role->update($request->safe()->except('permissions'));
        $role->permissions()->sync($request->validated('permissions', []));
        $auditLogger->record('role.updated', $role, $role->fresh()->load('permissions')->toArray(), $old);

        return redirect()->route('roles.index')->with('status', 'Role updated.');
    }

    private function permissions()
    {
        return Permission::orderBy('slug')->get()->groupBy(fn (Permission $permission): string => str($permission->slug)->before('-')->toString());
    }
}
