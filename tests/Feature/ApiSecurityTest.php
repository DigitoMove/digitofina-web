<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('issues permission-scoped tokens and records device metadata', function () {
    $user = securedUser(['manage-clients']);

    $token = $this->withHeaders([
        'X-Device-Id' => 'field-tablet-001',
        'User-Agent' => 'DigitoFina Field App',
    ])->postJson('/api/v1/auth/login', [
        'login' => $user->email,
        'password' => 'password',
        'device_name' => 'Field tablet',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->json('data.access_token');

    expect($token)->toBeString()->not->toBeEmpty();

    $storedToken = DB::table('personal_access_tokens')->first();

    expect(json_decode($storedToken->abilities, true))
        ->toContain('manage-clients')
        ->not->toContain('*')
        ->and($storedToken->device_id)->toBe('field-tablet-001')
        ->and($storedToken->user_agent)->toBe('DigitoFina Field App');
});

it('blocks branch level access to savings accounts outside the authenticated user branch', function () {
    $organization = Organization::create(['name' => 'Tenant SACCO', 'slug' => 'tenant-sacco']);
    $homeBranch = Branch::create(['organization_id' => $organization->id, 'code' => '1001', 'name' => 'Home', 'is_active' => true, 'status' => 'active']);
    $otherBranch = Branch::create(['organization_id' => $organization->id, 'code' => '2001', 'name' => 'Other', 'is_active' => true, 'status' => 'active']);
    $user = securedUser(['manage-accounts'], $organization, $homeBranch);
    $product = SavingsProduct::create([
        'organization_id' => $organization->id,
        'name' => 'Regular Savings',
        'code' => 'REG',
        'minimum_opening_balance' => 0,
        'minimum_balance' => 0,
        'interest_rate' => 0,
        'interest_posting_frequency' => 'monthly',
        'withdrawal_charge' => 0,
        'deposit_charge' => 0,
        'monthly_maintenance_fee' => 0,
        'account_number_prefix' => 'SAV',
        'allows_overdraft' => false,
        'status' => 'active',
    ]);
    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $otherBranch->id,
        'client_number' => '2001-C000001',
        'client_type' => 'individual',
        'first_name' => 'Jane',
        'last_name' => 'Away',
        'phone' => '+256700000009',
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $account = SavingsAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => $otherBranch->id,
        'client_id' => $client->id,
        'savings_product_id' => $product->id,
        'account_number' => '2001-SAV-000001',
        'opening_date' => today(),
        'current_balance' => 100000,
        'available_balance' => 100000,
        'status' => 'active',
    ]);

    $token = $this->postJson('/api/v1/auth/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->json('data.access_token');

    $this->withToken($token)
        ->getJson("/api/v1/savings/accounts/{$account->id}")
        ->assertForbidden()
        ->assertJsonPath('success', false);
});

function securedUser(array $permissions, ?Organization $organization = null, ?Branch $branch = null): User
{
    $organization ??= Organization::create(['name' => 'Security SACCO', 'slug' => fake()->unique()->slug()]);
    $branch ??= Branch::create(['organization_id' => $organization->id, 'code' => fake()->unique()->numerify('####'), 'name' => 'Security Branch', 'is_active' => true, 'status' => 'active']);
    $role = Role::create(['organization_id' => $organization->id, 'name' => fake()->unique()->jobTitle(), 'slug' => fake()->unique()->slug()]);

    foreach ($permissions as $permission) {
        $permissionModel = Permission::firstOrCreate(['slug' => $permission], ['organization_id' => $organization->id, 'name' => str($permission)->headline()]);
        $role->permissions()->syncWithoutDetaching([$permissionModel->id]);
    }

    return User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'password' => Hash::make('password'),
        'status' => 'active',
        'is_active' => true,
    ]);
}
