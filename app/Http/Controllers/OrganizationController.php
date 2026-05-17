<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function editCurrent(Request $request): View
    {
        return $this->edit($this->currentOrganization($request));
    }

    public function updateCurrent(UpdateOrganizationRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
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
