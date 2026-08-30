<?php

namespace App\Services;

use App\Models\PaymentProvider;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class NylonPayCollectionService
{
    public function __construct(private NylonPayClient $nylonPay) {}

    /** @param array<string, mixed> $metadata */
    public function initiate(Model $payable, float $amount, string $phone, User $member, array $metadata): PaymentRequest
    {
        CurrencyDenomination::assertValid($amount);
        $organizationId = (int) $member->organization_id;
        abort_unless($this->nylonPay->isConfigured($organizationId), 422, 'This organization has not configured Nylon Pay yet.');

        $provider = PaymentProvider::query()->firstOrCreate(
            ['organization_id' => $organizationId, 'code' => 'nylon-pay'],
            ['name' => 'Nylon Pay', 'type' => 'hosted_checkout', 'is_default' => true, 'status' => 'active'],
        );
        $paymentRequest = PaymentRequest::create([
            'organization_id' => $organizationId,
            'payment_provider_id' => $provider->id,
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'reference' => 'np'.now()->format('ymdHis').Str::lower(Str::random(1)),
            'payer_phone' => $phone,
            'amount' => $amount,
            'currency' => 'UGX',
            'status' => 'initiated',
            'metadata' => $metadata + array_filter([
                'initiated_by' => $member->id,
                'channel' => 'client_portal',
                'impersonator_user_id' => session(ImpersonationService::IMPERSONATOR_SESSION_KEY),
                'impersonated_user_id' => session()->has(ImpersonationService::IMPERSONATOR_SESSION_KEY) ? Auth::id() : null,
            ], fn (mixed $value): bool => $value !== null),
            'expires_at' => now()->addMinutes(15),
        ]);

        $invoice = $this->nylonPay->createCollectionInvoice($paymentRequest, route('member.dashboard', ['payment_return' => $paymentRequest->reference]));
        $paymentRequest->update([
            'external_reference' => $invoice['id'],
            'checkout_url' => $invoice['url'],
            'status' => $invoice['status'],
            'metadata' => ($paymentRequest->metadata ?? []) + ['nylon_invoice' => $invoice],
        ]);

        return $paymentRequest->refresh();
    }
}
