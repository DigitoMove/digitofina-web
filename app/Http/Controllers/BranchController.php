<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BranchGeneralLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Branch::class);

        $branches = Branch::query()
            ->with(['manager', 'organization', 'users'])
            ->when(! $request->user()->hasGlobalOrganizationAccess() && $request->user()->organization_id, fn ($query) => $query->where('organization_id', $request->user()->organization_id))
            ->when($request->search, fn ($query, string $search) => $query->whereAny(['code', 'name', 'phone', 'address'], 'like', "%{$search}%"))
            ->latest()
            ->paginate(15);

        return view('branches.index', compact('branches'));
    }

    public function create(): View
    {
        Gate::authorize('create', Branch::class);

        return view('branches.create', ['branch' => new Branch, 'managers' => $this->managerOptions(), 'organizations' => $this->organizationOptions()]);
    }

    public function store(StoreBranchRequest $request, AuditLogger $auditLogger, BranchGeneralLedgerService $generalLedger): RedirectResponse
    {
        Gate::authorize('create', Branch::class);

        $branch = Branch::create($request->validated() + [
            'organization_id' => $request->integer('organization_id') ?: $request->user()->organization_id,
            'is_active' => $request->string('status')->toString() === 'active',
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $auditLogger->record('branch.created', $branch, $branch->toArray());
        $generalLedger->ensureDefaults($branch);

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
}
