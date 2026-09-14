<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Module;
use App\Models\OfflineSyncBatch;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('accepts offline client captures with local ids and returns server references', function () {
    [, $token, $branch] = offlineSyncApiUser(['manage-clients']);

    $response = $this->withToken($token)
        ->postJson('/api/v1/sync/clients', [
            'client_batch_id' => 'phone-001-batch-001',
            'device_name' => 'Field phone',
            'records' => [[
                'action' => 'create',
                'local_temp_id' => 'local-client-001',
                'client_updated_at' => now()->subMinutes(5)->toIso8601String(),
                'payload' => [
                    'branch_id' => $branch->id,
                    'client_type' => 'individual',
                    'first_name' => 'Sarah',
                    'last_name' => 'Auma',
                    'phone' => '+256700900001',
                    'status' => 'pending',
                    'kyc_status' => 'pending',
                    'district' => 'Luweero',
                ],
            ]],
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.summary.accepted', 1)
        ->assertJsonPath('data.records.0.local_temp_id', 'local-client-001')
        ->assertJsonPath('data.records.0.status', 'accepted')
        ->assertJsonPath('data.records.0.response.local_temp_id', 'local-client-001')
        ->assertJsonStructure(['data' => ['records' => [['response' => ['server_id', 'client_number', 'sync_version', 'server_updated_at']]]]]);

    $client = Client::firstWhere('local_temp_id', 'local-client-001');

    expect($client)->not->toBeNull()
        ->and($client->client_number)->not->toBeEmpty()
        ->and($client->sync_source)->toBe('offline_mobile')
        ->and($client->synced_at)->not->toBeNull();

    $this->withToken($token)
        ->postJson('/api/v1/sync/clients', [
            'client_batch_id' => 'phone-001-batch-001',
            'device_name' => 'Field phone',
            'records' => [[
                'action' => 'create',
                'local_temp_id' => 'local-client-001',
                'client_updated_at' => now()->subMinutes(5)->toIso8601String(),
                'payload' => [
                    'branch_id' => $branch->id,
                    'client_type' => 'individual',
                    'first_name' => 'Sarah',
                    'last_name' => 'Auma',
                    'phone' => '+256700900001',
                    'status' => 'pending',
                    'kyc_status' => 'pending',
                    'district' => 'Luweero',
                ],
            ]],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.summary.accepted', 1);

    expect(OfflineSyncBatch::count())->toBe(1)
        ->and(Client::where('local_temp_id', 'local-client-001')->count())->toBe(1);
});

it('detects conflicts when a mobile update is based on an old server copy', function () {
    [$user, $token, $branch] = offlineSyncApiUser(['manage-clients']);
    $client = Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $branch->id,
        'client_number' => '1001-C900001',
        'client_type' => 'individual',
        'first_name' => 'Jane',
        'last_name' => 'Adoch',
        'phone' => '+256700900002',
        'status' => 'active',
        'kyc_status' => 'verified',
        'updated_at' => now(),
    ]);

    $this->withToken($token)
        ->postJson('/api/v1/sync/clients', [
            'client_batch_id' => 'phone-001-batch-002',
            'records' => [[
                'action' => 'update',
                'local_temp_id' => 'local-client-002',
                'server_id' => $client->id,
                'server_updated_at' => now()->subHour()->toIso8601String(),
                'client_updated_at' => now()->toIso8601String(),
                'payload' => [
                    'branch_id' => $branch->id,
                    'client_type' => 'individual',
                    'first_name' => 'Jane',
                    'last_name' => 'Adoch',
                    'phone' => '+256700999999',
                    'status' => 'active',
                    'kyc_status' => 'verified',
                ],
            ]],
        ])
        ->assertStatus(207)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.summary.conflicts', 1)
        ->assertJsonPath('data.records.0.status', 'conflict')
        ->assertJsonPath('data.records.0.conflict.reason', 'server_newer_than_mobile_snapshot');

    expect($client->fresh()->phone)->toBe('+256700900002');
});

it('blocks offline sync without the company API access module', function () {
    [, $token] = offlineSyncApiUser(['manage-clients'], false);

    $this->withToken($token)->postJson('/api/v1/sync/clients', [
        'client_batch_id' => 'disabled-sync',
        'records' => [],
    ])->assertForbidden()->assertJsonPath('errors.module', 'api-access');

    expect(OfflineSyncBatch::count())->toBe(0);
});

/**
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: string, 2: Branch}
 */
function offlineSyncApiUser(array $permissions, bool $enableSync = true): array
{
    $organization = Organization::create(['name' => 'Offline SACCO', 'slug' => fake()->unique()->slug()]);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => fake()->unique()->numerify('####'), 'name' => 'Offline Branch', 'is_active' => true, 'status' => 'active']);
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
    $modules = app(ModuleService::class);
    $modules->syncDefaults($organization);
    if ($enableSync) {
        $modules->enable($organization, Module::where('slug', 'api-access')->firstOrFail(), $user);
    }

    $token = test()->postJson('/api/v1/auth/login', [
        'login' => $user->email,
        'password' => 'password',
        'device_name' => 'Offline Flutter test client',
    ])->json('data.access_token');

    return [$user, $token, $branch];
}
