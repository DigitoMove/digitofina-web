<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

function impersonationAccounts(): array
{
    $organization = Organization::create(['name' => 'Impersonation tenant', 'slug' => 'impersonation', 'status' => 'active']);
    $admin = User::factory()->create(['role_id' => Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin'])->id, 'is_active' => true, 'status' => 'active']);
    $cashier = User::factory()->create(['organization_id' => $organization->id, 'role_id' => Role::firstOrCreate(['slug' => 'teller-cashier'], ['name' => 'Cashier'])->id, 'is_active' => true, 'status' => 'active']);

    return [$admin, $cashier];
}

it('lets a super admin find users and see their profile without replacing the admin token', function () {
    [$admin, $cashier] = impersonationAccounts();
    $token = $admin->createToken('mobile')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/admin/impersonation/users?role=teller-cashier')->assertOk()->assertJsonPath('data.users.0.id', $cashier->id);
    Auth::forgetGuards();
    $this->withHeader('X-Impersonate-User', (string) $cashier->id)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $cashier->id)->assertJsonPath('data.role', 'teller-cashier');
    Auth::forgetGuards();
    $this->withoutHeader('X-Impersonate-User')->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $admin->id);
    expect($admin->tokens()->count())->toBe(1);
});

it('rejects staff impersonation headers and inactive targets', function () {
    [$admin, $cashier] = impersonationAccounts();
    $this->withToken($cashier->createToken('staff')->plainTextToken)->withHeader('X-Impersonate-User', (string) $admin->id)->getJson('/api/v1/auth/me')->assertForbidden();
    Auth::forgetGuards();
    $cashier->update(['is_active' => false]);
    $this->withToken($admin->createToken('admin')->plainTextToken)->withHeader('X-Impersonate-User', (string) $cashier->id)->getJson('/api/v1/auth/me')->assertUnprocessable();
});

it('uses cashier permissions and blocks authentication changes while impersonating', function () {
    [$admin, $cashier] = impersonationAccounts();
    $this->withToken($admin->createToken('admin')->plainTextToken)->withHeader('X-Impersonate-User', (string) $cashier->id)->getJson('/api/v1/admin/dashboard')->assertForbidden();
    Auth::forgetGuards();
    $this->postJson('/api/v1/auth/refresh')->assertForbidden();
    expect($cashier->tokens()->count())->toBe(0);
});

it('records the administrator alongside the impersonated user', function () {
    [$admin, $cashier] = impersonationAccounts();
    $this->withToken($admin->createToken('admin')->plainTextToken)->withHeader('X-Impersonate-User', (string) $cashier->id)->getJson('/api/v1/auth/me')->assertOk();
    $audit = AuditLog::where('action', 'api.impersonation.accessed')->firstOrFail();
    expect($audit->user_id)->toBe($cashier->id)
        ->and($audit->new_values['_impersonation']['impersonator_user_id'])->toBe($admin->id);
});
