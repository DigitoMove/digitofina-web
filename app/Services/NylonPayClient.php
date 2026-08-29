<?php

namespace App\Services;

use App\Models\CommercialQuote;
use App\Models\NylonPayCredential;
use App\Models\PaymentRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NylonPayClient
{
    /** @return array{id: string, url: string, token: string|null, expiresAt: string|null, status: string} */
    public function createInvoice(CommercialQuote $quote, string $redirectUrl): array
    {
        $this->assertConfigured();

        $payload = [
            'amount' => (int) $quote->amount,
            'currency' => $quote->currency,
            'description' => "DigitoFina quote {$quote->quote_number}",
            'items' => [[
                'name' => $quote->billing_type === 'subscription'
                    ? "DigitoFina subscription ({$quote->subscription_months} month(s))"
                    : 'DigitoFina one-time licence',
                'quantity' => 1,
                'unitPrice' => (int) $quote->amount,
            ]],
            'redirectUrl' => $redirectUrl,
            'reference' => $quote->nylon_reference,
            'metadata' => [
                'quoteId' => (string) $quote->id,
                'organizationId' => (string) $quote->organization_id,
                'quoteNumber' => $quote->quote_number,
            ],
        ];

        $data = $this->send('sdk-create-invoice', $payload);

        return $this->invoiceData($data);
    }

    /** @return array{id: string, url: string, token: string|null, expiresAt: string|null, status: string} */
    public function createCollectionInvoice(PaymentRequest $paymentRequest, string $redirectUrl): array
    {
        $organizationId = (int) $paymentRequest->organization_id;
        $this->assertConfigured($organizationId);
        $purpose = str($paymentRequest->metadata['purpose'] ?? 'payment')->headline()->toString();
        $data = $this->send('sdk-create-invoice', [
            'amount' => (int) $paymentRequest->amount,
            'currency' => $paymentRequest->currency,
            'description' => "{$purpose} {$paymentRequest->reference}",
            'items' => [['name' => $purpose, 'quantity' => 1, 'unitPrice' => (int) $paymentRequest->amount]],
            'redirectUrl' => $redirectUrl,
            'reference' => $paymentRequest->reference,
            'metadata' => [
                'paymentRequestId' => (string) $paymentRequest->id,
                'organizationId' => (string) $organizationId,
                'purpose' => (string) ($paymentRequest->metadata['purpose'] ?? 'payment'),
            ],
        ], $organizationId);

        return $this->invoiceData($data);
    }

    /** @param array<string, mixed> $data
     * @return array{id: string, url: string, token: string|null, expiresAt: string|null, status: string}
     */
    private function invoiceData(array $data): array
    {
        $url = $data['url'] ?? null;
        $id = $data['id'] ?? null;

        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || ! is_string($id) || $id === '') {
            throw new RuntimeException('Nylon Pay did not return a valid checkout invoice.');
        }

        return [
            'id' => $id,
            'url' => $url,
            'token' => is_string($data['token'] ?? null) ? $data['token'] : null,
            'expiresAt' => is_string($data['expiresAt'] ?? null) ? $data['expiresAt'] : null,
            'status' => is_string($data['status'] ?? null) ? $data['status'] : 'pending',
        ];
    }

    public function isConfigured(?int $organizationId = null): bool
    {
        $credentials = $this->credentials($organizationId);

        return Str::startsWith($credentials['api_key'], 'npk_')
            && Str::startsWith($credentials['api_secret'], 'nps_')
            && filled($credentials['webhook_secret']);
    }

    public function verifyWebhook(string $payload, string $signature, ?NylonPayCredential $credential = null): bool
    {
        $secret = $this->credentials($credential?->organization_id, $credential)['webhook_secret'];

        if ($secret === '' || preg_match('/^[0-9a-f]{64}$/', $signature) !== 1) {
            return false;
        }

        if (! hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            return false;
        }

        $decoded = json_decode($payload, true);
        $timestamp = is_array($decoded) ? ($decoded['timestamp'] ?? null) : null;
        $webhookTime = $this->webhookTimestamp($timestamp);
        $tolerance = max(1, (int) config('services.nylon_pay.webhook_tolerance_seconds', 300));

        return $webhookTime !== null && abs(now()->timestamp - $webhookTime) <= $tolerance;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function send(string $action, array $payload, ?int $organizationId = null): array
    {
        $fingerprint = $this->fingerprint();
        $payload['_fingerprint'] = $fingerprint;
        $nonce = bin2hex(random_bytes(16));
        $timestamp = (string) floor(microtime(true) * 1000);
        $signature = hash_hmac('sha256', implode('.', [
            $fingerprint,
            $nonce,
            $timestamp,
            $this->canonicalJson($payload),
        ]), $this->credentials($organizationId)['api_secret']);
        $body = json_encode([
            'intent' => 'execute',
            'service' => 'sdk',
            'action' => $action,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $response = $this->request()
            ->withHeaders([
                'X-Nylon-Key' => $this->credentials($organizationId)['api_key'],
                'X-Nylon-Nonce' => $nonce,
                'X-Nylon-Timestamp' => $timestamp,
                'X-Nylon-Signature' => $signature,
            ])
            ->withBody($body, 'application/json')
            ->post($this->credentials($organizationId)['base_url']);

        if ($response->failed()) {
            throw new RuntimeException('Nylon Pay could not create the checkout. Please try again.');
        }

        $envelope = $response->json();

        if (! is_array($envelope) || ($envelope['status'] ?? false) !== true || ! is_array($envelope['data'] ?? null)) {
            throw new RuntimeException(is_string($envelope['message'] ?? null) ? $envelope['message'] : 'Nylon Pay returned an invalid response.');
        }

        return $this->verifiedResponseData($envelope['data'], $nonce, $organizationId);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(max(5, (int) config('services.nylon_pay.timeout', 15)))
            ->retry([100, 500, 1000], function (Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException
                        && in_array($exception->response->status(), [408, 429, 500, 502, 503, 504], true));
            }, throw: false);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function verifiedResponseData(array $data, string $nonce, ?int $organizationId = null): array
    {
        $signature = $data['_responseSignature'] ?? null;
        unset($data['_responseSignature']);

        if (! is_string($signature) || preg_match('/^[0-9a-f]{64}$/', $signature) !== 1) {
            throw new RuntimeException('Nylon Pay response signature is missing.');
        }

        $expected = hash_hmac('sha256', $this->canonicalJson($data), $this->credentials($organizationId)['api_secret']);

        if (! hash_equals($expected, $signature)) {
            throw new RuntimeException('Nylon Pay response signature could not be verified.');
        }

        if (! isset($data['_requestNonce']) || ! hash_equals($nonce, (string) $data['_requestNonce'])) {
            throw new RuntimeException('Nylon Pay response did not match this request.');
        }

        unset($data['_requestNonce']);

        return $data;
    }

    private function assertConfigured(?int $organizationId = null): void
    {
        if (! $this->isConfigured($organizationId)) {
            throw new RuntimeException('Nylon Pay is not configured. Set its API key, API secret, and webhook secret first.');
        }
    }

    private function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            'php_version:'.PHP_VERSION,
            'os:'.PHP_OS_FAMILY,
            'arch:'.php_uname('m'),
            'hostname:'.(gethostname() ?: 'unknown'),
        ]));
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->sortRecursively($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
    }

    private function webhookTimestamp(mixed $timestamp): ?int
    {
        if (is_numeric($timestamp)) {
            $value = (int) $timestamp;

            return $value >= 1_000_000_000_000 ? (int) floor($value / 1000) : $value;
        }

        if (is_string($timestamp)) {
            try {
                return Carbon::parse($timestamp)->timestamp;
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /** @return array{api_key: string, api_secret: string, webhook_secret: string, base_url: string} */
    private function credentials(?int $organizationId = null, ?NylonPayCredential $credential = null): array
    {
        $stored = $credential ?: NylonPayCredential::query()
            ->where('scope_key', $organizationId ? "organization:{$organizationId}" : 'platform:default')
            ->first();

        if (! $stored && $organizationId === null) {
            $stored = NylonPayCredential::query()->whereNull('organization_id')->where('profile', 'default')->first();
        }

        return [
            'api_key' => (string) ($stored?->api_key ?: config('services.nylon_pay.api_key')),
            'api_secret' => (string) ($stored?->api_secret ?: config('services.nylon_pay.api_secret')),
            'webhook_secret' => (string) ($stored?->webhook_secret ?: config('services.nylon_pay.webhook_secret')),
            'base_url' => (string) ($stored?->base_url ?: config('services.nylon_pay.base_url')),
        ];
    }
}
