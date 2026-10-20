<?php

test('mobile operations routes require an authenticated session', function () {
    $this->getJson('/api/v1/mobile-operations/savings-loan-sources?search=12')
        ->assertUnauthorized();

    $this->getJson('/api/v1/mobile-operations/non-cash/options')
        ->assertUnauthorized();

    $this->getJson('/api/v1/mobile-operations/due-repayments')
        ->assertUnauthorized();

    $this->getJson('/api/v1/mobile-operations/scheduled-deductions/1/runs')
        ->assertUnauthorized();

    $this->postJson('/api/v1/mobile-operations/scheduled-deductions/1/disable', [
        'status' => 'disabled',
    ])->assertUnauthorized();
});
