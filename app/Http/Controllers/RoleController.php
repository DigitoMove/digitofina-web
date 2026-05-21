<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Services\ModuleService;
use App\Support\ModuleCatalog;
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

    public function create(ModuleService $modules): View
    {
        Gate::authorize('create', Role::class);

        return view('roles.create', ['role' => new Role, 'permissions' => $this->permissions($modules)]);
    }

    public function store(StoreRoleRequest $request, AuditLogger $auditLogger, ModuleService $modules): RedirectResponse
    {
        Gate::authorize('create', Role::class);

        $role = Role::create($request->safe()->except('permissions') + [
            'organization_id' => $request->user()->organization_id,
        ]);
        $role->permissions()->sync($this->enabledPermissionIds($request->validated('permissions', []), $request->user(), $modules));
        $auditLogger->record('role.created', $role, $role->load('permissions')->toArray());

        return redirect()->route('roles.index')->with('status', 'Role created.');
    }

    public function edit(Role $role, ModuleService $modules): View
    {
        Gate::authorize('update', $role);

        return view('roles.edit', ['role' => $role->load('permissions'), 'permissions' => $this->permissions($modules)]);
    }

    public function update(UpdateRoleRequest $request, Role $role, AuditLogger $auditLogger, ModuleService $modules): RedirectResponse
    {
        Gate::authorize('update', $role);

        $old = $role->load('permissions')->toArray();
        $role->update($request->safe()->except('permissions'));
        $role->permissions()->sync($this->enabledPermissionIds($request->validated('permissions', []), $request->user(), $modules));
        $auditLogger->record('role.updated', $role, $role->fresh()->load('permissions')->toArray(), $old);

        return redirect()->route('roles.index')->with('status', 'Role updated.');
    }

    private function permissions(ModuleService $modules)
    {
        $enabled = $this->enabledModulesFor(auth()->user(), $modules);

        return Permission::orderBy('slug')
            ->get()
            ->filter(fn (Permission $permission): bool => $this->permissionModuleIsEnabled($permission, $enabled))
            ->groupBy(fn (Permission $permission): string => ModuleCatalog::moduleForPermission($permission->slug) ?: str($permission->slug)->before('-')->toString());
    }

    /**
     * @param  array<int, int|string>  $permissionIds
     * @return array<int, int>
     */
    private function enabledPermissionIds(array $permissionIds, $user, ModuleService $modules): array
    {
        $enabled = $this->enabledModulesFor($user, $modules);

        return Permission::query()
            ->whereIn('id', $permissionIds)
            ->get()
            ->filter(fn (Permission $permission): bool => $this->permissionModuleIsEnabled($permission, $enabled))
            ->pluck('id')
            ->all();
    }

    private function permissionModuleIsEnabled(Permission $permission, array $enabled): bool
    {
        $module = ModuleCatalog::moduleForPermission($permission->slug);

        return $module === null || in_array($module, $enabled, true);
    }

    /**
     * @return array<int, string>
     */
    private function enabledModulesFor($user, ModuleService $modules): array
    {
        if (! $user || $user->hasRole('super-admin') || ! $user->organization) {
            return collect(ModuleCatalog::modules())->pluck('slug')->all();
        }

        return $modules->enabledSlugs($user->organization);
    }
}
