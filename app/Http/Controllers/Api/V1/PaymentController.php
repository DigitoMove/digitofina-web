<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentRequestResource;
use App\Models\Loan;
use App\Models\MobileMoneyTransaction;
use App\Models\PaymentCallback;
use App\Models\PaymentProvider;
use App\Models\PaymentRequest;
use App\Models\SavingsAccount;
use App\Services\MobileMoneyReconciliationService;
use App\Services\PaymentGatewayService;
use App\Services\WebhookIngestionService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function initiateCollection(Request $request, PaymentGatewayService $payments): JsonResponse
    {
        abort_unless($request->user()->canPerform('post-transactions') || $request->user()->canPerform('manage-accounts'), 403);

        $data = $request->validate([
            'provider_id' => ['nullable', 'exists:payment_providers,id'],
            'payable_type' => ['required', 'in:savings_account,loan'],
            'payable_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:1'],
            'phone' => ['required', 'string', 'max:40'],
            'metadata' => ['nullable', 'array'],
        ]);

        $provider = isset($data['provider_id'])
            ? PaymentProvider::findOrFail($data['provider_id'])
            : ApiSecurity::scopeTenant(PaymentProvider::query(), $request->user())->where('is_default', true)->where('status', 'active')->firstOrFail();
        ApiSecurity::assertTenantAccess($request->user(), $provider);

        $payable = $this->payable($data['payable_type'], (int) $data['payable_id']);
        ApiSecurity::assertTenantAccess($request->user(), $payable);

        return ApiResponse::resource(
            new PaymentRequestResource($payments->initiateCollection($provider, $payable, (float) $data['amount'], $data['phone'], $data['metadata'] ?? [])->load('provider')),
            'Mobile money collection initiated.',
            201
        );
    }

    public function callback(Request $request, WebhookIngestionService $webhooks): JsonResponse
    {
        $data = $request->validate([
            'provider_code' => ['nullable', 'string'],
            'reference' => ['nullable', 'string'],
            'external_reference' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric'],
            'phone' => ['nullable', 'string'],
        ]);
        $webhookEvent = $webhooks->ingest($request, 'mobile_money', $data['provider_code'] ?? 'mobile-money');

        if ($webhookEvent->status === 'rejected') {
            return ApiResponse::error('Webhook signature verification failed.', [
                'signature' => ['The webhook signature is invalid.'],
            ], 401, [
                'webhook_event_id' => $webhookEvent->id,
            ]);
        }

        if ($webhookEvent->status === 'duplicate') {
            return ApiResponse::data([
                'status' => 'duplicate',
                'webhook_event_id' => $webhookEvent->id,
                'duplicate_of_id' => $webhookEvent->duplicate_of_id,
            ], 'Duplicate mobile money callback received; original event will not be reprocessed.');
        }

        $provider = PaymentProvider::query()->when($data['provider_code'] ?? null, fn ($query, string $code) => $query->where('code', $code))->first();
        $paymentRequest = null;

        if (($data['reference'] ?? null) || ($data['external_reference'] ?? null)) {
            $paymentRequest = PaymentRequest::query()
                ->where(function ($query) use ($data): void {
                    $query
                        ->when($data['reference'] ?? null, fn ($query, string $reference) => $query->where('reference', $reference))
                        ->when($data['external_reference'] ?? null, fn ($query, string $reference) => $query->orWhere('external_reference', $reference));
                })
                ->first();
        }

        $callback = PaymentCallback::create([
            'payment_provider_id' => $provider?->id,
            'payment_request_id' => $paymentRequest?->id,
            'external_reference' => $data['external_reference'] ?? null,
            'payload' => $request->all(),
            'signature' => $request->header('X-Signature'),
            'status' => 'received',
        ]);

        if ($paymentRequest && isset($data['status'])) {
            $paymentRequest->update(['status' => $data['status'], 'external_reference' => $data['external_reference'] ?? $paymentRequest->external_reference]);
        }

        if ($paymentRequest) {
            MobileMoneyTransaction::create([
                'organization_id' => $paymentRequest->organization_id,
                'payment_provider_id' => $provider?->id ?? $paymentRequest->payment_provider_id,
                'payment_request_id' => $paymentRequest->id,
                'allocatable_type' => $paymentRequest->payable_type,
                'allocatable_id' => $paymentRequest->payable_id,
                'provider_transaction_id' => $data['external_reference'] ?? null,
                'phone_number' => $data['phone'] ?? $paymentRequest->payer_phone,
                'amount' => $data['amount'] ?? $paymentRequest->amount,
                'currency' => $paymentRequest->currency,
                'status' => $data['status'] ?? 'pending',
                'raw_response' => $request->all(),
            ]);
        }

        $webhooks->markProcessed($webhookEvent, [
            'handler' => 'legacy_mobile_money_callback',
            'payment_callback_id' => $callback->id,
            'payment_request_id' => $paymentRequest?->id,
        ]);

        return ApiResponse::data(['status' => 'received', 'callback_id' => $callback->id], 'Mobile money callback received.');
    }

    public function verify(PaymentRequest $paymentRequest): JsonResponse
    {
        ApiSecurity::assertTenantAccess(request()->user(), $paymentRequest);

        return ApiResponse::resource(new PaymentRequestResource($paymentRequest->load('provider')), 'Payment verification retrieved.');
    }

    public function reconcile(Request $request, MobileMoneyReconciliationService $reconciliation): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounting') || $request->user()->canPerform('post-transactions'), 403);

        return ApiResponse::data(['status' => 'queued'], 'Reconciliation endpoint is ready for provider adapters.', 202);
    }

    public function matchPayment(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $paymentRequest);

        $data = $request->validate([
            'payable_type' => ['required', 'in:savings_account,loan'],
            'payable_id' => ['required', 'integer'],
        ]);

        $payable = $this->payable($data['payable_type'], (int) $data['payable_id']);
        ApiSecurity::assertTenantAccess($request->user(), $payable);
        $paymentRequest->update([
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'status' => 'matched',
        ]);

        return ApiResponse::resource(new PaymentRequestResource($paymentRequest->fresh()->load('provider')), 'Payment matched successfully.');
    }

    private function payable(string $type, int $id): Model
    {
        return match ($type) {
            'savings_account' => SavingsAccount::findOrFail($id),
            'loan' => Loan::findOrFail($id),
        };
    }
}
