<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NylonPayCredential;
use App\Models\PaymentRequest;
use App\Services\NylonPayClient;
use App\Services\OnlinePaymentPostingService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganizationNylonPayWebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        Request $request,
        string $webhookToken,
        NylonPayClient $nylonPay,
        OnlinePaymentPostingService $posting,
        TenantContext $tenant,
    ): JsonResponse {
        $credential = NylonPayCredential::query()->with('organization')->where('webhook_token', $webhookToken)->firstOrFail();
        $rawPayload = $request->getContent();

        if (! $nylonPay->verifyWebhook($rawPayload, (string) $request->header('X-Nylon-Signature'), $credential)) {
            return response()->json(['received' => false, 'message' => 'Invalid Nylon Pay signature.'], 401);
        }

        $payload = json_decode($rawPayload, true);
        abort_unless(is_array($payload), 422, 'Invalid webhook payload.');
        $details = $this->paymentDetails($payload);
        $paymentRequest = PaymentRequest::query()
            ->with('payable')
            ->where('organization_id', $credential->organization_id)
            ->where('reference', $details['reference'])
            ->first();

        if (! $paymentRequest) {
            return response()->json(['received' => true, 'matched' => false]);
        }

        abort_unless((int) $paymentRequest->payable?->organization_id === (int) $credential->organization_id, 422, 'Payment tenant mismatch.');
        abort_unless($details['currency'] === $paymentRequest->currency && round((float) $details['amount'], 2) === round((float) $paymentRequest->amount, 2), 422, 'Payment amount or currency mismatch.');
        $tenant->set($credential->organization, true);
        $paymentRequest->update([
            'external_reference' => $details['provider_reference'] ?: $paymentRequest->external_reference,
            'status' => $details['status'],
            'metadata' => ($paymentRequest->metadata ?? []) + ['nylon_webhook_event' => $payload['event'] ?? null],
        ]);
        $posted = $posting->postIfSuccessful($paymentRequest->fresh(), $details);

        return response()->json(['received' => true, 'matched' => true, 'posted' => $posted !== null]);
    }

    /** @param array<string, mixed> $payload
     * @return array{reference: string, provider_reference: ?string, status: string, amount: mixed, currency: string}
     */
    private function paymentDetails(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : $data;
        $status = Str::lower((string) ($transaction['status'] ?? $data['status'] ?? $payload['event'] ?? ''));

        return [
            'reference' => (string) ($transaction['reference'] ?? $data['reference'] ?? $payload['reference'] ?? ''),
            'provider_reference' => isset($transaction['id']) ? (string) $transaction['id'] : (isset($data['id']) ? (string) $data['id'] : null),
            'status' => in_array($status, ['success', 'successful', 'paid', 'completed'], true) ? 'successful' : $status,
            'amount' => $transaction['amount'] ?? $data['amount'] ?? null,
            'currency' => Str::upper((string) ($transaction['currency'] ?? $data['currency'] ?? '')),
        ];
    }
}
