<?php

use App\Services\LoanCalculatorService;

it('calculates flat rate installments', function () {
    $schedule = (new LoanCalculatorService)->flatRate(1_000_000, 0.5, 4);

    expect($schedule)->toHaveCount(4)
        ->and($schedule[0])->toMatchArray([
            'principal' => 250_000.00,
            'interest' => 5_000.00,
            'total' => 255_000.00,
        ])
        ->and($schedule[3]['balance'])->toBe(0);
});

it('calculates declining balance interest on outstanding principal', function () {
    $schedule = (new LoanCalculatorService)->decliningBalance(1_000_000, 2, 4);

    expect($schedule[0]['interest'])->toBe(20_000.00)
        ->and($schedule[1]['interest'])->toBe(15_000.00)
        ->and($schedule[3]['balance'])->toBe(0);
});

it('calculates reducing balance equal installments', function () {
    $schedule = (new LoanCalculatorService)->reducingBalanceEqualInstallments(1_000_000, 2, 4);

    expect($schedule)->toHaveCount(4)
        ->and($schedule[0]['total'])->toBeGreaterThan(260_000)
        ->and($schedule[0]['interest'])->toBe(20_000.00)
        ->and($schedule[3]['balance'])->toBe(0);
});
