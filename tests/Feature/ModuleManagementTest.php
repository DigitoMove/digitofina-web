<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Client;
use App\Models\DrawerSession;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TellerDrawer;
use App\Models\TransactionType;
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

it('hides disabled module navigation from the application shell', function () {
    $user = moduleManagementUser(['manage-loans', 'post-transactions', 'view-reports']);
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($user->organization);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee('Loan Products')
        ->assertDontSee('Teller Drawer')
        ->assertDontSee('Savings Products');
});

it('shows a teller form error instead of not found when posting without an open drawer', function () {
    $user = moduleManagementUser(['post-transactions']);
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($user->organization);
    $user->organization->modules()->syncWithoutDetaching([
        Module::where('slug', 'teller')->firstOrFail()->id => ['is_enabled' => true, 'enabled_at' => now()],
    ]);
    $client = Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->numerify('+2567########'),
        'status' => 'active',
    ]);
    $accountType = AccountType::create(['name' => 'Savings Account', 'code' => fake()->unique()->bothify('SAV-??'), 'category' => 'savings']);
    $account = Account::create([
        'organization_id' => $user->organization_id,
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'account_type_id' => $accountType->id,
        'account_number' => fake()->unique()->numerify('1001-SAV-####'),
        'account_name' => $client->full_name.' Savings',
        'status' => 'active',
        'balance' => 0,
        'opened_at' => today(),
    ]);
    $transactionType = TransactionType::create(['name' => 'Deposit', 'code' => fake()->unique()->bothify('deposit-??'), 'direction' => 'credit']);

    $this->actingAs($user)
        ->from(route('teller.index'))
        ->post(route('teller.post'), [
            'account_scope' => 'client_account',
            'account_number' => $account->account_number,
            'transaction_type_id' => $transactionType->id,
            'amount' => 1000,
            'transaction_date' => today()->toDateString(),
        ])
        ->assertRedirect(route('teller.index'))
        ->assertSessionHasErrors(['amount' => 'Open your teller drawer before posting a transaction.']);
});

it('shows a teller form error when the open drawer is unavailable', function () {
    $user = moduleManagementUser(['post-transactions']);
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($user->organization);
    $user->organization->modules()->syncWithoutDetaching([
        Module::where('slug', 'teller')->firstOrFail()->id => ['is_enabled' => true, 'enabled_at' => now()],
    ]);
    $otherBranch = Branch::create([
        'organization_id' => $user->organization_id,
        'code' => fake()->unique()->numerify('####'),
        'name' => 'Other Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $drawer = TellerDrawer::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $otherBranch->id,
        'assigned_user_id' => $user->id,
        'name' => 'Other Branch Drawer',
        'code' => fake()->unique()->bothify('TDR-??'),
        'current_balance' => 1000,
        'status' => 'open',
    ]);
    DrawerSession::create([
        'organization_id' => $user->organization_id,
        'teller_drawer_id' => $drawer->id,
        'user_id' => $user->id,
        'opening_balance' => 1000,
        'status' => 'open',
    ]);
    $client = Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->numerify('+2567########'),
        'status' => 'active',
    ]);
    $accountType = AccountType::create(['name' => 'Savings Account', 'code' => fake()->unique()->bothify('SAV-??'), 'category' => 'savings']);
    $account = Account::create([
        'organization_id' => $user->organization_id,
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'account_type_id' => $accountType->id,
        'account_number' => fake()->unique()->numerify('1001-SAV-####'),
        'account_name' => $client->full_name.' Savings',
        'status' => 'active',
        'balance' => 0,
        'opened_at' => today(),
    ]);
    $transactionType = TransactionType::create(['name' => 'Deposit', 'code' => fake()->unique()->bothify('deposit-??'), 'direction' => 'credit']);

    $this->actingAs($user)
        ->from(route('teller.index'))
        ->post(route('teller.post'), [
            'account_scope' => 'client_account',
            'account_number' => $account->account_number,
            'transaction_type_id' => $transactionType->id,
            'amount' => 1000,
            'transaction_date' => today()->toDateString(),
        ])
        ->assertRedirect(route('teller.index'))
        ->assertSessionHasErrors(['amount' => 'Your open teller drawer is no longer available. Close and reopen your drawer before posting a transaction.']);
});

it('allows an organization admin to open an organization drawer for a teller', function () {
    [$organization, $branch, $otherBranch] = drawerAdminOrganization();
    enableTellerModule($organization);
    $admin = drawerAdminUser($organization, null, 'organization-admin', ['manage-organization', 'manage-users', 'manage-drawers']);
    $teller = drawerAdminUser($organization, $otherBranch->id, 'teller-cashier', ['post-transactions', 'manage-drawers']);
    $drawer = TellerDrawer::create([
        'organization_id' => $organization->id,
        'branch_id' => $otherBranch->id,
        'assigned_user_id' => null,
        'name' => 'Other Branch Drawer',
        'code' => fake()->unique()->bothify('ORG-TD-??'),
        'current_balance' => 0,
        'status' => 'closed',
    ]);

    $this->actingAs($admin)
        ->from(route('teller.index'))
        ->post(route('teller.open'), [
            'teller_drawer_id' => $drawer->id,
            'teller_user_id' => $teller->id,
            'opening_balance' => 1000,
        ])
        ->assertRedirect(route('teller.index'))
        ->assertSessionHasNoErrors();

    expect($drawer->fresh())
        ->status->toBe('open')
        ->assigned_user_id->toBe($teller->id);
});

it('shows selected organization drawers and tellers to a super admin', function () {
    [$organization, $branch] = drawerAdminOrganization();
    [$otherOrganization, $otherBranch] = drawerAdminOrganization('Other Tenant');
    $superAdmin = drawerAdminUser(null, null, 'super-admin', []);
    $teller = drawerAdminUser($organization, $branch->id, 'teller-cashier', ['post-transactions', 'manage-drawers']);
    $ownDrawer = TellerDrawer::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'assigned_user_id' => null,
        'name' => 'Selected Org Drawer',
        'code' => fake()->unique()->bothify('SEL-TD-??'),
        'current_balance' => 0,
        'status' => 'closed',
    ]);
    $otherDrawer = TellerDrawer::create([
        'organization_id' => $otherOrganization->id,
        'branch_id' => $otherBranch->id,
        'assigned_user_id' => null,
        'name' => 'Other Org Drawer',
        'code' => fake()->unique()->bothify('OTH-TD-??'),
        'current_balance' => 0,
        'status' => 'closed',
    ]);

    $this->actingAs($superAdmin)
        ->withSession(['super_admin_organization_id' => $organization->id])
        ->get(route('teller.index'))
        ->assertSuccessful()
        ->assertSee($teller->name)
        ->assertSee($ownDrawer->code)
        ->assertDontSee($otherDrawer->code);
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

function drawerAdminOrganization(string $name = 'Drawer Admin Tenant'): array
{
    $organization = Organization::create([
        'name' => $name,
        'slug' => fake()->unique()->slug(),
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => fake()->unique()->numerify('####'),
        'name' => 'Main Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $otherBranch = Branch::create([
        'organization_id' => $organization->id,
        'code' => fake()->unique()->numerify('####'),
        'name' => 'Second Branch',
        'is_active' => true,
        'status' => 'active',
    ]);

    return [$organization, $branch, $otherBranch];
}

function drawerAdminUser(?Organization $organization, ?int $branchId, string $roleSlug, array $permissions): User
{
    $role = Role::create([
        'organization_id' => $organization?->id,
        'name' => str($roleSlug)->headline(),
        'slug' => $roleSlug,
    ]);

    foreach ($permissions as $permission) {
        $permissionModel = Permission::firstOrCreate(
            ['slug' => $permission],
            ['organization_id' => $organization?->id, 'name' => str($permission)->headline()]
        );
        $role->permissions()->syncWithoutDetaching([$permissionModel->id]);
    }

    return User::factory()->create([
        'organization_id' => $organization?->id,
        'branch_id' => $branchId,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
}

function enableTellerModule(Organization $organization): void
{
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($organization);
    $organization->modules()->syncWithoutDetaching([
        Module::where('slug', 'teller')->firstOrFail()->id => ['is_enabled' => true, 'enabled_at' => now()],
    ]);
}
