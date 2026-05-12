<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientKycController extends Controller
{
    public function update(Request $request, Client $client, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('verifyKyc', $client);

        $data = $request->validate([
            'kyc_status' => ['required', 'in:pending,verified,rejected'],
            'risk_rating' => ['nullable', 'in:low,medium,high'],
            'kyc_checklist' => ['array'],
            'kyc_checklist.*' => ['string', 'max:80'],
        ]);

        $old = $client->toArray();
        $client->update([
            'kyc_status' => $data['kyc_status'],
            'risk_rating' => $data['risk_rating'] ?? null,
            'kyc_documents' => collect(['identity_confirmed', 'address_confirmed', 'photo_captured', 'signature_captured', 'next_of_kin_confirmed'])
                ->mapWithKeys(fn (string $key): array => [$key => in_array($key, $data['kyc_checklist'] ?? [], true)])
                ->all(),
        ]);

        $auditLogger->record('client.kyc_updated', $client, $client->fresh()->toArray(), $old);

        return redirect()->route('clients.show', $client)->with('status', 'KYC status updated.');
    }
}
