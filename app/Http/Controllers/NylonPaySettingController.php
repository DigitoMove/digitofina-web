<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateNylonPayCredentialRequest;
use App\Models\NylonPayCredential;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class NylonPaySettingController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(UpdateNylonPayCredentialRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $credential = NylonPayCredential::firstOrNew(['scope_key' => 'platform:default'], ['profile' => 'default']);
        $old = ['configured' => $credential->exists, 'base_url' => $credential->base_url];
        $this->requireUnreadableSecretsToBeReplaced($credential, $request->validated());
        $data = collect($request->validated())
            ->reject(fn (mixed $value, string $key): bool => in_array($key, ['api_key', 'api_secret', 'webhook_secret'], true) && blank($value))
            ->all();
        $credential = $credential->saveCredentials($data + ['updated_by' => $request->user()->id]);
        $auditLogger->record('nylon_pay.credentials_updated', $credential, [
            'configured' => filled($credential->api_key) && filled($credential->api_secret) && filled($credential->webhook_secret),
            'base_url' => $credential->base_url,
        ], $old, $request->user());

        return redirect()->route('commercial-quotes.index')->with('status', 'Nylon Pay credentials updated securely.');
    }

    /** @param array<string, mixed> $data */
    private function requireUnreadableSecretsToBeReplaced(NylonPayCredential $credential, array $data): void
    {
        $errors = [];

        foreach ($credential->unreadableSecretAttributes() as $attribute) {
            if (blank($data[$attribute] ?? null)) {
                $errors[$attribute] = 'This saved secret cannot be decrypted. Enter it again to replace it.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
