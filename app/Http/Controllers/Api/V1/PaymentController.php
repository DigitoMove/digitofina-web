<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentRequestResource;
use App\Jobs\ProcessMobileMoneyCallback;
use App\Models\Loan;
use App\Models\PaymentProvider;
use App\Models\PaymentRequest;
use App\Models\SavingsAccount;
use App\Services\MobileMoneyReconciliationService;
use App\Services\PaymentGatewayService;
use App\Services\WebhookIngestionService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use App\Support\CurrencyDenomination;
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
            'amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
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

        ProcessMobileMoneyCallback::dispatch($webhookEvent)->afterCommit();

        return ApiResponse::data([
            'status' => 'received',
            'webhook_event_id' => $webhookEvent->id,
        ], 'Mobile money callback received for queued processing.', 202);
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
