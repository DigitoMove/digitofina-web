<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateNylonPayCredentialRequest;
use App\Models\NylonPayCredential;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;

class NylonPaySettingController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(UpdateNylonPayCredentialRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $credential = NylonPayCredential::firstOrNew(['profile' => 'default']);
        $old = ['configured' => $credential->exists, 'base_url' => $credential->base_url];
        $data = collect($request->validated())
            ->reject(fn (mixed $value, string $key): bool => in_array($key, ['api_key', 'api_secret', 'webhook_secret'], true) && blank($value))
            ->all();
        $credential->fill($data + ['updated_by' => $request->user()->id])->save();
        $auditLogger->record('nylon_pay.credentials_updated', $credential, [
            'configured' => filled($credential->api_key) && filled($credential->api_secret) && filled($credential->webhook_secret),
            'base_url' => $credential->base_url,
        ], $old, $request->user());

        return redirect()->route('commercial-quotes.index')->with('status', 'Nylon Pay credentials updated securely.');
    }
}
