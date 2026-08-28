<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Branch::class);

        $currentUser = $request->user();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'organization_id' => ['nullable', 'integer', $this->organizationFilterRule($request)],
            'status' => ['nullable', 'in:active,inactive,suspended'],
        ]);
        $selectedOrganizationId = $currentUser->hasGlobalOrganizationAccess() && filled($filters['organization_id'] ?? null) ? (int) $filters['organization_id'] : null;

        $branches = Branch::query()
            ->with(['manager', 'organization'])
            ->withCount('users')
            ->when($currentUser->hasGlobalOrganizationAccess() && $selectedOrganizationId, fn ($query) => $query->where('organization_id', $selectedOrganizationId))
            ->when(! $currentUser->hasGlobalOrganizationAccess() && $currentUser->organization_id, fn ($query) => $query->where('organization_id', $currentUser->organization_id))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->whereAny(['code', 'name', 'phone', 'address'], 'like', "%{$search}%"))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('branches.index', [
            'branches' => $branches,
            'organizations' => $this->organizationOptions(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Branch::class);

        return view('branches.create', ['branch' => new Branch, 'managers' => $this->managerOptions(), 'organizations' => $this->organizationOptions()]);
    }

    public function store(StoreBranchRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('create', Branch::class);

        $branch = DB::transaction(fn (): Branch => Branch::create($request->validated() + [
            'organization_id' => $request->integer('organization_id') ?: $request->user()->organization_id,
            'is_active' => $request->string('status')->toString() === 'active',
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        $auditLogger->record('branch.created', $branch, $branch->toArray());

        return redirect()->route('branches.index')->with('status', 'Branch created.');
    }

    public function edit(Branch $branch): View
    {
        Gate::authorize('update', $branch);

        return view('branches.edit', ['branch' => $branch, 'managers' => $this->managerOptions(), 'organizations' => $this->organizationOptions()]);
    }

    public function update(UpdateBranchRequest $request, Branch $branch, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('update', $branch);

        $old = $branch->toArray();
        $data = $request->validated();
        $branch->update($data + [
            'organization_id' => $request->integer('organization_id') ?: $branch->organization_id,
            'is_active' => $data['status'] === 'active',
            'updated_by' => $request->user()->id,
        ]);
        $auditLogger->record('branch.updated', $branch, $branch->fresh()->toArray(), $old);

        return redirect()->route('branches.index')->with('status', 'Branch updated.');
    }

    private function managerOptions()
    {
        return User::query()
            ->where('status', 'active')
            ->when(! request()->user()->hasGlobalOrganizationAccess() && request()->user()->organization_id, fn ($query) => $query->where('organization_id', request()->user()->organization_id))
            ->whereHas('role', fn ($query) => $query->whereIn('slug', ['super-admin', 'organization-admin', 'branch-manager', 'supervisor']))
            ->orderBy('name')
            ->get();
    }

    private function organizationOptions()
    {
        return request()->user()->hasGlobalOrganizationAccess()
            ? Organization::query()->orderBy('name')->get()
            : Organization::query()->whereKey(request()->user()->organization_id)->get();
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
}
