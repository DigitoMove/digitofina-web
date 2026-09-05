<?php

use App\Models\AuditLog;
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
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\LoanApplicationFeeService;
use App\Services\ModuleService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('keeps client portal members out of staff user management', function () {
    [$organization, $branch, $organizationAdmin] = tenantUser(['manage-users']);
    $organizationAdmin->role->update(['name' => 'Organization Admin', 'slug' => 'organization-admin']);
    $memberRole = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Member',
        'slug' => 'member',
    ]);
    $member = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $memberRole->id,
        'name' => 'Portal Client Account',
        'email' => 'portal-client@example.test',
        'phone' => '+256700000899',
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->actingAs($organizationAdmin)
        ->get(route('users.index'))
        ->assertSuccessful()
        ->assertSee('Staff User Management')
        ->assertSee($organizationAdmin->name)
        ->assertDontSee($member->email)
        ->assertDontSee('<option value="'.$memberRole->id.'"', false);

    expect($this->actingAs($organizationAdmin)->get(route('users.edit', $member))->isSuccessful())->toBeFalse();

    $this->actingAs($organizationAdmin)
        ->post(route('users.store'), [
            'role_id' => $memberRole->id,
            'branch_id' => $branch->id,
            'name' => 'Another Portal Client',
            'email' => 'another-portal-client@example.test',
            'phone' => '+256700000898',
            'status' => 'active',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
        ->assertSessionHasErrors('role_id');
});

it('prevents organization admins from assigning or editing super admins', function () {
    [$organization, $branch, $organizationAdmin] = tenantUser(['manage-users']);
    $organizationAdmin->role->update(['name' => 'Organization Admin', 'slug' => 'organization-admin']);
    $superRole = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $staffRole = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Operations Staff',
        'slug' => 'operations-staff',
    ]);
    $staff = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $staffRole->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $superAdmin = User::factory()->create([
        'organization_id' => null,
        'branch_id' => null,
        'role_id' => $superRole->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->actingAs($organizationAdmin)
        ->get(route('users.create'))
        ->assertSuccessful()
        ->assertDontSee('<option value="'.$superRole->id.'"', false);

    $this->actingAs($organizationAdmin)
        ->post(route('users.store'), [
            'role_id' => $superRole->id,
            'name' => 'Unauthorized Super Admin',
            'email' => 'unauthorized-super@example.test',
            'phone' => '+256700000901',
            'status' => 'active',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
        ->assertSessionHasErrors('role_id');

    $this->actingAs($organizationAdmin)
        ->put(route('users.update', $staff), [
            'role_id' => $superRole->id,
            'branch_id' => $branch->id,
            'name' => $staff->name,
            'email' => $staff->email,
            'phone' => $staff->phone,
            'status' => 'active',
        ])
        ->assertSessionHasErrors('role_id');

    expect($this->actingAs($organizationAdmin)->get(route('users.edit', $superAdmin))->isSuccessful())->toBeFalse()
        ->and($staff->fresh()->role_id)->toBe($staffRole->id);
});

it('allows organization admins to create and update users with all branch access', function () {
    [$organization, $branch, $organizationAdmin] = tenantUser(['manage-users']);
    $organizationAdmin->role->update(['name' => 'Organization Admin', 'slug' => 'organization-admin']);
    $otherBranch = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'SECONDARY',
        'name' => 'Secondary Branch',
        'status' => 'active',
        'is_active' => true,
    ]);
    $recoveryRole = Role::firstOrCreate(
        ['slug' => 'loan-recovery-officer'],
        ['organization_id' => null, 'name' => 'Loan Recovery Officer']
    );

    $this->actingAs($organizationAdmin)
        ->post(route('users.store'), [
            'role_id' => $recoveryRole->id,
            'branch_id' => null,
            'name' => 'All Branch Recovery',
            'email' => 'all-branch-recovery@example.test',
            'phone' => '+256700000902',
            'status' => 'active',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
        ->assertRedirect(route('users.index'))
        ->assertSessionHasNoErrors();

    $recoveryOfficer = User::where('email', 'all-branch-recovery@example.test')->firstOrFail();

    expect($recoveryOfficer->organization_id)->toBe($organization->id)
        ->and($recoveryOfficer->branch_id)->toBeNull()
        ->and($recoveryOfficer->canAccessBranch($branch->id, $organization->id))->toBeTrue()
        ->and($recoveryOfficer->canAccessBranch($otherBranch->id, $organization->id))->toBeTrue();

    $recoveryOfficer->update(['branch_id' => $branch->id]);

    $this->actingAs($organizationAdmin)
        ->put(route('users.update', $recoveryOfficer), [
            'role_id' => $recoveryRole->id,
            'branch_id' => null,
            'name' => $recoveryOfficer->name,
            'email' => $recoveryOfficer->email,
            'phone' => $recoveryOfficer->phone,
            'status' => 'active',
        ])
        ->assertRedirect(route('users.index'))
        ->assertSessionHasNoErrors();

    expect($recoveryOfficer->fresh()->branch_id)->toBeNull();
});

it('allows organization admins to create organization users without an assigned manage users permission', function () {
    [$organization, $branch, $organizationAdmin] = tenantUser([]);
    $organizationAdmin->role->update(['name' => 'Organization Admin', 'slug' => 'organization-admin']);
    $staffRole = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Cashier',
        'slug' => 'cashier',
    ]);

    $this->actingAs($organizationAdmin)
        ->post(route('users.store'), [
            'role_id' => $staffRole->id,
            'branch_id' => $branch->id,
            'name' => 'Organization Cashier',
            'email' => 'organization-cashier@example.test',
            'phone' => '+256700000903',
            'status' => 'active',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
        ->assertRedirect(route('users.index'))
        ->assertSessionHasNoErrors();

    $cashier = User::query()->where('email', 'organization-cashier@example.test')->firstOrFail();

    expect($cashier->organization_id)->toBe($organization->id)
        ->and($cashier->branch_id)->toBe($branch->id)
        ->and($cashier->role_id)->toBe($staffRole->id);
});

it('lets super admins and organization admins assign the system cashier role to a new organization', function () {
    [$catalogOrganization] = tenantShell('Role Catalog Owner');
    $cashierRole = Role::create([
        'organization_id' => $catalogOrganization->id,
        'name' => 'Teller Cashier',
        'slug' => 'teller-cashier',
    ]);
    [$organization, $branch, $organizationAdmin] = tenantUser([]);
    $organizationAdmin->role->update(['name' => 'Organization Admin', 'slug' => 'organization-admin']);

    $this->actingAs($organizationAdmin)
        ->get(route('users.create'))
        ->assertSuccessful()
        ->assertSee('<option value="'.$cashierRole->id.'"', false);

    $this->actingAs($organizationAdmin)
        ->post(route('users.store'), [
            'role_id' => $cashierRole->id,
            'branch_id' => $branch->id,
            'name' => 'Organization Teller',
            'email' => 'organization-teller@example.test',
            'phone' => '+256700000904',
            'status' => 'active',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
        ->assertRedirect(route('users.index'))
        ->assertSessionHasNoErrors();

    $superAdmin = superAdminUser();
    $superAdminRole = $superAdmin->role;

    $this->actingAs($superAdmin)
        ->get(route('users.create', ['organization_id' => $organization->id]))
        ->assertSuccessful()
        ->assertSee('<option value="'.$cashierRole->id.'"', false)
        ->assertDontSee('<option value="'.$superAdminRole->id.'"', false);

    $this->actingAs($superAdmin)
        ->post(route('users.store'), [
            'organization_id' => $organization->id,
            'role_id' => $cashierRole->id,
            'branch_id' => $branch->id,
            'name' => 'Super Admin Created Teller',
            'email' => 'super-created-teller@example.test',
            'phone' => '+256700000905',
            'status' => 'active',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
        ->assertRedirect(route('users.index'))
        ->assertSessionHasNoErrors();

    $this->actingAs($superAdmin)
        ->post(route('users.store'), [
            'role_id' => $superAdminRole->id,
            'name' => 'Second Super Admin',
            'email' => 'second-super@example.test',
            'phone' => '+256700000906',
            'status' => 'active',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
        ->assertSessionHasErrors('role_id');

    expect(User::query()->where('email', 'organization-teller@example.test')->firstOrFail())
        ->organization_id->toBe($organization->id)
        ->role_id->toBe($cashierRole->id)
        ->and(User::query()->where('email', 'super-created-teller@example.test')->firstOrFail())
        ->organization_id->toBe($organization->id)
        ->branch_id->toBe($branch->id)
        ->role_id->toBe($cashierRole->id)
        ->and(User::query()->where('email', 'second-super@example.test')->exists())->toBeFalse();
});

it('rejects custom roles and branches from another organization when creating users', function () {
    [$organization, $branch, $organizationAdmin] = tenantUser([]);
    $organizationAdmin->role->update(['name' => 'Organization Admin', 'slug' => 'organization-admin']);
    [$otherOrganization, $otherBranch] = tenantShell('Other Tenant');
    $otherRole = Role::create([
        'organization_id' => $otherOrganization->id,
        'name' => 'Other Tenant Custom Role',
        'slug' => 'other-tenant-custom-role',
    ]);
    $ownRole = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Own Tenant Custom Role',
        'slug' => 'own-tenant-custom-role',
    ]);

    $payload = [
        'branch_id' => $branch->id,
        'name' => 'Scoped Staff',
        'email' => 'scoped-staff@example.test',
        'phone' => '+256700000907',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ];

    $this->actingAs($organizationAdmin)
        ->post(route('users.store'), $payload + ['role_id' => $otherRole->id])
        ->assertSessionHasErrors('role_id');

    $this->actingAs($organizationAdmin)
        ->post(route('users.store'), array_merge($payload, [
            'role_id' => $ownRole->id,
            'branch_id' => $otherBranch->id,
        ]))
        ->assertSessionHasErrors('branch_id');

    expect(User::query()->where('email', 'scoped-staff@example.test')->exists())->toBeFalse();
});

it('scopes loan applications and reports to the organization selected by a super admin', function () {
    $superAdmin = superAdminUser();
    [$organizationA, $branchA] = tenantShell('Selected Reports Tenant');
    [$organizationB, $branchB] = tenantShell('Hidden Reports Tenant');
    $clientA = tenantClient($organizationA, $branchA);
    $clientB = tenantClient($organizationB, $branchB);
    $productA = tenantLoanProduct($organizationA, 'SELECTED-PRODUCT');
    $productB = tenantLoanProduct($organizationB, 'HIDDEN-PRODUCT');
    $loanA = tenantApplicationLoan($organizationA, $branchA, $clientA, $productA, 'SELECTED-APPLICATION');
    $loanB = tenantApplicationLoan($organizationB, $branchB, $clientB, $productB, 'HIDDEN-APPLICATION');
    app(ModuleService::class)->enable($organizationA, Module::where('slug', 'loans')->firstOrFail(), $superAdmin);

    $this->actingAs($superAdmin)
        ->withSession(['super_admin_organization_id' => $organizationA->id])
        ->get(route('loans.index', ['status_group' => 'applications']))
        ->assertSuccessful()
        ->assertSee($loanA->loan_number)
        ->assertDontSee($loanB->loan_number);

    $this->actingAs($superAdmin)
        ->withSession(['super_admin_organization_id' => $organizationA->id])
        ->get(route('reports.show', [
            'report' => 'loans-application-by-date',
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]))
        ->assertSuccessful()
        ->assertSee($loanA->loan_number)
        ->assertDontSee($loanB->loan_number);

    $this->actingAs($superAdmin)
        ->withSession(['super_admin_organization_id' => $organizationA->id])
        ->from(route('reports.index'))
        ->get(route('reports.show', [
            'report' => 'loans-application-by-date',
            'branch_id' => $branchB->id,
        ]))
        ->assertRedirect(route('reports.index'))
        ->assertSessionHasErrors('branch_id');
});

it('lets a super admin act as an organization user and safely return', function () {
    $superAdmin = superAdminUser();
    [$previousOrganization] = tenantShell('Previous Super Admin Context');
    [$organization, , $staff] = tenantUser([]);

    $this->actingAs($superAdmin)
        ->withSession(['super_admin_organization_id' => $previousOrganization->id])
        ->post(route('users.impersonate', $staff))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('impersonator_user_id', $superAdmin->id);

    $this->assertAuthenticatedAs($staff);

    $this->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Acting as '.$staff->name)
        ->assertSee($staff->role->name)
        ->assertSee($organization->name);

    $this->delete(route('impersonation.destroy'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionMissing('impersonator_user_id')
        ->assertSessionHas('super_admin_organization_id', $previousOrganization->id);

    $this->assertAuthenticatedAs($superAdmin);

    expect(AuditLog::query()->withoutGlobalScope('tenant')->where('action', 'auth.impersonation.started')->exists())->toBeTrue()
        ->and(AuditLog::query()->withoutGlobalScope('tenant')->where('action', 'auth.impersonation.stopped')->exists())->toBeTrue();
});

it('lets a super admin clear an active demo organization without changing its data', function () {
    $superAdmin = superAdminUser();
    [$demoOrganization] = tenantShell('Active Demo Tenant');
    $demoOrganization->update([
        'is_demo' => true,
        'demo_started_at' => now(),
        'demo_expires_at' => now()->addDays(14),
    ]);

    $this->actingAs($superAdmin)
        ->withSession(['super_admin_organization_id' => $demoOrganization->id])
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Clear active organization')
        ->assertSee(route('organizations.switch.global'), false);

    $this->from(route('dashboard'))
        ->post(route('organizations.switch.global'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionMissing('super_admin_organization_id')
        ->assertSessionHas('status', 'Returned to global Super Admin view.');

    $this->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee('Clear active organization');

    expect($demoOrganization->fresh())
        ->not->toBeNull()
        ->is_demo->toBeTrue();
});

it('preserves a user password unless password change is explicitly enabled', function () {
    [$organization, $branch, $organizationAdmin] = tenantUser([]);
    $organizationAdmin->role->update(['name' => 'Organization Admin', 'slug' => 'organization-admin']);
    $staffRole = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Password Test Staff',
        'slug' => 'password-test-staff',
    ]);
    $staff = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $staffRole->id,
        'phone' => '+256700000908',
        'password' => Hash::make('OriginalPassword123!'),
        'status' => 'active',
        'is_active' => true,
    ]);
    $payload = [
        'role_id' => $staffRole->id,
        'branch_id' => $branch->id,
        'name' => $staff->name,
        'email' => $staff->email,
        'phone' => $staff->phone,
        'status' => 'active',
    ];

    $this->actingAs($organizationAdmin)
        ->put(route('users.update', $staff), $payload + [
            'password' => 'BrowserAutofill123!',
            'password_confirmation' => 'BrowserAutofill123!',
        ])
        ->assertRedirect(route('users.index'))
        ->assertSessionHasNoErrors();

    expect(Hash::check('OriginalPassword123!', $staff->fresh()->password))->toBeTrue()
        ->and(Hash::check('BrowserAutofill123!', $staff->fresh()->password))->toBeFalse();

    $this->actingAs($organizationAdmin)
        ->put(route('users.update', $staff), $payload + [
            'change_password' => '1',
            'password' => 'ChangedPassword123!',
            'password_confirmation' => 'ChangedPassword123!',
        ])
        ->assertRedirect(route('users.index'))
        ->assertSessionHasNoErrors();

    expect(Hash::check('ChangedPassword123!', $staff->fresh()->password))->toBeTrue();
});

it('loads user assignment branches and roles for the organization selected by a super admin', function () {
    $superAdmin = superAdminUser();
    [$organizationA, $branchA] = tenantShell('Current Options Tenant');
    [$organizationB, $branchB] = tenantShell('Requested Options Tenant');
    $roleA = Role::create(['organization_id' => $organizationA->id, 'name' => 'Tenant A Custom', 'slug' => 'tenant-a-custom']);
    $roleB = Role::create(['organization_id' => $organizationB->id, 'name' => 'Tenant B Custom', 'slug' => 'tenant-b-custom']);

    $response = $this->actingAs($superAdmin)
        ->withSession(['super_admin_organization_id' => $organizationA->id])
        ->getJson(route('users.form-options', ['organization_id' => $organizationB->id]))
        ->assertSuccessful()
        ->assertJsonFragment(['id' => $branchB->id, 'name' => $branchB->name])
        ->assertJsonFragment(['id' => $roleB->id, 'name' => $roleB->name]);

    expect(collect($response->json('branches'))->pluck('id')->all())->toBe([$branchB->id])
        ->and(collect($response->json('roles'))->pluck('id'))->toContain($roleB->id)
        ->not->toContain($roleA->id)
        ->and(collect($response->json('roles'))->pluck('name'))->not->toContain('Super Admin');
});

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

it('lets super admins filter user listing by organization and branch', function () {
    [$organizationA, $branchA] = tenantShell('Atlas SACCO');
    $branchA->update(['code' => 'ATL-MAIN', 'name' => 'Atlas Main']);
    [$organizationB, $branchB] = tenantShell('Beacon SACCO');
    $branchB->update(['code' => 'BEA-MAIN', 'name' => 'Beacon Main']);

    $atlasRole = Role::create([
        'organization_id' => $organizationA->id,
        'name' => 'Atlas Teller',
        'slug' => 'atlas-teller',
    ]);
    $beaconRole = Role::create([
        'organization_id' => $organizationB->id,
        'name' => 'Beacon Teller',
        'slug' => 'beacon-teller',
    ]);

    User::factory()->create([
        'organization_id' => $organizationA->id,
        'branch_id' => $branchA->id,
        'role_id' => $atlasRole->id,
        'name' => 'Atlas Filter Cashier',
        'email' => 'atlas-filter-cashier@example.test',
        'phone' => '+256700991001',
        'status' => 'active',
        'is_active' => true,
    ]);
    User::factory()->create([
        'organization_id' => $organizationA->id,
        'branch_id' => $branchA->id,
        'role_id' => $atlasRole->id,
        'name' => 'Atlas Suspended Cashier',
        'email' => 'atlas-suspended-cashier@example.test',
        'phone' => '+256700991003',
        'status' => 'suspended',
        'is_active' => false,
    ]);
    User::factory()->create([
        'organization_id' => $organizationB->id,
        'branch_id' => $branchB->id,
        'role_id' => $beaconRole->id,
        'name' => 'Beacon Filter Cashier',
        'email' => 'beacon-filter-cashier@example.test',
        'phone' => '+256700991002',
        'status' => 'active',
        'is_active' => true,
    ]);
    $superAdmin = superAdminUser();

    $this->actingAs($superAdmin)
        ->get(route('users.index', [
            'organization_id' => $organizationA->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]))
        ->assertSuccessful()
        ->assertSeeInOrder(['Atlas Filter Cashier', 'Atlas Teller', 'Atlas SACCO', 'Atlas Main'])
        ->assertSee('<option value="'.$organizationA->id.'" selected', false)
        ->assertSee('<option value="'.$branchA->id.'" selected', false)
        ->assertSee('<option value="active" selected', false)
        ->assertSee('data-label="Organization"', false)
        ->assertDontSee('Atlas Suspended Cashier')
        ->assertDontSee('Beacon Filter Cashier')
        ->assertDontSee('Beacon Teller')
        ->assertDontSee('Beacon Main');

    $this->actingAs($superAdmin)
        ->from(route('users.index'))
        ->get(route('users.index', [
            'organization_id' => $organizationA->id,
            'branch_id' => $branchB->id,
        ]))
        ->assertRedirect(route('users.index'))
        ->assertSessionHasErrors('branch_id');
});

it('lets super admins filter branch management by organization', function () {
    [$organizationA, $branchA] = tenantShell('Atlas Branch SACCO');
    $branchA->update(['code' => 'ATL-BR', 'name' => 'Atlas Listing Branch']);
    $suspendedBranch = Branch::create([
        'organization_id' => $organizationA->id,
        'code' => 'ATL-SUSP',
        'name' => 'Atlas Suspended Branch',
        'status' => 'suspended',
        'is_active' => false,
    ]);
    $staffRole = Role::create([
        'organization_id' => $organizationA->id,
        'name' => 'Atlas Branch Staff',
        'slug' => 'atlas-branch-staff',
    ]);
    User::factory()->create([
        'organization_id' => $organizationA->id,
        'branch_id' => $branchA->id,
        'role_id' => $staffRole->id,
        'name' => 'Atlas Branch User',
        'status' => 'active',
        'is_active' => true,
    ]);
    [, $branchB] = tenantShell('Beacon Branch SACCO');
    $branchB->update(['code' => 'BEA-BR', 'name' => 'Beacon Listing Branch']);

    $this->actingAs(superAdminUser())
        ->get(route('branches.index', ['organization_id' => $organizationA->id, 'status' => 'active']))
        ->assertSuccessful()
        ->assertSeeInOrder(['ATL-BR', 'Atlas Listing Branch', 'Atlas Branch SACCO'])
        ->assertSee('<option value="'.$organizationA->id.'" selected', false)
        ->assertSee('<option value="active" selected', false)
        ->assertSee('data-label="Staff">1</td>', false)
        ->assertDontSee($suspendedBranch->name)
        ->assertDontSee('Beacon Listing Branch');
});

it('keeps system settings scoped to each organization', function () {
    [$originalOrganization] = tenantShell('Original SACCO');
    [$secondOrganization] = tenantShell('Second SACCO');

    SystemSetting::withoutGlobalScope('tenant')->create([
        'organization_id' => $originalOrganization->id,
        'key' => 'loan_application_fee',
        'label' => 'Loan application fee',
        'value' => '15000',
        'type' => 'money',
        'group' => 'loans',
    ]);

    SystemSetting::ensureForOrganization($secondOrganization);
    SystemSetting::forOrganization($secondOrganization->id)
        ->where('key', 'loan_application_fee')
        ->update(['value' => '30000']);

    expect(SystemSetting::value('loan_application_fee', 0, $originalOrganization->id))->toBe('15000')
        ->and(SystemSetting::value('loan_application_fee', 0, $secondOrganization->id))->toBe('30000');
});

it('falls back to platform settings only when an organization has no override', function () {
    [$originalOrganization] = tenantShell('Original SACCO');
    [$secondOrganization] = tenantShell('Second SACCO');

    SystemSetting::withoutGlobalScope('tenant')->updateOrCreate(
        ['organization_id' => null, 'key' => 'loan_application_fee'],
        ['label' => 'Loan application fee', 'value' => '10000', 'type' => 'money', 'group' => 'loans']
    );

    SystemSetting::withoutGlobalScope('tenant')->updateOrCreate(
        ['organization_id' => $originalOrganization->id, 'key' => 'loan_application_fee'],
        ['label' => 'Loan application fee', 'value' => '25000', 'type' => 'money', 'group' => 'loans']
    );

    expect(SystemSetting::value('loan_application_fee', 0, $originalOrganization->id))->toBe('25000')
        ->and(SystemSetting::value('loan_application_fee', 0, $secondOrganization->id))->toBe('10000');
});

it('uses organization application fee ranges before platform ranges', function () {
    [$organization] = tenantShell('Original SACCO');
    [$secondOrganization] = tenantShell('Second SACCO');

    SystemSetting::withoutGlobalScope('tenant')->updateOrCreate(
        ['organization_id' => null, 'key' => 'loan_application_fee_ranges'],
        [
            'label' => 'Loan application fee ranges',
            'value' => json_encode([['min' => 0, 'max' => null, 'fee' => 20000]]),
            'type' => 'json',
            'group' => 'loans',
        ]
    );

    SystemSetting::withoutGlobalScope('tenant')->updateOrCreate(
        ['organization_id' => $organization->id, 'key' => 'loan_application_fee_ranges'],
        [
            'label' => 'Loan application fee ranges',
            'value' => json_encode([['min' => 0, 'max' => null, 'fee' => 5000]]),
            'type' => 'json',
            'group' => 'loans',
        ]
    );

    $applicationFees = app(LoanApplicationFeeService::class);

    expect($applicationFees->feeFor(100000, $organization->id))->toBe(5000.0)
        ->and($applicationFees->feeFor(100000, $secondOrganization->id))->toBe(20000.0);
});

it('lets organization users view their organization profile without edit permission', function () {
    [$organization, , $user] = tenantUser(['view-reports']);

    $this->actingAs($user)
        ->get(route('organization.current'))
        ->assertOk()
        ->assertSee($organization->name)
        ->assertSee('You can view this organization profile')
        ->assertSee('disabled', false);
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

function tenantLoanProduct(Organization $organization, string $code): LoanProduct
{
    return LoanProduct::create([
        'organization_id' => $organization->id,
        'name' => str($code)->headline()->toString(),
        'code' => $code,
        'min_amount' => 10000,
        'max_amount' => 1000000,
        'min_term' => 1,
        'max_term' => 12,
        'term_unit' => 'months',
        'annual_interest_rate' => 12,
        'interest_method' => 'flat',
        'repayment_frequency' => 'monthly',
        'status' => 'active',
        'is_active' => true,
    ]);
}

function tenantApplicationLoan(Organization $organization, Branch $branch, Client $client, LoanProduct $product, string $number): Loan
{
    return Loan::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'loan_product_id' => $product->id,
        'loan_number' => $number,
        'requested_amount' => 100000,
        'principal_balance' => 100000,
        'interest_rate' => 12,
        'term_periods' => 12,
        'repayment_frequency' => 'monthly',
        'status' => 'submitted',
        'application_date' => today(),
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
