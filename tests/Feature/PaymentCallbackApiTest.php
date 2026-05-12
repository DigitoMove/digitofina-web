<?php

use App\Models\PaymentCallback;
use App\Models\PaymentProvider;
use App\Models\PaymentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records unmatched mobile money callbacks without attaching them to an arbitrary payment request', function () {
    $provider = PaymentProvider::create([
        'name' => 'MTN Test',
        'code' => 'mtn-test',
        'type' => 'mobile_money',
        'status' => 'active',
    ]);

    PaymentRequest::create([
        'payment_provider_id' => $provider->id,
        'reference' => 'PAY-001',
        'payer_phone' => '256700000000',
        'amount' => 25000,
        'currency' => 'UGX',
        'status' => 'initiated',
    ]);

    $this->postJson('/api/v1/payments/mobile-money/callback', [
        'provider_code' => 'mtn-test',
        'status' => 'successful',
        'amount' => 25000,
        'phone' => '256700000000',
    ])->assertSuccessful()
        ->assertJsonPath('status', 'received');

    expect(PaymentCallback::first())
        ->payment_request_id
        ->toBeNull();
});
