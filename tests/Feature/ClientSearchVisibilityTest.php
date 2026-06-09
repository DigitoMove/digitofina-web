<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows any branch user to search clients while returning only their branch', function () {
    [$organization, $ownBranch, $otherBranch] = clientSearchOrganization();
    $user = clientSearchUser($organization, $ownBranch, 'member');
    $ownClient = clientSearchClient($organization, $ownBranch, 'Own Branch Match');
    $otherClient = clientSearchClient($organization, $otherBranch, 'Other Branch Match');

    enableClientSearchModule($organization, $user);

    $this->actingAs($user)
        ->get(route('clients.index', ['search' => 'Branch Match']))
        ->assertSuccessful()
        ->assertSee($ownClient->client_number)
        ->assertDontSee($otherClient->client_number);
});

it('allows a branchless user to search all clients in their organization only', function () {
    [$organization, $firstBranch, $secondBranch] = clientSearchOrganization();
    [$otherOrganization, $otherBranch] = clientSearchOrganization('Other Organization');
    $user = clientSearchUser($organization, null, 'branchless-staff');
    $firstClient = clientSearchClient($organization, $firstBranch, 'Organization Match One');
    $secondClient = clientSearchClient($organization, $secondBranch, 'Organization Match Two');
    $outsideClient = clientSearchClient($otherOrganization, $otherBranch, 'Organization Match Outside');

    enableClientSearchModule($organization, $user);

    $this->actingAs($user)
        ->get(route('clients.index', ['search' => 'Organization Match']))
        ->assertSuccessful()
        ->assertSee($firstClient->client_number)
        ->assertSee($secondClient->client_number)
        ->assertDontSee($outsideClient->client_number);
});

/**
 * @return array{0: Organization, 1: Branch, 2: Branch}
 */
function clientSearchOrganization(string $name = 'Search Organization'): array
{
    $organization = Organization::create([
        'name' => $name.' '.fake()->unique()->word(),
        'slug' => fake()->unique()->slug(),
        'status' => 'active',
    ]);
    $firstBranch = Branch::create([
        'organization_id' => $organization->id,
        'name' => 'First Branch '.fake()->unique()->word(),
        'code' => fake()->unique()->bothify('CS-###'),
        'is_active' => true,
        'status' => 'active',
    ]);
    $secondBranch = Branch::create([
        'organization_id' => $organization->id,
        'name' => 'Second Branch '.fake()->unique()->word(),
        'code' => fake()->unique()->bothify('CS-###'),
        'is_active' => true,
        'status' => 'active',
    ]);

    return [$organization, $firstBranch, $secondBranch];
}

function clientSearchUser(Organization $organization, ?Branch $branch, string $roleSlug): User
{
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => str($roleSlug)->headline(),
        'slug' => $roleSlug,
    ]);

    return User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch?->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
}

function clientSearchClient(Organization $organization, Branch $branch, string $firstName): Client
{
    return Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => fake()->unique()->bothify('SEARCH-C-####'),
        'client_type' => 'individual',
        'first_name' => $firstName,
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->numerify('+2567########'),
        'status' => 'active',
        'registered_at' => today(),
    ]);
}

function enableClientSearchModule(Organization $organization, User $user): void
{
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->enable($organization, Module::where('slug', 'clients')->firstOrFail(), $user);
}
