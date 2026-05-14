<?php

namespace App\Services;

use App\Models\MobileMoneyTransaction;
use App\Models\PaymentProvider;
use App\Models\PaymentRequest;
use App\Models\SmsProvider;
use App\Models\WebhookEvent;
use App\Models\WebhookProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class WebhookIngestionService
{
    public function ingest(Request $request, string $sourceType, string $providerCode): WebhookEvent
    {
        $sourceType = $this->normalizeSourceType($sourceType);
        $providerCode = Str::of($providerCode)->lower()->slug('-')->toString();
        $rawPayload = $request->getContent();
        $payload = $this->decodedPayload($rawPayload);
        $headers = $request->headers->all();
        $provider = $this->webhookProvider($sourceType, $providerCode);
        $providerName = $provider?->name ?? $this->providerName($sourceType, $providerCode) ?? $providerCode;
        $signatureHeader = $provider?->signature_header ?? $this->signatureHeader($sourceType, $providerCode);
        $signature = $signatureHeader ? $request->header($signatureHeader) : null;
        $signatureValid = $this->signatureValid($provider, $rawPayload, $signature);
        $eventId = $this->eventId($request, $payload);
        $payloadHash = hash('sha256', $rawPayload);
        $duplicateKey = $this->duplicateKey($sourceType, $providerCode, $eventId, $payloadHash);

        return DB::transaction(function () use ($provider, $sourceType, $providerCode, $providerName, $eventId, $duplicateKey, $payloadHash, $headers, $rawPayload, $payload, $signatureHeader, $signature, $signatureValid): WebhookEvent {
            $original = WebhookEvent::query()
                ->where('duplicate_key', $duplicateKey)
                ->whereNull('duplicate_of_id')
                ->lockForUpdate()
                ->first();

            $status = match (true) {
                $signatureValid === false => 'rejected',
                $original !== null => 'duplicate',
                default => 'received',
            };

            return WebhookEvent::create([
                'organization_id' => $provider?->organization_id,
                'webhook_provider_id' => $provider?->id,
                'duplicate_of_id' => $original?->id,
                'source_type' => $sourceType,
                'provider_name' => $providerName,
                'provider_code' => $providerCode,
                'event_id' => $eventId,
                'duplicate_key' => $duplicateKey,
                'payload_hash' => $payloadHash,
                'headers' => $headers,
                'raw_payload' => $rawPayload,
                'payload' => $payload,
                'signature_header' => $signatureHeader,
                'signature' => $signature,
                'signature_valid' => $signatureValid,
                'status' => $status,
                'error_message' => $signatureValid === false ? 'Webhook signature verification failed.' : null,
            ]);
        }, 3);
    }

    public function process(WebhookEvent $event): WebhookEvent
    {
        if (in_array($event->status, ['duplicate', 'rejected', 'processed'], true)) {
            return $event;
        }

        try {
            $event->forceFill([
                'status' => 'processing',
                'attempts' => $event->attempts + 1,
                'error_message' => null,
                'next_retry_at' => null,
            ])->save();

            $result = match ($event->source_type) {
                'mobile_money', 'bank' => $this->processPaymentWebhook($event),
                'sms' => ['handler' => 'sms', 'message' => 'SMS provider webhook stored for adapter processing.'],
                default => ['handler' => 'generic', 'message' => 'Webhook stored for external partner adapter processing.'],
            };

            $event->forceFill([
                'status' => 'processed',
                'processing_result' => $result,
                'processed_at' => now(),
            ])->save();

            return $event;
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'next_retry_at' => now()->addMinutes($this->retryDelayMinutes($event->attempts)),
            ])->save();

            Log::error('Webhook processing failed.', [
                'webhook_event_id' => $event->id,
                'source_type' => $event->source_type,
                'provider_code' => $event->provider_code,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function markProcessed(WebhookEvent $event, array $result = []): WebhookEvent
    {
        $event->forceFill([
            'status' => $event->status === 'duplicate' ? 'duplicate' : 'processed',
            'processing_result' => $result,
            'processed_at' => now(),
        ])->save();

        return $event;
    }

    public function retry(WebhookEvent $event): WebhookEvent
    {
        abort_unless($event->status === 'failed', 422, 'Only failed webhook events can be retried.');

        $event->forceFill([
            'status' => 'received',
            'error_message' => null,
            'next_retry_at' => null,
        ])->save();

        return $event;
    }

    private function webhookProvider(string $sourceType, string $providerCode): ?WebhookProvider
    {
        return WebhookProvider::query()
            ->where('source_type', $sourceType)
            ->where('code', $providerCode)
            ->where('status', 'active')
            ->first();
    }

    private function providerName(string $sourceType, string $providerCode): ?string
    {
        if (in_array($sourceType, ['mobile_money', 'bank'], true)) {
            return PaymentProvider::query()->where('code', $providerCode)->value('name');
        }

        if ($sourceType === 'sms') {
            return SmsProvider::query()->where('code', $providerCode)->value('name');
        }

        return null;
    }

    private function signatureHeader(string $sourceType, string $providerCode): ?string
    {
        $configuration = $this->legacyProviderConfiguration($sourceType, $providerCode);

        return $configuration['signature_header'] ?? 'X-Signature';
    }

    private function signatureValid(?WebhookProvider $provider, string $rawPayload, ?string $signature): ?bool
    {
        $secret = $provider?->signing_secret;

        if (! $secret) {
            return $provider?->requires_signature ? false : null;
        }

        if (! $signature) {
            return false;
        }

        $algorithm = $provider->signature_algorithm ?: 'sha256';
        $provided = Str::of($signature)->after('=')->toString();

        if (hash_equals(hash_hmac($algorithm, $rawPayload, $secret), $provided)) {
            return true;
        }

        $decoded = json_decode($rawPayload, true);

        if (is_array($decoded)) {
            return hash_equals(hash_hmac($algorithm, json_encode($decoded, JSON_THROW_ON_ERROR), $secret), $provided);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyProviderConfiguration(string $sourceType, string $providerCode): array
    {
        $provider = match (true) {
            in_array($sourceType, ['mobile_money', 'bank'], true) => PaymentProvider::query()->where('code', $providerCode)->first(),
            $sourceType === 'sms' => SmsProvider::query()->where('code', $providerCode)->first(),
            default => null,
        };

        return $provider?->configuration ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedPayload(string $rawPayload): array
    {
        $decoded = json_decode($rawPayload, true);

        return is_array($decoded) ? $decoded : ['_raw' => $rawPayload];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function eventId(Request $request, array $payload): ?string
    {
        return $request->header('X-Webhook-Id')
            ?: $request->header('X-Event-Id')
            ?: $request->header('X-Request-Id')
            ?: ($payload['event_id'] ?? $payload['id'] ?? $payload['external_reference'] ?? $payload['reference'] ?? $payload['transaction_id'] ?? null);
    }

    private function duplicateKey(string $sourceType, string $providerCode, ?string $eventId, string $payloadHash): string
    {
        return hash('sha256', implode('|', [$sourceType, $providerCode, $eventId ?: $payloadHash]));
    }

    private function normalizeSourceType(string $sourceType): string
    {
        return Str::of($sourceType)->replace('-', '_')->lower()->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function processPaymentWebhook(WebhookEvent $event): array
    {
        $payload = $event->payload ?? [];
        $reference = $payload['reference'] ?? null;
        $externalReference = $payload['external_reference'] ?? $payload['transaction_id'] ?? null;

        if (! $reference && ! $externalReference) {
            return ['handler' => 'payment', 'matched' => false];
        }

        $paymentRequest = PaymentRequest::query()
            ->where(function ($query) use ($reference, $externalReference): void {
                $query
                    ->when($reference, fn ($query, string $value) => $query->where('reference', $value))
                    ->when($externalReference, fn ($query, string $value) => $query->orWhere('external_reference', $value));
            })
            ->first();

        if (! $paymentRequest) {
            return ['handler' => 'payment', 'matched' => false];
        }

        $paymentRequest->update([
            'status' => $payload['status'] ?? $paymentRequest->status,
            'external_reference' => $externalReference ?: $paymentRequest->external_reference,
        ]);

        MobileMoneyTransaction::query()->firstOrCreate(
            [
                'payment_request_id' => $paymentRequest->id,
                'provider_transaction_id' => $externalReference,
            ],
            [
                'organization_id' => $paymentRequest->organization_id,
                'payment_provider_id' => $paymentRequest->payment_provider_id,
                'allocatable_type' => $paymentRequest->payable_type,
                'allocatable_id' => $paymentRequest->payable_id,
                'phone_number' => $payload['phone'] ?? $paymentRequest->payer_phone,
                'amount' => $payload['amount'] ?? $paymentRequest->amount,
                'currency' => $paymentRequest->currency,
                'status' => $payload['status'] ?? 'pending',
                'raw_response' => $payload,
            ],
        );

        return ['handler' => 'payment', 'matched' => true, 'payment_request_id' => $paymentRequest->id];
    }

    private function retryDelayMinutes(int $attempts): int
    {
        return match (true) {
            $attempts <= 1 => 1,
            $attempts === 2 => 5,
            default => 15,
        };
    }
}
