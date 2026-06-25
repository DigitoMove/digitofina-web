<?php

namespace App\Jobs;

use App\Models\MobileMoneyTransaction;
use App\Models\PaymentCallback;
use App\Models\PaymentProvider;
use App\Models\PaymentRequest;
use App\Models\WebhookEvent;
use App\Services\OnlinePaymentPostingService;
use App\Services\WebhookIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessMobileMoneyCallback implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public WebhookEvent $webhookEvent) {}

    public function handle(WebhookIngestionService $webhooks, OnlinePaymentPostingService $onlinePayments): void
    {
        $event = WebhookEvent::query()->findOrFail($this->webhookEvent->id);

        if (in_array($event->status, ['duplicate', 'processed', 'rejected'], true)) {
            return;
        }

        $event->update([
            'status' => 'processing',
            'attempts' => $event->attempts + 1,
        ]);

        $data = $event->payload ?? [];
        $providerCode = $data['provider_code'] ?? $event->provider_code;
        $provider = PaymentProvider::query()
            ->when($providerCode, fn (Builder $query, string $code) => $query->where('code', $code))
            ->first();
        $paymentRequest = $this->paymentRequest($data);
        $headers = $event->headers ?? [];

        $callback = PaymentCallback::create([
            'payment_provider_id' => $provider?->id,
            'payment_request_id' => $paymentRequest?->id,
            'external_reference' => $data['external_reference'] ?? null,
            'payload' => $data,
            'signature' => $headers['x-signature'][0] ?? $headers['x-signature'] ?? null,
            'status' => 'received',
        ]);

        if ($paymentRequest && isset($data['status'])) {
            $paymentRequest->update([
                'status' => $data['status'],
                'external_reference' => $data['external_reference'] ?? $paymentRequest->external_reference,
            ]);
        }

        if ($paymentRequest) {
            MobileMoneyTransaction::firstOrCreate(
                [
                    'payment_request_id' => $paymentRequest->id,
                    'provider_transaction_id' => $data['external_reference'] ?? null,
                ],
                [
                    'organization_id' => $paymentRequest->organization_id,
                    'payment_provider_id' => $provider?->id ?? $paymentRequest->payment_provider_id,
                    'allocatable_type' => $paymentRequest->payable_type,
                    'allocatable_id' => $paymentRequest->payable_id,
                    'phone_number' => $data['phone'] ?? $paymentRequest->payer_phone,
                    'amount' => $data['amount'] ?? $paymentRequest->amount,
                    'currency' => $paymentRequest->currency,
                    'status' => $data['status'] ?? 'pending',
                    'raw_response' => $data,
                ],
            );

            $onlinePayments->postIfSuccessful($paymentRequest, $data);
        }

        $webhooks->markProcessed($event, [
            'handler' => 'queued_mobile_money_callback',
            'payment_callback_id' => $callback->id,
            'payment_request_id' => $paymentRequest?->id,
        ]);
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function failed(Throwable $exception): void
    {
        WebhookEvent::query()->whereKey($this->webhookEvent->id)->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'next_retry_at' => now()->addMinutes(15),
        ]);

        Log::error('Mobile money callback processing failed.', [
            'webhook_event_id' => $this->webhookEvent->id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function paymentRequest(array $data): ?PaymentRequest
    {
        if (! ($data['reference'] ?? null) && ! ($data['external_reference'] ?? null)) {
            return null;
        }

        return PaymentRequest::query()
            ->where(function (Builder $query) use ($data): void {
                $query
                    ->when($data['reference'] ?? null, fn (Builder $query, string $reference) => $query->where('reference', $reference))
                    ->when($data['external_reference'] ?? null, fn (Builder $query, string $reference) => $query->orWhere('external_reference', $reference));
            })
            ->first();
    }
}
