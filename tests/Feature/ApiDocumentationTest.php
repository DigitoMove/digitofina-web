<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves openapi documentation for every versioned api route', function () {
    $response = $this->getJson('/api/v1/docs/openapi.json')
        ->assertSuccessful()
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('info.title', 'DigitoFina API')
        ->assertJsonPath('paths./clients.get.x-required-permissions.0', 'manage-clients')
        ->assertJsonPath('paths./savings/accounts/{savingsAccount}/deposit.post.x-idempotency-required', true)
        ->assertJsonPath('paths./savings/accounts/{savingsAccount}/deposit.post.parameters.1.name', 'Idempotency-Key')
        ->assertJsonPath('paths./sync/clients.post.x-required-permissions.0', 'manage-clients')
        ->assertJsonPath('components.securitySchemes.sanctumBearer.scheme', 'bearer');

    $paths = $response->json('paths');

    expect($paths)->toHaveKey('/auth/login')
        ->and($paths)->toHaveKey('/clients/{client}/documents')
        ->and($paths)->toHaveKey('/loans/{loan}/repayments')
        ->and($paths)->toHaveKey('/webhooks/{source}/{provider}')
        ->and($paths)->toHaveKey('/docs/openapi.json');
});
