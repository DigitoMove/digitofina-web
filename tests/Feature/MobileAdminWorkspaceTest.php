<?php

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function mobileAdmin(string $slug, ?Organization $organization = null): User
{
    $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);

    return User::factory()->create([
        'role_id' => $role->id, 'organization_id' => $organization?->id,
        'branch_id' => null, 'status' => 'active', 'is_active' => true,
    ]);
}

it('serves the super admin dashboard without a browser session', function () {
    $admin = mobileAdmin('super-admin');
    Organization::create(['name' => 'First tenant', 'slug' => 'first-tenant', 'status' => 'active']);
    Organization::create(['name' => 'Second tenant', 'slug' => 'second-tenant', 'status' => 'active']);
    $token = $admin->createToken('mobile')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/admin/dashboard')
        ->assertOk()->assertJsonPath('data.role', 'super-admin')
        ->assertJsonPath('data.scope', null)
        ->assertJsonPath('data.metrics.0.value', 2);
    foreach ($response->json('data.navigation') as $section) {
        $this->withToken($token)->getJson('/api/v1/admin/records/'.$section['key'].'?search=example')
            ->assertOk()->assertJsonStructure(['data', 'meta' => ['pagination']]);
    }
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
});

it('limits organization administrators to their own records and rejects scope tampering', function () {
    $own = Organization::create(['name' => 'Own tenant', 'slug' => 'own']);
    $other = Organization::create(['name' => 'Foreign tenant', 'slug' => 'foreign']);
    $admin = mobileAdmin('organization-admin', $own);
    $foreign = mobileAdmin('organization-admin', $other);
    app(ModuleService::class)->syncDefaults($own);
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.scope.id', $own->id);
    $this->getJson('/api/v1/admin/records/organizations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    $this->getJson('/api/v1/admin/records/users')->assertOk()->assertJsonCount(1, 'data')->assertDontSee($foreign->email);
    $this->getJson('/api/v1/admin/records/users/'.$foreign->id)->assertNotFound();
    $this->getJson('/api/v1/admin/dashboard?organization_id='.$other->id)->assertForbidden();
    $this->getJson('/api/v1/admin/records/packages')->assertForbidden();
});

it('allows super admins to filter tenants without retaining scope across requests', function () {
    $own = Organization::create(['name' => 'Chosen tenant', 'slug' => 'chosen']);
    $other = Organization::create(['name' => 'Other tenant', 'slug' => 'other']);
    mobileAdmin('organization-admin', $own);
    mobileAdmin('organization-admin', $other);
    app(ModuleService::class)->syncDefaults($own);
    Sanctum::actingAs(mobileAdmin('super-admin'));
    $this->getJson('/api/v1/admin/records/organizations?organization_id='.$own->id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    $this->getJson('/api/v1/admin/records/organizations/'.$other->id.'?organization_id='.$own->id)->assertNotFound();
    $this->getJson('/api/v1/admin/records/organizations')->assertOk()->assertJsonCount(2, 'data');
});

it('protects admin routes from staff members clients and guests', function () {
    $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    $org = Organization::create(['name' => 'Staff tenant', 'slug' => 'staff']);
    foreach (['loan-officer', 'member'] as $role) {
        Sanctum::actingAs(mobileAdmin($role, $org));
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->getJson('/api/v1/admin/records/organizations')->assertForbidden();
    }
});

it('returns paginated searchable records without exposing secrets', function () {
    $org = Organization::create(['name' => 'Directory tenant', 'slug' => 'directory']);
    $admin = mobileAdmin('super-admin');
    for ($i = 0; $i < 23; $i++) {
        $user = mobileAdmin('organization-admin', $org);
        $user->update(['name' => 'Directory user '.$i]);
    }
    Sanctum::actingAs($admin);
    $this->getJson('/api/v1/admin/records/users')->assertOk()
        ->assertJsonCount(20, 'data')->assertJsonPath('meta.pagination.total', 24)
        ->assertDontSee('remember_token')->assertDontSee('access_token');
    $this->getJson('/api/v1/admin/records/users?search=Directory%20user%2022')
        ->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/records/users/'.$admin->id)->assertOk()->assertJsonMissingPath('data.fields.password');
});

it('enforces module visibility on both overview and direct record routes', function () {
    $org = Organization::create(['name' => 'Limited tenant', 'slug' => 'limited']);
    Sanctum::actingAs(mobileAdmin('organization-admin', $org));
    app(ModuleService::class)->syncDefaults($org);
    $response = $this->getJson('/api/v1/admin/dashboard')->assertOk();
    expect(collect($response->json('data.navigation'))->pluck('key')->all())->not->toContain('loans');
    $this->getJson('/api/v1/admin/records/loans')->assertForbidden();
    $this->getJson('/api/v1/admin/records/not-a-section')->assertNotFound();
});
