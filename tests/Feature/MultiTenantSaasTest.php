<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('keeps organization clients separated in api responses', function () {
    [$organizationA, $branchA, $userA] = tenantUser(['manage-clients']);
    [$organizationB, $branchB] = tenantShell('Tenant B');
    $clientA = tenantClient($organizationA, $branchA, ['first_name' => 'Alice']);
    $clientB = tenantClient($organizationB, $branchB, ['first_name' => 'Brenda']);

    Sanctum::actingAs($userA, ['*']);

    $this->getJson('/api/v1/clients')
        ->assertSuccessful()
        ->assertJsonFragment(['first_name' => $clientA->first_name])
        ->assertJsonMissing(['first_name' => $clientB->first_name]);
});

it('prevents organization users from resolving another organization loan by id', function () {
    [, , $userA] = tenantUser(['manage-loans']);
    [$organizationB, $branchB] = tenantShell('Tenant B');
    $clientB = tenantClient($organizationB, $branchB);
    $productB = LoanProduct::create([
        'organization_id' => $organizationB->id,
        'name' => 'Business Loan',
        'code' => 'BL',
        'min_amount' => 10000,
        'max_amount' => 1000000,
        'min_term' => 1,
        'max_term' => 12,
        'term_unit' => 'months',
        'annual_interest_rate' => 12,
        'interest_method' => 'flat',
        'repayment_frequency' => 'monthly',
        'status' => 'active',
    ]);
    $loanB = Loan::create([
        'organization_id' => $organizationB->id,
        'branch_id' => $branchB->id,
        'client_id' => $clientB->id,
        'loan_product_id' => $productB->id,
        'loan_number' => 'B-LOAN-001',
        'requested_amount' => 100000,
        'principal_balance' => 100000,
        'interest_rate' => 12,
        'term_periods' => 12,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
        'application_date' => today(),
    ]);

    app(ModuleService::class)->enable($userA->organization, Module::where('slug', 'loans')->firstOrFail(), $userA);
    Sanctum::actingAs($userA, ['*']);

    $this->getJson("/api/v1/loans/{$loanB->id}")
        ->assertNotFound();
});

it('prevents organization users from downloading another organization client document', function () {
    [, , $userA] = tenantUser(['manage-clients']);
    [$organizationB, $branchB] = tenantShell('Tenant B');
    $clientB = tenantClient($organizationB, $branchB);
    $documentB = ClientDocument::create([
        'organization_id' => $organizationB->id,
        'client_id' => $clientB->id,
        'uploaded_by' => $userA->id,
        'type' => 'other',
        'title' => 'Private',
        'disk' => 'local',
        'path' => 'private/organizations/'.$organizationB->id.'/clients/'.$clientB->id.'/documents/private.pdf',
        'mime_type' => 'application/pdf',
        'size' => 10,
        'verification_status' => 'pending',
    ]);

    $this->actingAs($userA)
        ->get(route('clients.documents.download', [$clientB, $documentB]))
        ->assertNotFound();
});

it('limits branch users to their own branch records', function () {
    [$organization, $branchA, $userA] = tenantUser(['manage-clients']);
    $branchB = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'BR-B',
        'name' => 'Branch B',
        'status' => 'active',
        'is_active' => true,
    ]);
    tenantClient($organization, $branchA, ['first_name' => 'BranchA']);
    tenantClient($organization, $branchB, ['first_name' => 'BranchB']);

    $this->actingAs($userA);

    expect(Client::query()->pluck('first_name')->all())
        ->toContain('BranchA')
        ->not->toContain('BranchB');
});

it('blocks login for disabled organizations', function () {
    [, , $user] = tenantUser(['manage-clients'], ['status' => 'suspended']);

    $this->post('/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('login');
});

it('lets super admin see all organization records', function () {
    [$organizationA, $branchA] = tenantShell('Tenant A');
    [$organizationB, $branchB] = tenantShell('Tenant B');
    tenantClient($organizationA, $branchA);
    tenantClient($organizationB, $branchB);
    $superAdmin = superAdminUser();

    $this->actingAs($superAdmin);

    expect(Client::query()->count())->toBe(2);
});

it('resolves tenant from organization subdomain', function () {
    [$organization] = tenantShell('Hope SACCO', ['slug' => 'hope']);
    tenantProbeRoute();

    $this->getJson('https://hope.gateoffina.com/tenant-probe')
        ->assertSuccessful()
        ->assertJsonPath('organization_id', $organization->id);
});

it('resolves tenant from custom domain', function () {
    [$organization] = tenantShell('Hope SACCO');
    OrganizationDomain::create([
        'organization_id' => $organization->id,
        'domain' => 'system.hopesacco.co.ug',
        'type' => 'custom_domain',
        'is_primary' => true,
        'status' => 'active',
        'verified_at' => now(),
    ]);
    tenantProbeRoute();

    $this->getJson('https://system.hopesacco.co.ug/tenant-probe')
        ->assertSuccessful()
        ->assertJsonPath('organization_id', $organization->id);
});

it('assigns existing records to an organization without changing balances', function () {
    $organization = Organization::create(['name' => 'Hope SACCO', 'slug' => 'hope-sacco', 'status' => 'active']);
    $branchId = DB::table('branches')->insertGetId([
        'code' => 'LEGACY',
        'name' => 'Legacy Branch',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $clientId = DB::table('clients')->insertGetId([
        'branch_id' => $branchId,
        'client_number' => 'LEG-C-001',
        'client_type' => 'individual',
        'first_name' => 'Legacy',
        'last_name' => 'Client',
        'phone' => '+256700000001',
        'status' => 'active',
        'registered_at' => today(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('loans')->insert([
        'branch_id' => $branchId,
        'client_id' => $clientId,
        'loan_product_id' => LoanProduct::create([
            'name' => 'Legacy Loan',
            'code' => 'LEG',
            'annual_interest_rate' => 12,
            'status' => 'active',
        ])->id,
        'loan_number' => 'LEG-L-001',
        'requested_amount' => 500000,
        'principal_balance' => 123456,
        'interest_rate' => 12,
        'term_periods' => 12,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('gateoffina:assign-organization', ['--organization' => 'hope-sacco', '--all' => true])
        ->assertSuccessful();

    expect(DB::table('clients')->where('id', $clientId)->value('organization_id'))->toBe($organization->id)
        ->and((float) DB::table('loans')->where('loan_number', 'LEG-L-001')->value('principal_balance'))->toBe(123456.0);
});

it('does not change records during dry run assignment', function () {
    Organization::create(['name' => 'Hope SACCO', 'slug' => 'hope-sacco', 'status' => 'active']);
    $branchId = DB::table('branches')->insertGetId([
        'code' => 'DRY',
        'name' => 'Dry Branch',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $clientId = DB::table('clients')->insertGetId([
        'branch_id' => $branchId,
        'client_number' => 'DRY-C-001',
        'client_type' => 'individual',
        'first_name' => 'Dry',
        'last_name' => 'Run',
        'phone' => '+256700000002',
        'status' => 'active',
        'registered_at' => today(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('gateoffina:assign-organization', ['--organization' => 'hope-sacco', '--all' => true, '--dry-run' => true])
        ->assertSuccessful();

    expect(DB::table('clients')->where('id', $clientId)->value('organization_id'))->toBeNull();
});

it('validation command detects missing organization ids', function () {
    $branchId = DB::table('branches')->insertGetId([
        'code' => 'VAL',
        'name' => 'Validation Branch',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('clients')->insert([
        'branch_id' => $branchId,
        'client_number' => 'VAL-C-001',
        'client_type' => 'individual',
        'first_name' => 'Missing',
        'last_name' => 'Tenant',
        'phone' => '+256700000003',
        'status' => 'active',
        'registered_at' => today(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('gateoffina:validate-organizations')
        ->assertFailed();
});

function tenantProbeRoute(): void
{
    Route::middleware('tenant')->get('/tenant-probe', function (TenantContext $tenant) {
        return response()->json(['organization_id' => $tenant->id()]);
    });
}

/**
 * @return array{0: Organization, 1: Branch, 2: User}
 */
function tenantUser(array $permissions, array $organizationAttributes = []): array
{
    [$organization, $branch] = tenantShell('Tenant '.fake()->unique()->word(), $organizationAttributes);
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

    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($organization);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'password' => Hash::make('password'),
        'status' => 'active',
        'is_active' => true,
    ]);

    return [$organization, $branch, $user];
}

/**
 * @return array{0: Organization, 1: Branch}
 */
function tenantShell(string $name, array $organizationAttributes = []): array
{
    $organization = Organization::create([
        'name' => $name,
        'slug' => $organizationAttributes['slug'] ?? fake()->unique()->slug(),
        'status' => $organizationAttributes['status'] ?? 'active',
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => fake()->unique()->bothify('BR-###'),
        'name' => 'Main Branch',
        'status' => 'active',
        'is_active' => true,
    ]);

    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($organization);

    return [$organization, $branch];
}

function tenantClient(Organization $organization, Branch $branch, array $attributes = []): Client
{
    return Client::create($attributes + [
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => fake()->unique()->bothify('C-####'),
        'client_type' => 'individual',
        'first_name' => 'Client',
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->numerify('+2567########'),
        'status' => 'active',
        'registered_at' => today(),
    ]);
}

function superAdminUser(): User
{
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);

    return User::factory()->create([
        'role_id' => $role->id,
        'organization_id' => null,
        'branch_id' => null,
        'password' => Hash::make('password'),
        'status' => 'active',
        'is_active' => true,
    ]);
}
