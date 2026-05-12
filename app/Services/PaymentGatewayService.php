<?php

namespace App\Services;

use App\Models\PaymentProvider;
use App\Models\PaymentRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentGatewayService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function initiateCollection(PaymentProvider $provider, Model $payable, float $amount, string $phone, array $metadata = []): PaymentRequest
    {
        return PaymentRequest::create([
            'organization_id' => $provider->organization_id,
            'payment_provider_id' => $provider->id,
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'reference' => 'PAY'.now()->format('ymdHis').Str::upper(Str::random(5)),
            'payer_phone' => $phone,
            'amount' => $amount,
            'currency' => 'UGX',
            'status' => 'initiated',
            'metadata' => $metadata,
            'expires_at' => now()->addMinutes(15),
        ]);
    }
}
