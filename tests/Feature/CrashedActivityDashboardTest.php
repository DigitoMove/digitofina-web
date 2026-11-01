<?php

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('keeps the super admin dashboard available when activity storage fails', function (bool $selectOrganization) {
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $admin = User::factory()->create([
        'role_id' => $role->id,
        'organization_id' => null,
        'branch_id' => null,
        'status' => 'active',
        'is_active' => true,
    ]);
    $organization = Organization::create(['name' => 'Activity Test', 'slug' => 'activity-test']);
    Schema::drop('user_activities');

    $response = $this->actingAs($admin)
        ->withSession($selectOrganization ? ['super_admin_organization_id' => $organization->id] : [])
        ->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Super Admin Dashboard')
        ->assertSee('Activity tracking is temporarily unavailable');
})->with([false, true]);
