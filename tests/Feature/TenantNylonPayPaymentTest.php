<?php

use App\Models\Branch;
use App\Models\NylonPayCredential;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps Nylon Pay collection credentials and webhooks independent per organization', function () {
    [$organization, $admin] = tenantNylonAdmin('Alpha SACCO', 'alpha-secret-for-webhooks');

    $this->actingAs($admin)->put(route('organization.nylon-pay.update'), [
        'api_key' => 'npk_live_alpha',
        'api_secret' => 'nps_live_alpha',
        'webhook_secret' => 'alpha-secret-for-webhooks',
        'base_url' => 'https://api.nylonpay.nilesquad.com/api/services',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $credential = NylonPayCredential::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($credential->scope_key)->toBe("organization:{$organization->id}")
        ->and($credential->webhook_token)->toHaveLength(48)
        ->and($credential->api_key)->toBe('npk_live_alpha');

    [$otherOrganization, $otherAdmin] = tenantNylonAdmin('Beta SACCO', 'beta-secret-for-webhooks');
    $this->actingAs($otherAdmin)->put(route('organization.nylon-pay.update'), [
        'api_key' => 'npk_live_beta',
        'api_secret' => 'nps_live_beta',
        'webhook_secret' => 'beta-secret-for-webhooks',
        'base_url' => 'https://api.nylonpay.nilesquad.com/api/services',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(NylonPayCredential::query()->where('organization_id', $otherOrganization->id)->value('api_key'))->toBe('npk_live_beta');

    $payload = json_encode(['event' => 'success', 'timestamp' => now()->timestamp, 'reference' => 'np260829123456a', 'status' => 'successful', 'amount' => 1000, 'currency' => 'UGX'], JSON_THROW_ON_ERROR);
    $this->call('POST', route('api.v1.webhooks.organization-nylon-pay', $credential->webhook_token), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_NYLON_SIGNATURE' => hash_hmac('sha256', $payload, 'alpha-secret-for-webhooks'),
    ], $payload)->assertSuccessful()->assertJson(['received' => true, 'matched' => false]);

    $this->call('POST', route('api.v1.webhooks.organization-nylon-pay', $credential->webhook_token), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_NYLON_SIGNATURE' => hash_hmac('sha256', $payload, 'beta-secret-for-webhooks'),
    ], $payload)->assertUnauthorized();
});

/** @return array{Organization, User} */
function tenantNylonAdmin(string $name, string $secret): array
{
    $organization = Organization::create(['name' => $name, 'slug' => fake()->unique()->slug(), 'status' => 'active']);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => fake()->unique()->numerify('####'), 'name' => 'Main', 'is_active' => true, 'status' => 'active']);
    $role = Role::query()->firstOrCreate(['slug' => 'organization-admin'], ['name' => 'Organization Admin']);
    $admin = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'role_id' => $role->id, 'status' => 'active', 'is_active' => true]);

    return [$organization, $admin];
}
