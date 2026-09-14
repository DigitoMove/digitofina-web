<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('returns mobile friendly paginated and syncable list responses', function () {
    [$user, $token, $branch] = mobileApiUser(['manage-clients']);
    $updatedSince = now()->subDay()->toIso8601String();

    Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $branch->id,
        'client_number' => 'MBL-0001',
        'client_type' => 'individual',
        'first_name' => 'Amina',
        'last_name' => 'Nakato',
        'phone' => '+256701000001',
        'address' => 'Kampala Road',
        'kyc_status' => 'verified',
        'status' => 'active',
        'registered_at' => today(),
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $branch->id,
        'client_number' => 'MBL-0002',
        'client_type' => 'individual',
        'first_name' => 'Brian',
        'last_name' => 'Okello',
        'phone' => '+256701000002',
        'address' => 'Gulu Road',
        'kyc_status' => 'pending',
        'status' => 'active',
        'registered_at' => today(),
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $this->withToken($token)
        ->getJson('/api/v1/clients?per_page=1&sort=-updated_at&updated_since='.rawurlencode($updatedSince))
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.pagination.per_page', 1)
        ->assertJsonPath('meta.query.view', 'list')
        ->assertJsonPath('meta.sync.requested_updated_since', $updatedSince)
        ->assertJsonPath('data.0.client_number', 'MBL-0002')
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'client_number', 'full_name', 'status', 'created_at', 'updated_at'],
            ],
            'meta' => ['pagination', 'query', 'sync', 'server_time', 'api_version'],
        ])
        ->assertJsonMissingPath('data.0.address');
});

it('registers and revokes push notification device tokens', function () {
    [$user, $token] = mobileApiUser(['manage-clients']);
    $modules = app(ModuleService::class);
    $modules->syncDefaults($user->organization);
    $modules->enable($user->organization, Module::where('slug', 'sms')->firstOrFail(), $user);

    $deviceId = $this->withToken($token)
        ->postJson('/api/v1/notifications/devices', [
            'device_id' => 'field-phone-001',
            'platform' => 'android',
            'provider' => 'fcm',
            'push_token' => 'fcm-token-example',
            'device_name' => 'Field phone',
            'device_model' => 'Pixel 8',
            'os_version' => 'Android 15',
            'app_version' => '1.0.0',
            'locale' => 'en_UG',
            'timezone' => 'Africa/Kampala',
            'capabilities' => ['push', 'background_sync'],
        ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.device_id', 'field-phone-001')
        ->assertJsonPath('data.platform', 'android')
        ->json('data.id');

    $this->withToken($token)
        ->getJson('/api/v1/notifications/devices?per_page=10')
        ->assertSuccessful()
        ->assertJsonPath('data.0.device_id', 'field-phone-001')
        ->assertJsonStructure(['meta' => ['pagination', 'query', 'sync']]);

    $revokedAt = $this->withToken($token)
        ->deleteJson("/api/v1/notifications/devices/{$deviceId}")
        ->assertSuccessful()
        ->json('data.revoked_at');

    expect($revokedAt)->toBeString()->not->toBeEmpty();
});

it('keeps notification device registration gated by the company notification module', function () {
    [$user, $token] = mobileApiUser(['manage-clients']);

    $this->withToken($token)->postJson('/api/v1/notifications/devices', [
        'device_id' => 'disabled-device',
        'platform' => 'android',
        'push_token' => 'disabled-token',
    ])->assertForbidden()->assertJsonPath('errors.module', 'sms');

    expect($user->deviceTokens()->count())->toBe(0);
});

/**
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: string, 2: Branch}
 */
function mobileApiUser(array $permissions): array
{
    $organization = Organization::create(['name' => 'Mobile SACCO', 'slug' => fake()->unique()->slug()]);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => fake()->unique()->numerify('####'), 'name' => 'Mobile Branch', 'is_active' => true, 'status' => 'active']);
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
        'device_name' => 'Flutter test client',
    ])->json('data.access_token');

    return [$user, $token, $branch];
}
