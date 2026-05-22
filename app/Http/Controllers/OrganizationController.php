<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationRequest;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Package;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ModuleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);

        $organizations = Organization::query()
            ->with('package')
            ->withCount(['branches', 'users'])
            ->when($request->search, fn (Builder $query, string $search): Builder => $query->whereAny(['name', 'slug', 'email', 'phone'], 'like', "%{$search}%"))
            ->when($request->status, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('organizations.index', [
            'organizations' => $organizations,
            'packages' => Package::query()->where('status', 'active')->orderBy('name')->get(),
            'selectedOrganizationId' => $request->session()->get('super_admin_organization_id'),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);

        return view('organizations.create', [
            'organization' => new Organization([
                'status' => 'active',
                'currency' => 'UGX',
                'timezone' => 'Africa/Kampala',
            ]),
            'packages' => Package::query()->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger, ModuleService $modules): RedirectResponse
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);

        $data = $request->validate($this->organizationRules());

        $organization = Organization::create($data);
        $modules->syncCatalog();
        $modules->syncDefaults($organization);
        SystemSetting::ensureForOrganization($organization);

        if (! empty($data['package_id'])) {
            $modules->assignPackage($organization, Package::findOrFail($data['package_id']), $request->user());
        }

        $auditLogger->record('organization.created', $organization, $organization->toArray(), actor: $request->user());

        return redirect()->route('organizations.index')->with('status', 'Organization created.');
    }

    public function switch(Request $request, ?Organization $organization = null): RedirectResponse
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);

        if ($organization?->exists) {
            $request->session()->put('super_admin_organization_id', $organization->id);

            return back()->with('status', "Switched into {$organization->name}.");
        }

        $request->session()->forget('super_admin_organization_id');

        return back()->with('status', 'Returned to global Super Admin view.');
    }

    public function users(Request $request, Organization $organization): View
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);

        return view('organizations.users', [
            'organization' => $organization,
            'users' => User::query()
                ->with(['role', 'branch'])
                ->where('organization_id', $organization->id)
                ->orderBy('name')
                ->paginate(20),
            'availableUsers' => User::query()
                ->whereNull('organization_id')
                ->whereDoesntHave('role', fn (Builder $query): Builder => $query->where('slug', 'super-admin'))
                ->orderBy('name')
                ->get(),
            'branches' => Branch::query()->where('organization_id', $organization->id)->orderBy('name')->get(),
        ]);
    }

    public function assignUser(Request $request, Organization $organization, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('organization_id', $organization->id)],
        ]);

        $user = User::query()->findOrFail($data['user_id']);
        $old = $user->only(['organization_id', 'branch_id']);
        $user->update([
            'organization_id' => $organization->id,
            'branch_id' => $data['branch_id'] ?? null,
        ]);

        $auditLogger->record('organization.user_assigned', $user, $user->fresh()->only(['organization_id', 'branch_id']), $old, $request->user());

        return back()->with('status', 'User assigned to organization.');
    }

    public function editCurrent(Request $request): View
    {
        if ($request->user()->hasRole('super-admin')) {
            abort(404);
        }

        return $this->edit($this->currentOrganization($request));
    }

    public function updateCurrent(UpdateOrganizationRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        if ($request->user()->hasRole('super-admin')) {
            abort(404);
        }

        return $this->update($request, $this->currentOrganization($request), $auditLogger);
    }

    public function edit(Organization $organization): View
    {
        Gate::authorize('update', $organization);

        return view('organization.edit', compact('organization'));
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('update', $organization);

        $old = $organization->toArray();
        $organization->update($request->validated());
        $auditLogger->record('organization.updated', $organization, $organization->fresh()->toArray(), $old);

        return redirect()->route('organization.edit', $organization)->with('status', 'Organization profile updated.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function organizationRules(?Organization $organization = null): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'slug' => ['required', 'string', 'max:191', Rule::unique('organizations', 'slug')->ignore($organization)],
            'logo' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string'],
            'currency' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'string', 'max:80'],
            'package_id' => ['nullable', 'exists:packages,id'],
            'status' => ['required', 'in:active,suspended,disabled,inactive'],
        ];
    }

    private function currentOrganization(Request $request): Organization
    {
        $user = $request->user();
        $organization = $user?->organization
            ?: $user?->branch?->organization
            ?: Organization::query()->orderBy('id')->first();

        abort_unless($organization, 404);

        return $organization;
    }
}
