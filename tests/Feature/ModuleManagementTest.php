<?php

use App\Models\Branch;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\ReportCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('blocks disabled module api routes for an organization', function () {
    $user = moduleManagementUser(['view-loans']);

    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($user->organization);

    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/loans')
        ->assertForbidden()
        ->assertJsonPath('success', false);
});

it('prevents disabled module permissions from granting access', function () {
    $user = moduleManagementUser(['manage-loans']);

    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($user->organization);

    expect($user->fresh()->canPerform('manage-loans'))->toBeFalse();
});

it('filters disabled module reports from the catalog', function () {
    $user = moduleManagementUser(['view-reports']);
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($user->organization);

    $reportSlugs = collect(app(ReportCatalogService::class)->definitions($user))->pluck('slug');

    expect($reportSlugs)
        ->toContain('client-register')
        ->not->toContain('loan-book')
        ->not->toContain('opened-savings-accounts');
});

it('prevents disabling modules required by enabled dependents', function () {
    $user = moduleManagementUser(['manage-organization']);
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($user->organization);

    $modules->enable($user->organization, Module::where('slug', 'savings')->firstOrFail(), $user);

    expect(fn () => $modules->disable($user->organization, Module::where('slug', 'clients')->firstOrFail(), $user))
        ->toThrow(InvalidArgumentException::class);
});

function moduleManagementUser(array $permissions): User
{
    $organization = Organization::create([
        'name' => 'Module Tenant',
        'slug' => fake()->unique()->slug(),
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => fake()->unique()->numerify('####'),
        'name' => 'Main Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => fake()->unique()->jobTitle(),
        'slug' => fake()->unique()->slug(),
    ]);

    foreach ($permissions as $permission) {
        $permissionModel = Permission::firstOrCreate(
            ['slug' => $permission],
            ['organization_id' => $organization->id, 'name' => str($permission)->headline()]
        );
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
