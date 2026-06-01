<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists client groups when the branch has been deleted', function () {
    $organization = Organization::create([
        'name' => 'Test Organization',
        'slug' => 'test-organization',
        'status' => 'active',
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'name' => 'Central Branch',
        'code' => 'CB',
        'is_active' => true,
    ]);
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Super Admin',
        'slug' => 'super-admin',
    ]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
    ]);
    $member = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => 'C-001',
        'first_name' => 'Grace',
        'last_name' => 'Akello',
        'phone' => '+256700000001',
        'status' => 'active',
    ]);
    $group = ClientGroup::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'group_number' => 'G-001',
        'name' => 'Market Women Group',
        'status' => 'active',
    ]);
    $group->members()->attach($member, [
        'role' => 'member',
        'joined_at' => now()->toDateString(),
        'status' => 'active',
    ]);

    $branch->delete();

    $this->actingAs($user)
        ->get(route('client-groups.index'))
        ->assertOk()
        ->assertSee('Market Women Group')
        ->assertSee('Central Branch')
        ->assertSee('Deleted branch')
        ->assertSee('1');
});
