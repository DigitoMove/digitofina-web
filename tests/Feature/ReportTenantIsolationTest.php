<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\LoanSchedule;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\ReportCatalogService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('isolates every profitability source to the active organization', function () {
    [$organizationA, $branchA, $adminA, $loanA] = reportTenantFixture('Demo Organization', 'DEMO', 1000, 50, 120, 200, 40);
    [, , , $loanB] = reportTenantFixture('Other Organization', 'OTHER', 9_000_000, 400_000, 300_000, 800_000, 500_000);
    $this->actingAs($adminA);

    $filters = [
        'from' => today()->toDateString(),
        'to' => today()->toDateString(),
    ];
    $reports = app(ReportCatalogService::class);
    $profitability = $reports->generate('profitability-summary', $filters, $adminA);
    $disbursements = $reports->generate('loan-disbursements', $filters, $adminA);
    $applicationFees = $reports->generate('application-fees-income', $filters, $adminA);
    $interest = $reports->generate('loan-interest-income', $filters, $adminA);
    $branches = $reports->generate('branch-profitability-standing', $filters, $adminA);

    expect($organizationA->id)->toBe($adminA->organization_id)
        ->and($profitability['rows']->firstWhere('Metric', 'Application fees received')['Amount'])->toBe('UGX 50.00')
        ->and($profitability['rows']->firstWhere('Metric', 'Capital disbursed')['Amount'])->toBe('UGX 1,000.00')
        ->and($profitability['rows']->firstWhere('Metric', 'Interest collected')['Amount'])->toBe('UGX 120.00')
        ->and($profitability['rows']->firstWhere('Metric', 'Ledger expenses total')['Amount'])->toBe('UGX 40.00')
        ->and($disbursements['summary']['Gross principal disbursed'])->toBe('UGX 1,000.00')
        ->and($disbursements['summary']['Application fees charged'])->toBe('UGX 50.00')
        ->and($disbursements['rows']->pluck('Loan')->all())->toBe([$loanA->loan_number])
        ->and($disbursements['rows']->pluck('Loan'))->not->toContain($loanB->loan_number)
        ->and($applicationFees['summary']['Application fees charged'])->toBe('UGX 50.00')
        ->and($interest['summary']['Interest collected'])->toBe('UGX 120.00')
        ->and($branches['rows']->pluck('Branch'))->toContain($branchA->name, 'All Branches')
        ->and($branches['rows']->pluck('Branch'))->not->toContain('OTHER Branch');
});

it('uses the selected organization when a super admin generates reports', function () {
    [$organizationA, , , $loanA] = reportTenantFixture('Selected Organization', 'SELECTED', 1500, 75, 100, 250, 25);
    [, , , $loanB] = reportTenantFixture('Unselected Organization', 'UNSELECTED', 8_000_000, 300_000, 200_000, 700_000, 400_000);
    $role = Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
    $superAdmin = User::factory()->create([
        'organization_id' => null,
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $this->actingAs($superAdmin);
    app(TenantContext::class)->set($organizationA, true);

    $report = app(ReportCatalogService::class)->generate('loan-disbursements', [
        'from' => today()->toDateString(),
        'to' => today()->toDateString(),
    ], $superAdmin);

    expect($report['summary']['Gross principal disbursed'])->toBe('UGX 1,500.00')
        ->and($report['rows']->pluck('Loan')->all())->toBe([$loanA->loan_number])
        ->and($report['rows']->pluck('Loan'))->not->toContain($loanB->loan_number);
});

it('keeps every registered report executable inside the active organization boundary', function () {
    [, , $adminA] = reportTenantFixture('Complete Report Organization', 'COMPLETE', 2000, 100, 150, 350, 60);
    [, , , $foreignLoan] = reportTenantFixture('Foreign Report Organization', 'FOREIGN', 7_000_000, 250_000, 175_000, 600_000, 300_000);
    $this->actingAs($adminA);
    $reports = app(ReportCatalogService::class);
    $filters = [
        'from' => today()->toDateString(),
        'to' => today()->toDateString(),
    ];

    foreach ($reports->definitions($adminA) as $definition) {
        $report = $reports->generate($definition['slug'], $filters, $adminA);
        $renderedRows = json_encode($report['rows']->values()->all(), JSON_THROW_ON_ERROR);

        expect($renderedRows, "Report [{$definition['slug']}] included another organization's loan.")
            ->not->toContain($foreignLoan->loan_number)
            ->not->toContain('FOREIGN Branch')
            ->not->toContain('FOREIGN Client');
    }
});

it('isolates API report totals to the authenticated organization', function () {
    [, , $adminA] = reportTenantFixture('API Report Organization', 'API', 2400, 120, 180, 400, 80);
    reportTenantFixture('Foreign API Report Organization', 'FOREIGNAPI', 6_000_000, 200_000, 160_000, 500_000, 250_000);
    Sanctum::actingAs($adminA, ['*']);

    $this->getJson('/api/v1/reports/dashboard')
        ->assertSuccessful()
        ->assertJsonPath('data.clients', 1)
        ->assertJsonPath('data.active_clients', 1)
        ->assertJsonPath('data.loan_portfolio', 2300);

    $this->getJson('/api/v1/reports/loans')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.total', 1)
        ->assertJsonPath('data.0.principal', 2300);
});

it('requires a super admin to select an organization before opening reports', function () {
    $role = Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
    $permission = Permission::query()->firstOrCreate(['slug' => 'view-reports'], ['name' => 'View Reports']);
    $role->permissions()->syncWithoutDetaching([$permission->id]);
    $superAdmin = User::factory()->create([
        'organization_id' => null,
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->actingAs($superAdmin)
        ->get(route('reports.index'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('warning', 'Select an organization before opening reports.');
});

/** @return array{Organization, Branch, User, Loan} */
function reportTenantFixture(string $organizationName, string $code, int $disbursed, int $fee, int $interest, int $ledgerIncome, int $expense): array
{
    $organization = Organization::create([
        'name' => $organizationName,
        'slug' => str($organizationName)->slug().'-'.strtolower($code),
        'status' => 'active',
    ]);
    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($organization);
    Module::query()->pluck('id')->each(fn (int $moduleId) => OrganizationModule::updateOrCreate(
        ['organization_id' => $organization->id, 'module_id' => $moduleId],
        ['is_enabled' => true, 'enabled_at' => now()],
    ));
    app(ModuleService::class)->clearCache($organization);
    $role = Role::query()->firstOrCreate(['slug' => 'organization-admin'], ['name' => 'Organization Admin']);
    $permission = Permission::query()->firstOrCreate(['slug' => 'view-reports'], ['name' => 'View Reports']);
    $role->permissions()->syncWithoutDetaching([$permission->id]);
    $admin = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => $code,
        'name' => "{$code} Branch",
        'is_active' => true,
        'status' => 'active',
    ]);
    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => "{$code}-C000001",
        'client_type' => 'individual',
        'first_name' => $code,
        'last_name' => 'Client',
        'phone' => '2567'.str_pad((string) $organization->id, 8, '0', STR_PAD_LEFT),
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $product = LoanProduct::create([
        'organization_id' => $organization->id,
        'name' => "{$code} Loan",
        'code' => "{$code}-LOAN",
        'annual_interest_rate' => 24,
        'interest_method' => 'flat',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 1,
        'status' => 'active',
        'is_active' => true,
    ]);
    $loan = Loan::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $product->id,
        'loan_number' => "{$code}-LN000001",
        'requested_amount' => $disbursed,
        'approved_amount' => $disbursed,
        'principal_balance' => $disbursed - 100,
        'interest_balance' => 20,
        'interest_rate' => 24,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
        'application_date' => today(),
        'disbursed_at' => today(),
    ]);
    LoanDisbursement::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'loan_id' => $loan->id,
        'disbursed_amount' => $disbursed,
        'fees_deducted' => $fee,
        'net_amount' => $disbursed - $fee,
        'payment_method' => 'cash',
        'transaction_reference' => "{$code}-DISB-001",
        'disbursement_date' => today(),
        'status' => 'successful',
    ]);
    LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today(),
        'principal_due' => $disbursed,
        'interest_due' => $interest + 50,
        'interest_paid' => $interest,
        'total_due' => $disbursed + $interest + 50,
        'outstanding_amount' => $disbursed,
        'status' => 'partial',
    ]);
    LoanRepayment::create([
        'organization_id' => $organization->id,
        'loan_id' => $loan->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'amount' => $interest + 100,
        'interest_paid' => $interest,
        'principal_paid' => 100,
        'paid_at' => today(),
        'status' => 'successful',
    ]);
    reportTenantLedgerEntry($organization, $branch, "{$code}-INCOME", 'Income', 'income', 'credit', $ledgerIncome);
    reportTenantLedgerEntry($organization, $branch, "{$code}-EXPENSE", 'Expense', 'expense', 'debit', $expense);

    return [$organization, $branch, $admin, $loan];
}

function reportTenantLedgerEntry(Organization $organization, Branch $branch, string $code, string $name, string $type, string $entryType, int $amount): void
{
    $account = ChartAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'code' => $code,
        'name' => $name,
        'type' => $type,
        'normal_balance' => $type === 'expense' ? 'debit' : 'credit',
        'is_active' => true,
    ]);
    JournalEntry::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'chart_account_id' => $account->id,
        'reference' => "JE-{$code}",
        'account_name' => $account->name,
        'entry_type' => $entryType,
        'amount' => $amount,
        'status' => 'posted',
        'posted_at' => now(),
    ]);
}

it('keeps every report row and total unchanged when inaccessible organizations and branches gain activity', function (string $roleSlug) {
    [$organization, $branch, $user] = reportTenantFixture('Boundary Report Organization', 'BOUNDARY', 2000, 100, 150, 350, 60);
    if ($roleSlug === 'branch-manager') {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => 'Branch Manager']);
        $role->permissions()->syncWithoutDetaching(Permission::where('slug', 'view-reports')->pluck('id'));
        $user->update(['role_id' => $role->id, 'branch_id' => $branch->id]);
        $user->unsetRelation('role');
    }
    $this->actingAs($user);
    $reports = app(ReportCatalogService::class);
    $filters = ['from' => today()->toDateString(), 'to' => today()->toDateString()];
    $snapshots = [];
    foreach ($reports->definitions($user) as $definition) {
        $report = $reports->generate($definition['slug'], $filters, $user);
        $snapshots[$definition['slug']] = [$report['rows']->all(), $report['summary']];
    }

    auth()->forgetGuards();
    app(TenantContext::class)->clear();
    [$otherOrganization] = reportTenantFixture('Inaccessible Report Organization', 'INACCESSIBLE', 9_000_000, 500_000, 400_000, 600_000, 300_000);
    if ($roleSlug === 'branch-manager') {
        foreach ([Branch::class, User::class, Client::class, LoanProduct::class, Loan::class, LoanDisbursement::class, LoanRepayment::class, ChartAccount::class, JournalEntry::class] as $model) {
            $model::withoutGlobalScopes()->where('organization_id', $otherOrganization->id)->update(['organization_id' => $organization->id]);
        }
    }
    $this->actingAs($user);
    foreach ($snapshots as $slug => $snapshot) {
        $report = $reports->generate($slug, $filters, $user);
        expect([$report['rows']->all(), $report['summary']], "Report [{$slug}] changed after inaccessible activity.")->toBe($snapshot);
    }
})->with(['organization-admin', 'branch-manager']);

it('scopes loan disbursement reversals even when a foreign record references an accessible branch', function () {
    [$organization, $branch, $admin, $ownLoan] = reportTenantFixture('Reversal Organization', 'OWNREV', 2000, 100, 150, 350, 60);
    [, , , $foreignLoan] = reportTenantFixture('Foreign Reversal Organization', 'FOREIGNREV', 9_000_000, 500_000, 400_000, 600_000, 300_000);
    LoanDisbursement::query()->update(['status' => 'reversed']);
    LoanDisbursement::where('loan_id', $foreignLoan->id)->update(['branch_id' => $branch->id]);
    $this->actingAs($admin);
    $report = app(ReportCatalogService::class)->generate('loan-disbursement-reversals', [], $admin);
    expect($report['rows']->pluck('Loan')->all())->toBe([$ownLoan->loan_number])
        ->and($report['summary']['Gross principal disbursed'])->toBe('UGX 2,000.00');
});
