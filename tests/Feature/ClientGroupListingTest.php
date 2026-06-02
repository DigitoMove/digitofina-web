<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

it('keeps client groups and clients scoped to the logged in organization', function () {
    [$organizationA, $branchA, $userA] = clientGroupTenantUser();
    [$organizationB, $branchB] = clientGroupTenantShell('Other Organization');
    $clientA = clientGroupTenantClient($organizationA, $branchA, 'Client A');
    $clientB = clientGroupTenantClient($organizationB, $branchB, 'Client B');

    ClientGroup::create([
        'organization_id' => $organizationA->id,
        'branch_id' => $branchA->id,
        'group_number' => 'ORG-A-GROUP',
        'name' => 'Organization A Group',
        'status' => 'active',
    ])->members()->attach($clientA, [
        'role' => 'member',
        'joined_at' => now()->toDateString(),
        'status' => 'active',
    ]);
    ClientGroup::create([
        'organization_id' => $organizationB->id,
        'branch_id' => $branchB->id,
        'group_number' => 'ORG-B-GROUP',
        'name' => 'Organization B Group',
        'status' => 'active',
    ])->members()->attach($clientB, [
        'role' => 'member',
        'joined_at' => now()->toDateString(),
        'status' => 'active',
    ]);

    $response = $this->actingAs($userA)
        ->get(route('client-groups.index'))
        ->assertOk()
        ->assertSee('Organization A Group')
        ->assertDontSee('Organization B Group');

    expect(Client::query()->pluck('first_name')->all())
        ->toContain('Client A')
        ->not->toContain('Client B')
        ->and($response->getContent())->toContain('Organization A Group');
});

it('does not allow another organization client to be attached to a group', function () {
    [$organizationA, $branchA, $userA] = clientGroupTenantUser();
    [$organizationB, $branchB] = clientGroupTenantShell('Other Organization');
    $clientB = clientGroupTenantClient($organizationB, $branchB, 'Outside');

    $this->actingAs($userA)
        ->from(route('client-groups.create'))
        ->post(route('client-groups.store'), [
            'branch_id' => $branchA->id,
            'group_number' => 'ORG-A-CROSS-MEMBER',
            'name' => 'Cross Member Group',
            'member_ids' => [$clientB->id],
            'status' => 'active',
        ])
        ->assertRedirect(route('client-groups.create'))
        ->assertSessionHasErrors('member_ids.0');

    expect(ClientGroup::query()->where('group_number', 'ORG-A-CROSS-MEMBER')->exists())->toBeFalse();
});

it('attaches new groups and memberships to the selected branch organization', function () {
    [$organizationA, $branchA, $userA] = clientGroupTenantUser();
    $clientA = clientGroupTenantClient($organizationA, $branchA, 'Inside');

    $this->actingAs($userA)
        ->post(route('client-groups.store'), [
            'branch_id' => $branchA->id,
            'group_number' => 'ORG-A-VALID-GROUP',
            'name' => 'Valid Group',
            'member_ids' => [$clientA->id],
            'status' => 'active',
        ])
        ->assertRedirect();

    $group = ClientGroup::query()->where('group_number', 'ORG-A-VALID-GROUP')->firstOrFail();

    expect((int) $group->organization_id)->toBe((int) $organizationA->id)
        ->and((int) DB::table('client_group_members')
            ->where('client_group_id', $group->id)
            ->where('client_id', $clientA->id)
            ->value('organization_id'))->toBe((int) $organizationA->id);
});

/**
 * @return array{0: Organization, 1: Branch, 2: User}
 */
function clientGroupTenantUser(): array
{
    [$organization, $branch] = clientGroupTenantShell('Client Group Organization');
    $permission = Permission::firstOrCreate(
        ['slug' => 'manage-clients'],
        ['organization_id' => $organization->id, 'name' => 'Manage Clients']
    );
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Client Manager',
        'slug' => fake()->unique()->slug(),
    ]);
    $role->permissions()->sync([$permission->id]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    app(ModuleService::class)->enable($organization, Module::where('slug', 'clients')->firstOrFail(), $user);

    return [$organization, $branch, $user];
}

/**
 * @return array{0: Organization, 1: Branch}
 */
function clientGroupTenantShell(string $name): array
{
    $organization = Organization::create([
        'name' => $name.' '.fake()->unique()->word(),
        'slug' => fake()->unique()->slug(),
        'status' => 'active',
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'name' => $name.' Branch',
        'code' => fake()->unique()->bothify('CG-###'),
        'is_active' => true,
        'status' => 'active',
    ]);

    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($organization);

    return [$organization, $branch];
}

function clientGroupTenantClient(Organization $organization, Branch $branch, string $firstName): Client
{
    return Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => fake()->unique()->bothify('CG-C-####'),
        'client_type' => 'individual',
        'first_name' => $firstName,
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->numerify('+2567########'),
        'status' => 'active',
        'registered_at' => today(),
    ]);
}
