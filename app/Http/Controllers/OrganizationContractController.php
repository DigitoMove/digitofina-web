<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OrganizationContractController extends Controller
{
    public function all(Request $request): View
    {
        $this->authorizeSuperAdmin($request);

        return view('contracts.all', [
            'contracts' => OrganizationContract::query()->with('organization')->latest()->paginate(20),
        ]);
    }

    public function createForAny(Request $request): View
    {
        $this->authorizeSuperAdmin($request);

        return view('contracts.form', [
            'organization' => null,
            'organizations' => Organization::query()->where('status', 'active')->orderBy('name')->get(),
            'modules' => Module::query()->orderBy('name')->get(['slug', 'name']),
            'selectedModules' => [],
            'organizationModuleMap' => Organization::query()->where('status', 'active')->with('modules')->get()->mapWithKeys(fn (Organization $organization): array => [
                $organization->id => $organization->modules->filter(fn (Module $module): bool => (bool) $module->pivot->is_enabled)->pluck('slug')->values()->all(),
            ])->all(),
        ]);
    }

    public function storeForAny(Request $request): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $organization = Organization::query()->findOrFail($request->integer('organization_id'));

        return $this->store($request, $organization);
    }

    public function index(Request $request, Organization $organization): View
    {
        $this->authorizeSuperAdmin($request);

        return view('contracts.index', [
            'organization' => $organization,
            'contracts' => $organization->contracts()->latest('version')->get(),
        ]);
    }

    public function create(Request $request, Organization $organization): View
    {
        $this->authorizeSuperAdmin($request);

        return view('contracts.form', [
            'organization' => $organization,
            'modules' => Module::query()->orderBy('name')->get(['slug', 'name']),
            'selectedModules' => $organization->modules()->wherePivot('is_enabled', true)->pluck('slug')->all(),
            'organizationModuleMap' => [],
        ]);
    }

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $data = $this->validated($request);
        $data['organization_id'] = $organization->id;
        $data['created_by'] = $request->user()->id;
        $data['version'] = ((int) $organization->contracts()->max('version')) + 1;
        $data['title'] = $data['title'] ?: 'Software Services Agreement';
        $data['public_token'] = Str::random(64);
        $data['balance_due'] = max(0, (float) $data['total_fee'] - (float) $data['paid_amount']);
        $data['included_modules'] = is_array($data['included_modules'] ?? null)
            ? implode(', ', array_filter($data['included_modules']))
            : ($data['included_modules'] ?? null);
        $contract = OrganizationContract::create($data);

        return redirect()->route('organizations.contracts.show', [$organization, $contract])->with('status', 'Contract created.');
    }

    public function show(Request $request, Organization $organization, OrganizationContract $contract): View
    {
        $this->authorizeSuperAdmin($request);
        abort_unless($contract->organization_id === $organization->id, 404);

        return view('contracts.show', compact('organization', 'contract'));
    }

    public function edit(Request $request, Organization $organization, OrganizationContract $contract): View
    {
        $this->authorizeSuperAdmin($request);
        abort_unless($contract->organization_id === $organization->id, 404);

        return view('contracts.form', [
            'organization' => $organization,
            'contract' => $contract,
            'modules' => Module::query()->orderBy('name')->get(['slug', 'name']),
            'selectedModules' => collect(explode(',', (string) $contract->included_modules))->map(fn (string $module): string => trim($module))->filter()->values()->all(),
            'organizationModuleMap' => [],
        ]);
    }

    public function update(Request $request, Organization $organization, OrganizationContract $contract): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        abort_unless($contract->organization_id === $organization->id, 404);
        $data = $this->validated($request);
        $data['balance_due'] = max(0, (float) $data['total_fee'] - (float) $data['paid_amount']);
        $data['included_modules'] = is_array($data['included_modules'] ?? null)
            ? implode(', ', array_filter($data['included_modules']))
            : ($data['included_modules'] ?? null);
        $contract->update($data);

        return redirect()->route('organizations.contracts.show', [$organization, $contract])->with('status', 'Contract updated.');
    }

    public function share(Request $request, Organization $organization, OrganizationContract $contract): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        abort_unless($contract->organization_id === $organization->id, 404);
        $contract->update(['status' => 'shared', 'sent_at' => now()]);

        return back()->with('status', 'Contract is ready to share with the customer.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:190'],
            'term_type' => ['required', 'in:fixed_term,perpetual'],
            'term_months' => ['nullable', 'required_if:term_type,fixed_term', 'integer', 'min:1', 'max:120'],
            'total_fee' => ['required', 'numeric', 'min:0'],
            'paid_amount' => ['required', 'numeric', 'min:0'],
            'annual_hosting_fee' => ['required', 'numeric', 'min:0'],
            'included_branches' => ['nullable', 'integer', 'min:0'],
            'included_users' => ['nullable', 'integer', 'min:0'],
            'included_clients' => ['nullable', 'integer', 'min:0'],
            'included_modules' => ['nullable'],
            'review_date' => ['nullable', 'date'],
            'special_terms' => ['nullable', 'string'],
            'provider_responsibilities' => ['nullable', 'string'],
            'customer_responsibilities' => ['nullable', 'string'],
        ]);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super-admin'), 403);
    }
}
