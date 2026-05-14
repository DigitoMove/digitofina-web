<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates audit logs for successful mutating api actions', function () {
    [$user, $token, $branch] = auditApiUser(['manage-clients']);

    $clientId = $this->withHeaders([
        'User-Agent' => 'DigitoFina Flutter App',
        'X-Device-Name' => 'Field Officer Phone',
    ])
        ->withToken($token)
        ->postJson('/api/v1/clients', [
            'branch_id' => $branch->id,
            'client_type' => 'individual',
            'first_name' => 'Sarah',
            'last_name' => 'Namara',
            'phone' => '+256701555111',
            'kyc_status' => 'pending',
            'status' => 'active',
        ])
        ->assertCreated()
        ->json('data.id');

    $log = AuditLog::query()
        ->where('action', 'api.v1.clients.store')
        ->latest('id')
        ->firstOrFail();

    expect($log->user_id)->toBe($user->id)
        ->and($log->organization_id)->toBe($user->organization_id)
        ->and($log->branch_id)->toBe($branch->id)
        ->and($log->endpoint)->toBe('api/v1/clients')
        ->and($log->request_method)->toBe('POST')
        ->and($log->record_type)->toBe('client')
        ->and($log->record_id)->toBe($clientId)
        ->and($log->ip_address)->not->toBeEmpty()
        ->and($log->user_agent)->toBe('DigitoFina Flutter App')
        ->and($log->device_name)->toBe('Field Officer Phone')
        ->and($log->response_status)->toBe(201)
        ->and($log->old_values)->toBeNull()
        ->and($log->new_values['first_name'])->toBe('Sarah')
        ->and($log->created_at)->not->toBeNull();
});

/**
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: string, 2: Branch}
 */
function auditApiUser(array $permissions): array
{
    $organization = Organization::create(['name' => 'Audit SACCO', 'slug' => fake()->unique()->slug()]);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => fake()->unique()->numerify('####'), 'name' => 'Audit Branch', 'is_active' => true, 'status' => 'active']);
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
        'device_name' => 'Audit test client',
    ])->json('data.access_token');

    return [$user, $token, $branch];
}
