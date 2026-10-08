<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\ReportCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows and filters the loan-added date in the administrator list and loan report', function () {
    [$organization, $branch, $admin, $product] = loanListingFixture('Primary');
    $earlyLoan = loanListingLoan($organization, $branch, $admin, $product, '2026-01-15', 'PRIMARY-EARLY');
    $selectedLoan = loanListingLoan($organization, $branch, $admin, $product, '2026-02-15', 'PRIMARY-SELECTED');
    $lateLoan = loanListingLoan($organization, $branch, $admin, $product, '2026-03-15', 'PRIMARY-LATE');

    $report = app(ReportCatalogService::class)->generate('loans-application-by-date', [
        'from' => '2026-02-01',
        'to' => '2026-02-28',
    ], $admin);
    $this->actingAs($admin);
    $response = $this->get(route('loans.index', ['added_from' => '2026-02-01', 'added_to' => '2026-02-28']));

    expect($report['columns'])->toContain('Loan Added')
        ->and($report['rows']->map(fn (array $row): string => $row['Loan No.'])->all())->toBe([$selectedLoan->loan_number])
        ->and($report['rows']->first()['Loan Added'])->toBe('15 Feb 2026')
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->getContent())->toContain('Loan Added', '15 Feb 2026', $selectedLoan->loan_number)
        ->not->toContain($earlyLoan->loan_number)
        ->not->toContain($lateLoan->loan_number);
});

it('rejects an inverted loan-added date range', function () {
    [, , $admin] = loanListingFixture('Validation');

    $this->actingAs($admin)
        ->get(route('loans.index', ['added_from' => '2026-03-01', 'added_to' => '2026-02-01']))
        ->assertSessionHasErrors('added_to');
});

it('keeps loan list tenant and authorization boundaries', function () {
    [$organization, $branch, $admin, $product] = loanListingFixture('Own');
    $ownLoan = loanListingLoan($organization, $branch, $admin, $product, '2026-02-15', 'OWN-LOAN');
    [$foreignOrganization, $foreignBranch, $foreignAdmin, $foreignProduct] = loanListingFixture('Foreign');
    $foreignLoan = loanListingLoan($foreignOrganization, $foreignBranch, $foreignAdmin, $foreignProduct, '2026-02-15', 'FOREIGN-LOAN');

    $response = $this->actingAs($admin)->get(route('loans.index', ['added_from' => '2026-02-01', 'added_to' => '2026-02-28']));
    $memberRole = Role::firstOrCreate(['slug' => 'member'], ['name' => 'Member']);
    $member = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $memberRole->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    expect($response->getContent())->toContain($ownLoan->loan_number)
        ->not->toContain($foreignLoan->loan_number)
        ->and($this->actingAs($member)->get(route('loans.index'))->status())->toBe(302);
});

/**
 * @return array{0:Organization, 1:Branch, 2:User, 3:LoanProduct}
 */
function loanListingFixture(string $label): array
{
    $organization = Organization::create([
        'name' => $label.' Loans',
        'slug' => str($label)->slug().'-loans-'.fake()->unique()->numerify('###'),
        'status' => 'active',
    ]);
    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($organization);
    Module::query()->pluck('id')->each(fn (int $moduleId): OrganizationModule => OrganizationModule::updateOrCreate(
        ['organization_id' => $organization->id, 'module_id' => $moduleId],
        ['is_enabled' => true, 'enabled_at' => now()],
    ));
    app(ModuleService::class)->clearCache($organization);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => fake()->unique()->numerify('LOAN####'),
        'name' => $label.' Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::firstOrCreate(['slug' => 'organization-admin'], ['name' => 'Organization Admin']);
    $permission = Permission::firstOrCreate(['slug' => 'view-reports'], ['name' => 'View Reports']);
    $role->permissions()->syncWithoutDetaching([$permission->id]);
    $admin = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $product = LoanProduct::create([
        'organization_id' => $organization->id,
        'name' => $label.' Loan',
        'code' => fake()->unique()->bothify('LOAN-??'),
        'min_amount' => 1_000,
        'max_amount' => 1_000_000,
        'annual_interest_rate' => 12,
        'interest_method' => 'flat',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 12,
        'is_active' => true,
        'status' => 'active',
    ]);

    return [$organization, $branch, $admin, $product];
}

function loanListingLoan(Organization $organization, Branch $branch, User $admin, LoanProduct $product, string $applicationDate, string $number): Loan
{
    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'officer_id' => $admin->id,
        'client_number' => $number.'-CLIENT',
        'client_type' => 'individual',
        'first_name' => 'Loan',
        'last_name' => $number,
        'phone' => fake()->unique()->e164PhoneNumber(),
        'status' => 'active',
    ]);

    return Loan::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $product->id,
        'officer_id' => $admin->id,
        'created_by' => $admin->id,
        'loan_number' => $number,
        'requested_amount' => 100_000,
        'approved_amount' => 100_000,
        'principal_balance' => 100_000,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 12,
        'term_periods' => 12,
        'repayment_frequency' => 'monthly',
        'application_date' => $applicationDate,
        'status' => 'active',
    ]);
}
