<?php

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('issues a sanctum token and returns the authenticated user profile', function () {
    $branch = Branch::create(['code' => '1001', 'name' => 'Luweero', 'is_active' => true, 'status' => 'active']);
    $permission = Permission::create(['name' => 'Manage Clients', 'slug' => 'manage-clients']);
    $role = Role::create(['name' => 'Officer', 'slug' => 'officer']);
    $role->permissions()->sync([$permission->id]);

    $user = User::factory()->create([
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
        ->assertJsonPath('token_type', 'Bearer')
        ->json('access_token');

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertSuccessful()
        ->assertJsonPath('email', 'api@digitofina.test')
        ->assertJsonPath('permissions.0', 'manage-clients');
});
