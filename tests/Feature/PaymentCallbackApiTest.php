<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\LoanSchedule;
use App\Models\PaymentCallback;
use App\Models\PaymentProvider;
use App\Models\PaymentRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function paymentCallbackChartAccounts(): void
{
    foreach ([
        ['1000', 'Cash on Hand', 'asset', 'debit'],
        ['1200', 'Loan Portfolio', 'asset', 'debit'],
        ['4100', 'Interest Income', 'income', 'credit'],
        ['4200', 'Fees and Penalties Income', 'income', 'credit'],
    ] as [$code, $name, $type, $normalBalance]) {
        ChartAccount::create(['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normalBalance, 'is_active' => true]);
    }
}

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
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'received');

    expect(PaymentCallback::first())
        ->payment_request_id
        ->toBeNull();
});

it('posts successful online loan repayments once and clears penalties before closing the loan', function () {
    paymentCallbackChartAccounts();
    $branch = Branch::create(['code' => '1001', 'name' => 'Main Branch', 'is_active' => true, 'status' => 'active']);
    $role = Role::create(['name' => 'Member', 'slug' => 'member']);
    $user = User::factory()->create(['branch_id' => $branch->id, 'role_id' => $role->id, 'status' => 'active', 'is_active' => true]);
    $client = Client::create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'client_number' => '1001-C000001',
        'client_type' => 'individual',
        'first_name' => 'Sarah',
        'last_name' => 'Ageno',
        'phone' => '256700000001',
        'kyc_status' => 'verified',
        'status' => 'active',
    ]);
    $product = LoanProduct::create([
        'name' => 'Penalty Loan',
        'code' => 'PEN',
        'annual_interest_rate' => 12,
        'interest_method' => 'declining_balance',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 1,
        'penalty_type' => 'percentage',
        'penalty_rate' => 1,
        'status' => 'active',
        'is_active' => true,
    ]);
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $product->id,
        'loan_number' => 'LN-PEN-001',
        'requested_amount' => 100000,
        'approved_amount' => 100000,
        'principal_balance' => 100000,
        'interest_balance' => 10000,
        'fees_balance' => 5000,
        'penalty_balance' => 2000,
        'interest_rate' => 12,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
    ]);
    LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today()->subDay(),
        'principal_due' => 100000,
        'interest_due' => 10000,
        'fees_due' => 5000,
        'penalty_due' => 2000,
        'total_due' => 117000,
        'outstanding_amount' => 117000,
        'balance_due' => 117000,
        'status' => 'overdue',
    ]);
    $provider = PaymentProvider::create([
        'name' => 'MTN Test',
        'code' => 'mtn-test',
        'type' => 'mobile_money',
        'status' => 'active',
    ]);
    $paymentRequest = PaymentRequest::create([
        'payment_provider_id' => $provider->id,
        'payable_type' => $loan->getMorphClass(),
        'payable_id' => $loan->id,
        'reference' => 'PAY-PEN-001',
        'payer_phone' => '256700000001',
        'amount' => 117000,
        'currency' => 'UGX',
        'status' => 'initiated',
        'metadata' => ['initiated_by' => $user->id, 'client_id' => $client->id],
    ]);

    $this->postJson('/api/v1/payments/mobile-money/callback', [
        'provider_code' => 'mtn-test',
        'reference' => $paymentRequest->reference,
        'external_reference' => 'MM-001',
        'status' => 'successful',
        'amount' => 117000,
        'phone' => '256700000001',
    ])->assertSuccessful();

    $paymentRequest->refresh();
    $loan->refresh();

    expect($paymentRequest->status)->toBe('posted')
        ->and($paymentRequest->posted_at)->not->toBeNull()
        ->and($loan->status)->toBe('closed')
        ->and((float) $loan->penalty_balance)->toBe(0.0)
        ->and((float) $loan->principal_balance)->toBe(0.0)
        ->and(LoanRepayment::where('loan_id', $loan->id)->count())->toBe(1)
        ->and((float) LoanRepayment::first()->penalty_paid)->toBe(2000.0);

    $this->postJson('/api/v1/payments/mobile-money/callback', [
        'provider_code' => 'mtn-test',
        'reference' => $paymentRequest->reference,
        'external_reference' => 'MM-001',
        'status' => 'successful',
        'amount' => 117000,
        'phone' => '256700000001',
    ])->assertSuccessful();

    expect(LoanRepayment::where('loan_id', $loan->id)->count())->toBe(1);
});
