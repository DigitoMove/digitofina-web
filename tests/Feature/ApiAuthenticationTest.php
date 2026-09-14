<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('issues a sanctum token and returns the authenticated user profile', function () {
    $organization = Organization::create(['name' => 'Authentication SACCO', 'slug' => 'authentication-sacco']);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => '1001', 'name' => 'Luweero', 'is_active' => true, 'status' => 'active']);
    $permission = Permission::create(['name' => 'Manage Clients', 'slug' => 'manage-clients']);
    $role = Role::create(['name' => 'Officer', 'slug' => 'officer']);
    $role->permissions()->sync([$permission->id]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'email' => 'api@digitofina.test',
        'password' => Hash::make('password'),
        'status' => 'active',
        'is_active' => true,
    ]);

    $token = $this->postJson('/api/v1/auth/login', [
        'login' => $user->email,
        'password' => 'password',
        'device_name' => 'Pest',
    ])
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->json('data.access_token');

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email', 'api@digitofina.test')
        ->assertJsonPath('data.permissions.0', 'manage-clients');
});

it('returns standardized validation errors for api requests', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonStructure([
            'success',
            'message',
            'errors' => ['login', 'password'],
        ]);
});

it('denies tenant profile access to staff without an organization', function () {
    $role = Role::create(['name' => 'Officer', 'slug' => 'officer']);
    $user = User::factory()->create([
        'organization_id' => null,
        'branch_id' => null,
        'role_id' => $role->id,
        'password' => Hash::make('password'),
        'status' => 'active',
        'is_active' => true,
    ]);

    $token = $user->createToken('Unassigned staff')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Your account is not assigned to an organization.');
});
