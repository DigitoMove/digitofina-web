<?php

use App\Models\Loan;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Services\LoanApplicationFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calculates mixed fixed and percentage bands on the requested amount', function (int $amount, float $expected) {
    $ranges = json_encode([
        ['min' => 100, 'max' => 500000, 'fee' => 10000],
        ['min' => 500100, 'max' => 950000, 'fee_type' => 'fixed', 'fee' => 20000],
        ['min' => 950100, 'max' => null, 'fee_type' => 'percentage', 'fee' => 0.01],
    ]);
    $service = app(LoanApplicationFeeService::class);
    $service->assertValidJson($ranges);
    SystemSetting::forPlatform()->updateOrCreate(['key' => 'loan_application_fee_ranges'], ['value' => $ranges, 'type' => 'json', 'group' => 'loans']);

    expect($service->feeFor($amount))->toBe($expected);
})->with([
    'first range' => [100, 10000.0],
    'first upper bound' => [500000, 10000.0],
    'second lower bound' => [500100, 20000.0],
    'second upper bound' => [950000, 20000.0],
    'percentage lower bound rounds down' => [950100, 9500.0],
    'half rounds up' => [955000, 9600.0],
    'one million' => [1000000, 10000.0],
    'two million' => [2000000, 20000.0],
]);

it('rejects invalid fee band configurations', function (array $ranges) {
    expect(fn () => app(LoanApplicationFeeService::class)->assertValidJson(json_encode($ranges)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'ambiguous fraction without type' => [[['min' => 100, 'max' => null, 'fee' => 0.01]]],
    'unknown fee type' => [[['min' => 100, 'fee_type' => 'percent', 'fee' => 0.01]]],
    'negative percentage' => [[['min' => 100, 'fee_type' => 'percentage', 'fee' => -0.01]]],
    'percentage above one' => [[['min' => 100, 'fee_type' => 'percentage', 'fee' => 10]]],
    'nonnumeric percentage' => [[['min' => 100, 'fee_type' => 'percentage', 'fee' => '1%']]],
    'overlap' => [[['min' => 100, 'max' => 1000, 'fee' => 100], ['min' => 1000, 'fee_type' => 'percentage', 'fee' => 0.01]]],
    'open ended before final range' => [[['min' => 100, 'max' => null, 'fee' => 100], ['min' => 1000, 'fee_type' => 'percentage', 'fee' => 0.01]]],
    'single object instead of list' => [['min' => 100, 'fee' => 100]],
    'negative fixed fee' => [[['min' => 100, 'fee' => -100]]],
]);

it('supports zero fees in both types and preserves fallback outside bands', function (string $type) {
    $ranges = json_encode([['min' => 100, 'max' => 1000000, 'fee_type' => $type, 'fee' => 0]]);
    $service = app(LoanApplicationFeeService::class);
    $service->assertValidJson($ranges);
    SystemSetting::forPlatform()->updateOrCreate(['key' => 'loan_application_fee_ranges'], ['value' => $ranges, 'type' => 'json', 'group' => 'loans']);
    SystemSetting::forPlatform()->updateOrCreate(['key' => 'loan_application_fee'], ['value' => '5000', 'type' => 'money', 'group' => 'loans']);

    expect($service->feeFor(1000000))->toBe(0.0)
        ->and($service->feeFor(2000000))->toBe(5000.0);
})->with(['fixed', 'percentage']);

it('uses company percentage ranges before platform ranges and keeps loan exceptions first', function () {
    $company = Organization::create(['name' => 'Company fee lender', 'slug' => 'company-fee-lender', 'status' => 'active']);
    $other = Organization::create(['name' => 'Default fee lender', 'slug' => 'default-fee-lender', 'status' => 'active']);
    SystemSetting::forPlatform()->updateOrCreate(['key' => 'loan_application_fee_ranges'], ['value' => json_encode([['min' => 100, 'fee_type' => 'percentage', 'fee' => 0.02]]), 'type' => 'json', 'group' => 'loans']);
    SystemSetting::create(['organization_id' => $company->id, 'key' => 'loan_application_fee_ranges', 'value' => json_encode([['min' => 100, 'fee_type' => 'percentage', 'fee' => 0.01]]), 'type' => 'json', 'group' => 'loans']);
    $service = app(LoanApplicationFeeService::class);

    expect($service->feeFor(1000000, $company->id))->toBe(10000.0)
        ->and($service->feeFor(1000000, $other->id))->toBe(20000.0);

    $loan = new Loan(['organization_id' => $company->id, 'requested_amount' => 1000000, 'application_fee_override' => 0]);
    expect($service->feeForLoan($loan))->toBe(0.0);
    $loan->application_fee_override = 5000;
    expect($service->feeForLoan($loan))->toBe(5000.0);
    $loan->application_fee_override = null;
    expect($service->feeForLoan($loan))->toBe(10000.0);
});
