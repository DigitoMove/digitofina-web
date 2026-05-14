<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\WebhookProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('stores signed incoming webhooks and processes them once', function () {
    $payload = [
        'event_id' => 'evt-001',
        'reference' => 'PAY-001',
        'status' => 'successful',
        'amount' => 25000,
    ];
    $rawPayload = json_encode($payload);
    $secret = 'provider-secret';

    WebhookProvider::create([
        'name' => 'MTN Webhooks',
        'code' => 'mtn-webhooks',
        'source_type' => 'mobile_money',
        'signature_header' => 'X-Signature',
        'signature_algorithm' => 'sha256',
        'signing_secret' => $secret,
        'requires_signature' => true,
        'status' => 'active',
    ]);

    $this->withHeaders([
        'Content-Type' => 'application/json',
        'X-Signature' => 'sha256='.hash_hmac('sha256', $rawPayload, $secret),
        'X-Event-Id' => 'evt-001',
    ])->postJson('/api/v1/webhooks/mobile-money/mtn-webhooks', $payload)
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    $event = WebhookEvent::firstOrFail();

    expect($event->provider_name)->toBe('MTN Webhooks')
        ->and($event->source_type)->toBe('mobile_money')
        ->and($event->event_id)->toBe('evt-001')
        ->and($event->signature_valid)->toBeTrue()
        ->and($event->status)->toBe('processed')
        ->and($event->raw_payload)->toBe($rawPayload)
        ->and($event->headers)->toHaveKey('x-signature')
        ->and($event->attempts)->toBe(1)
        ->and($event->processed_at)->not->toBeNull();

    $this->withHeaders([
        'Content-Type' => 'application/json',
        'X-Signature' => 'sha256='.hash_hmac('sha256', $rawPayload, $secret),
        'X-Event-Id' => 'evt-001',
    ])->postJson('/api/v1/webhooks/mobile-money/mtn-webhooks', $payload)
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'duplicate')
        ->assertJsonPath('data.duplicate_of_id', $event->id);

    expect(WebhookEvent::count())->toBe(2)
        ->and(WebhookEvent::latest('id')->first()->status)->toBe('duplicate')
        ->and(WebhookEvent::latest('id')->first()->attempts)->toBe(0);
});

it('rejects webhooks with invalid provider signatures but stores the raw delivery', function () {
    WebhookProvider::create([
        'name' => 'Bank Webhooks',
        'code' => 'bank-one',
        'source_type' => 'bank',
        'signature_header' => 'X-Bank-Signature',
        'signing_secret' => 'bank-secret',
        'requires_signature' => true,
        'status' => 'active',
    ]);

    $this->withHeaders([
        'Content-Type' => 'application/json',
        'X-Bank-Signature' => 'sha256=bad-signature',
    ])->postJson('/api/v1/webhooks/bank/bank-one', [
        'event_id' => 'bank-event-1',
        'status' => 'successful',
    ])
        ->assertUnauthorized()
        ->assertJsonPath('success', false);

    $event = WebhookEvent::firstOrFail();

    expect($event->status)->toBe('rejected')
        ->and($event->signature_valid)->toBeFalse()
        ->and($event->error_message)->toBe('Webhook signature verification failed.')
        ->and($event->raw_payload)->not->toBeEmpty();
});

it('queues failed webhook events for retry', function () {
    [$token] = webhookSystemUser(['manage-settings']);
    $event = WebhookEvent::create([
        'source_type' => 'partner',
        'provider_name' => 'Partner API',
        'provider_code' => 'partner-api',
        'event_id' => 'partner-1',
        'duplicate_key' => hash('sha256', 'partner-api|partner-1'),
        'payload_hash' => hash('sha256', '{}'),
        'headers' => [],
        'raw_payload' => '{}',
        'payload' => [],
        'status' => 'failed',
        'attempts' => 1,
        'error_message' => 'Adapter failed',
        'next_retry_at' => now()->addMinute(),
    ]);

    $this->withToken($token)
        ->postJson("/api/v1/webhooks/events/{$event->id}/retry")
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    expect($event->fresh()->status)->toBe('processed')
        ->and($event->fresh()->attempts)->toBe(2)
        ->and($event->fresh()->error_message)->toBeNull();
});

/**
 * @param  array<int, string>  $permissions
 * @return array{0: string, 1: User}
 */
function webhookSystemUser(array $permissions): array
{
    $organization = Organization::create(['name' => 'Webhook SACCO', 'slug' => fake()->unique()->slug()]);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => fake()->unique()->numerify('####'), 'name' => 'Webhook Branch', 'is_active' => true, 'status' => 'active']);
    $role = Role::create(['organization_id' => $organization->id, 'name' => fake()->unique()->jobTitle(), 'slug' => fake()->unique()->slug()]);

    foreach ($permissions as $permission) {
        $permissionModel = Permission::firstOrCreate(['slug' => $permission], ['organization_id' => $organization->id, 'name' => str($permission)->headline()]);
        $role->permissions()->syncWithoutDetaching([$permissionModel->id]);
    }

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'password' => Hash::make('password'),
        'status' => 'active',
        'is_active' => true,
    ]);

    $token = test()->postJson('/api/v1/auth/login', [
        'login' => $user->email,
        'password' => 'password',
        'device_name' => 'Webhook test client',
    ])->json('data.access_token');

    return [$token, $user];
}
