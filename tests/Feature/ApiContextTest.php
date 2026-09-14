<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function contextUser(string $role, ?Organization $organization = null): User
{
    return User::factory()->create([
        'organization_id' => $organization?->id,
        'role_id' => Role::firstOrCreate(['slug' => $role], ['name' => $role])->id,
        'status' => 'active',
        'is_active' => true,
        'password' => Hash::make('password'),
    ]);
}

it('carries a platform company selection to profile and account queries and can return global', function () {
    $admin = contextUser('super-admin');
    $one = Organization::create(['name' => 'One', 'slug' => 'one', 'status' => 'active']);
    $two = Organization::create(['name' => 'Two', 'slug' => 'two', 'status' => 'active']);
    Branch::create(['organization_id' => $one->id, 'name' => 'One branch', 'code' => 'C101', 'status' => 'active']);
    Branch::create(['organization_id' => $two->id, 'name' => 'Two branch', 'code' => 'C201', 'status' => 'active']);

    $this->withToken($admin->createToken('test')->plainTextToken)
        ->withHeader('X-Organization-Id', (string) $one->id)
        ->getJson('/api/v1/auth/me')->assertSuccessful()
        ->assertJsonPath('data.context.organization_id', $one->id)
        ->assertJsonPath('data.context.is_global', false)
        ->assertJsonPath('data.context.can_choose_company', true);
    $this->getJson('/api/v1/admin/records/branches')->assertSuccessful()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'One branch');
    $this->getJson('/api/v1/admin/dashboard?organization_id='.$two->id)->assertUnprocessable();
    $this->withHeader('X-Organization-Id', 'global')->getJson('/api/v1/auth/me')
        ->assertSuccessful()->assertJsonPath('data.context.organization_id', null)->assertJsonPath('data.context.is_global', true);
});

it('rejects company overrides from staff and malformed scopes', function () {
    $one = Organization::create(['name' => 'One', 'slug' => 'one', 'status' => 'active']);
    $two = Organization::create(['name' => 'Two', 'slug' => 'two', 'status' => 'active']);
    $user = contextUser('organization-admin', $one);
    $this->withToken($user->createToken('test')->plainTextToken)
        ->withHeader('X-Organization-Id', (string) $two->id)->getJson('/api/v1/auth/me')->assertForbidden();
    $this->withHeader('X-Organization-Id', 'global')->getJson('/api/v1/auth/me')->assertForbidden();
    $this->withHeader('X-Organization-Id', 'invalid')->getJson('/api/v1/auth/me')->assertUnprocessable();
    $this->withHeader('X-Organization-Id', (string) $one->id)->getJson('/api/v1/auth/me')
        ->assertSuccessful()->assertJsonPath('data.context.can_choose_company', false);
});

it('rejects an unassigned staff login before creating a token', function () {
    $user = contextUser('loan-officer');
    $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'password'])
        ->assertUnprocessable()->assertJsonValidationErrors('login');
    expect($user->tokens()->count())->toBe(0);
});

it('rejects a deactivated user even with a previously issued token', function () {
    $one = Organization::create(['name' => 'One', 'slug' => 'one', 'status' => 'active']);
    $user = contextUser('organization-admin', $one);
    $token = $user->createToken('test')->plainTextToken;
    $user->update(['is_active' => false]);
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('refreshes custom role permissions and reports branch scope', function () {
    $one = Organization::create(['name' => 'One', 'slug' => 'one', 'status' => 'active']);
    $user = contextUser('custom-clerk', $one);
    $branch = Branch::create(['organization_id' => $one->id, 'name' => 'Branch', 'code' => 'C101', 'status' => 'active']);
    $user->update(['branch_id' => $branch->id]);
    $permission = Permission::firstOrCreate(['slug' => 'manage-clients'], ['name' => 'Manage clients']);
    $user->role->permissions()->attach($permission);
    $token = $user->createToken('test')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertSuccessful()
        ->assertJsonPath('data.context.branch_id', $branch->id)
        ->assertJsonPath('data.context.can_choose_branch', false)
        ->assertJsonPath('data.capabilities.create_clients', true)
        ->assertJsonFragment(['effective_permissions' => ['manage-clients']]);
    $user->role->permissions()->detach();
    Auth::forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertSuccessful()
        ->assertJsonPath('data.capabilities.create_clients', false)
        ->assertJsonCount(0, 'data.effective_permissions');
});
