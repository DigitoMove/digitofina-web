<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationNylonPayCredentialRequest;
use App\Models\NylonPayCredential;
use App\Services\AuditLogger;
use App\Services\NylonPayClient;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OrganizationNylonPaySettingController extends Controller
{
    public function edit(TenantContext $tenant, NylonPayClient $nylonPay): View
    {
        $organization = $tenant->organization() ?: request()->user()->organization;
        abort_unless($organization, 422, 'Select an organization first.');
        $credential = NylonPayCredential::query()->where('scope_key', "organization:{$organization->id}")->first();

        return view('nylon-pay-settings.edit', [
            'organization' => $organization,
            'credential' => $credential,
            'configured' => $nylonPay->isConfigured($organization->id),
            'webhookUrl' => $credential?->webhook_token ? route('api.v1.webhooks.organization-nylon-pay', $credential->webhook_token) : null,
        ]);
    }

    public function update(UpdateOrganizationNylonPayCredentialRequest $request, TenantContext $tenant, AuditLogger $auditLogger): RedirectResponse
    {
        $organization = $tenant->organization() ?: $request->user()->organization;
        abort_unless($organization, 422, 'Select an organization first.');
        abort_unless($request->user()->hasRole('super-admin') || (int) $request->user()->organization_id === (int) $organization->id, 403);

        $credential = NylonPayCredential::firstOrNew(['scope_key' => "organization:{$organization->id}"], [
            'organization_id' => $organization->id,
            'profile' => 'collections',
            'webhook_token' => Str::random(48),
        ]);
        $data = collect($request->validated())
            ->reject(fn (mixed $value, string $key): bool => in_array($key, ['api_key', 'api_secret', 'webhook_secret'], true) && blank($value))
            ->all();
        $credential->fill($data + ['updated_by' => $request->user()->id])->save();
        $auditLogger->record('nylon_pay.organization_credentials_updated', $credential, ['organization_id' => $organization->id]);

        return back()->with('status', 'Organization Nylon Pay credentials saved securely.');
    }
}
