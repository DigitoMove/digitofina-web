<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
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

it('aggregates lifetime business cash flow and current position by month', function () {
    [$organization, $branch, $user] = businessCashFlowFixture('Primary Business', 'PRIMARY');
    $accounts = businessCashFlowAccounts($organization, $branch);
    $loan = businessCashFlowLoan($organization, $branch, $user, 150_000, 88_000, 4_000, 2_000, 1_000);

    businessCashFlowJournal($accounts['capital'], $branch, '2026-01-15', 'credit', 100_000, 'CAPITAL-JAN');
    businessCashFlowJournal($accounts['cash'], $branch, '2026-01-15', 'debit', 100_000, 'CASH-JAN');
    businessCashFlowJournal($accounts['income'], $branch, '2026-01-20', 'credit', 30_000, 'INCOME-JAN');
    businessCashFlowJournal($accounts['expense'], $branch, '2026-02-15', 'debit', 5_000, 'EXPENSE-FEB');
    businessCashFlowJournal($accounts['income'], $branch, '2026-03-15', 'credit', 20_000, 'INCOME-MAR');
    businessCashFlowJournal($accounts['expense'], $branch, '2026-04-15', 'debit', 7_000, 'EXPENSE-APR');
    LoanDisbursement::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'loan_id' => $loan->id,
        'disbursed_amount' => 50_000,
        'fees_deducted' => 2_000,
        'net_amount' => 48_000,
        'payment_method' => 'cash',
        'transaction_reference' => 'DISB-PRIMARY-001',
        'disbursement_date' => '2026-02-10',
        'status' => 'successful',
    ]);
    LoanRepayment::create([
        'organization_id' => $organization->id,
        'loan_id' => $loan->id,
        'client_id' => $loan->client_id,
        'branch_id' => $branch->id,
        'amount' => 12_000,
        'principal_paid' => 10_000,
        'interest_paid' => 1_000,
        'fees_paid' => 500,
        'penalty_paid' => 500,
        'paid_at' => '2026-03-20',
        'status' => 'successful',
    ]);

    $report = app(ReportCatalogService::class)->generate('business-cash-flow', ['period' => 'monthly'], $user);
    $rows = $report['rows']->keyBy('Period');
    $position = $report['positionRows']->keyBy('Metric');

    expect($report['definition']['category'])->toBe('Business Performance')
        ->and($report['from']->toDateString())->toBe('2026-01-15')
        ->and($rows['Jan 2026']['Capital / Funding In'])->toBe('UGX 100,000.00')
        ->and($rows['Feb 2026']['Loan Disbursements Out'])->toBe('UGX 50,000.00')
        ->and($rows['Feb 2026']['Other Income In'])->toBe('UGX 2,000.00')
        ->and($rows['Mar 2026']['Loan Repayments In'])->toBe('UGX 12,000.00')
        ->and($rows['Mar 2026']['Principal Repaid'])->toBe('UGX 10,000.00')
        ->and($report['summary']['Selected net cash flow'])->toBe('UGX 102,000.00')
        ->and($report['summary']['Selected realized profit'])->toBe('UGX 42,000.00')
        ->and($report['summary']['Selected profit margin'])->toBe('77.78%')
        ->and($report['summary']['Cumulative / lifetime accounting profit'])->toBe('UGX 38,000.00')
        ->and($report['summary']['Expected future loan income'])->toBe('UGX 7,000.00')
        ->and($report['summary']['Estimated liquidation surplus/(shortfall)'])->toBe('UGX 195,000.00')
        ->and($position['Outstanding loan principal']['Amount'])->toBe('UGX 88,000.00')
        ->and($position['Total outstanding loan balance']['Amount'])->toBe('UGX 95,000.00')
        ->and($position['Current profit']['Amount'])->toBe('UGX 38,000.00');
});

it('shows a negative liquidation shortfall when liabilities exceed recoverable value', function () {
    [$organization, $branch, $user] = businessCashFlowFixture('Shortfall Business', 'SHORTFALL');
    $loan = businessCashFlowLoan($organization, $branch, $user, 50_000, 20_000, 2_000, 500, 500);
    $liability = ChartAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'code' => $organization->id.'-2100',
        'name' => 'Current Liabilities',
        'type' => 'liability',
        'normal_balance' => 'credit',
        'is_active' => true,
    ]);
    businessCashFlowJournal($liability, $branch, '2026-02-01', 'credit', 200_000, 'LIABILITY-SHORTFALL');

    $report = app(ReportCatalogService::class)->generate('business-cash-flow', [
        'from' => '2026-01-01',
        'to' => '2026-02-28',
    ], $user);

    expect($loan->exists)->toBeTrue()
        ->and($report['summary']['Expected future loan income'])->toBe('UGX 3,000.00')
        ->and($report['summary']['Estimated liquidation surplus/(shortfall)'])->toBe('UGX -177,000.00')
        ->and($report['liquidationRows']->firstWhere('Metric', 'Estimated liquidation surplus/(shortfall)')['Amount'])->toBe('UGX -177,000.00');
});

it('scopes liquidation estimates to the selected branch and tenant', function () {
    [$organization, $branch, $user] = businessCashFlowFixture('Branch Scope Business', 'BRANCHSCOPE');
    businessCashFlowLoan($organization, $branch, $user, 25_000, 25_000, 0, 0, 0);
    $otherBranch = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'BRANCHSCOPE-OTHER',
        'name' => 'Other Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    businessCashFlowLoan($organization, $otherBranch, $user, 900_000, 900_000, 0, 0, 0);
    [$foreignOrganization, $foreignBranch, $foreignUser] = businessCashFlowFixture('Foreign Scope Business', 'FOREIGNSCOPE');
    businessCashFlowLoan($foreignOrganization, $foreignBranch, $foreignUser, 700_000, 700_000, 0, 0, 0);

    $service = app(ReportCatalogService::class);
    $branchReport = $service->generate('business-cash-flow', [
        'from' => '2026-01-01',
        'to' => '2026-02-28',
        'branch_id' => $branch->id,
    ], $user);
    $organizationReport = $service->generate('business-cash-flow', [
        'from' => '2026-01-01',
        'to' => '2026-02-28',
    ], $user);

    expect($branchReport['summary']['Estimated liquidation surplus/(shortfall)'])->toBe('UGX 25,000.00')
        ->and($organizationReport['summary']['Estimated liquidation surplus/(shortfall)'])->toBe('UGX 925,000.00');
});

it('groups business cash flow into quarterly, half-yearly, and yearly rows', function () {
    [, $branch, $user] = businessCashFlowFixture('Period Business', 'PERIOD');
    $accounts = businessCashFlowAccounts($user->organization, $branch);
    businessCashFlowJournal($accounts['income'], $branch, '2026-01-10', 'credit', 10_000, 'Q1-INCOME');
    businessCashFlowJournal($accounts['income'], $branch, '2026-04-10', 'credit', 20_000, 'Q2-INCOME');
    businessCashFlowJournal($accounts['income'], $branch, '2026-07-10', 'credit', 30_000, 'Q3-INCOME');
    businessCashFlowJournal($accounts['income'], $branch, '2026-10-10', 'credit', 40_000, 'Q4-INCOME');

    $service = app(ReportCatalogService::class);
    $quarterly = $service->generate('business-cash-flow', ['from' => '2026-01-01', 'to' => '2026-12-31', 'period' => 'quarterly'], $user);
    $halfYearly = $service->generate('business-cash-flow', ['from' => '2026-01-01', 'to' => '2026-12-31', 'period' => 'half_yearly'], $user);
    $yearly = $service->generate('business-cash-flow', ['from' => '2026-01-01', 'to' => '2026-12-31', 'period' => 'yearly'], $user);

    expect($quarterly['rows']->pluck('Period')->all())->toContain('Q1 2026', 'Q2 2026', 'Q3 2026', 'Q4 2026', 'Lifetime / Selected Total')
        ->and($quarterly['rows']->firstWhere('Period', 'Q3 2026')['Other Income In'])->toBe('UGX 30,000.00')
        ->and($halfYearly['rows']->pluck('Period')->all())->toContain('H1 2026', 'H2 2026', 'Lifetime / Selected Total')
        ->and($halfYearly['rows']->firstWhere('Period', 'H2 2026')['Other Income In'])->toBe('UGX 70,000.00')
        ->and($yearly['rows']->pluck('Period')->all())->toContain('2026', 'Lifetime / Selected Total')
        ->and($yearly['rows']->firstWhere('Period', '2026')['Other Income In'])->toBe('UGX 100,000.00');
});

it('omits zero-activity periods while retaining offsetting activity and cumulative totals', function () {
    [, $branch, $user] = businessCashFlowFixture('Sparse Business', 'SPARSE');
    $accounts = businessCashFlowAccounts($user->organization, $branch);
    businessCashFlowJournal($accounts['income'], $branch, '2026-01-10', 'credit', 10_000, 'OFFSET-INCOME');
    businessCashFlowJournal($accounts['expense'], $branch, '2026-01-11', 'debit', 10_000, 'OFFSET-EXPENSE');
    businessCashFlowJournal($accounts['income'], $branch, '2026-03-10', 'credit', 5_000, 'MARCH-INCOME');

    $report = app(ReportCatalogService::class)->generate('business-cash-flow', [
        'from' => '2026-01-01',
        'to' => '2026-03-31',
        'period' => 'monthly',
    ], $user);
    $rows = $report['rows']->keyBy('Period');

    expect($report['rows']->pluck('Period')->all())->toBe(['Jan 2026', 'Mar 2026', 'Lifetime / Selected Total'])
        ->and($rows['Jan 2026']['Net Cash Flow'])->toBe('UGX 0.00')
        ->and($rows['Jan 2026']['Other Income In'])->toBe('UGX 10,000.00')
        ->and($rows['Mar 2026']['Cumulative Cash Flow'])->toBe('UGX 5,000.00')
        ->and($report['summary']['Selected net cash flow'])->toBe('UGX 5,000.00');
});

it('scopes business cash flow to the tenant and authorized report roles', function () {
    [$organization, $branch, $user] = businessCashFlowFixture('Scoped Business', 'SCOPED');
    $accounts = businessCashFlowAccounts($organization, $branch);
    businessCashFlowJournal($accounts['income'], $branch, '2026-02-01', 'credit', 5_000, 'OWN-INCOME');
    [$foreignOrganization, $foreignBranch, $foreignUser] = businessCashFlowFixture('Foreign Business', 'FOREIGN');
    $foreignAccounts = businessCashFlowAccounts($foreignOrganization, $foreignBranch);
    businessCashFlowJournal($foreignAccounts['income'], $foreignBranch, '2026-02-01', 'credit', 900_000, 'FOREIGN-INCOME');

    $report = app(ReportCatalogService::class)->generate('business-cash-flow', ['from' => '2026-02-01', 'to' => '2026-02-28'], $user);

    expect($report['summary']['Selected net cash flow'])->toBe('UGX 5,000.00')
        ->and($report['summary']['Estimated liquidation surplus/(shortfall)'])->toBe('UGX 0.00')
        ->and($report['rows']->pluck('Other Income In')->all())->not->toContain('UGX 900,000.00');

    $restrictedRole = Role::create(['name' => 'No Report Access', 'slug' => 'no-report-access']);
    $restrictedUser = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $restrictedRole->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->actingAs($restrictedUser)->get(route('reports.show', 'business-cash-flow'))->assertRedirect();
    expect($foreignUser->organization_id)->toBe($foreignOrganization->id);
});

it('renders the separate business performance report section and filters', function () {
    [, , $user] = businessCashFlowFixture('UI Business', 'UIBUS');

    $this->actingAs($user)
        ->get(route('reports.index'))
        ->assertSuccessful()
        ->assertSee('Business Performance')
        ->assertSee('Business Cash Flow &amp; Performance', false)
        ->assertSee('data-report-business-cash-flow="1"', false);

    $this->actingAs($user)
        ->get(route('reports.show', ['report' => 'business-cash-flow', 'from' => '2026-01-01', 'to' => '2026-12-31', 'period' => 'yearly']))
        ->assertSuccessful()
        ->assertSee('Current Business Position')
        ->assertSee('Estimated Closure / Liquidation')
        ->assertSee('Estimated liquidation surplus/(shortfall)')
        ->assertSee('Yearly view')
        ->assertSee('Total assets');
});

/**
 * @return array{0:Organization, 1:Branch, 2:User}
 */
function businessCashFlowFixture(string $name, string $code): array
{
    $organization = Organization::create(['name' => $name, 'slug' => str($name)->slug().'-'.strtolower($code), 'status' => 'active']);
    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($organization);
    Module::query()->pluck('id')->each(fn (int $moduleId) => OrganizationModule::updateOrCreate(
        ['organization_id' => $organization->id, 'module_id' => $moduleId],
        ['is_enabled' => true, 'enabled_at' => now()],
    ));
    app(ModuleService::class)->clearCache($organization);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => $code, 'name' => $name.' Branch', 'is_active' => true, 'status' => 'active']);
    $role = Role::firstOrCreate(['slug' => 'organization-admin'], ['name' => 'Organization Admin']);
    $permission = Permission::firstOrCreate(['slug' => 'view-reports'], ['name' => 'View Reports']);
    $role->permissions()->sync([$permission->id]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    return [$organization, $branch, $user];
}

/**
 * @return array{capital:ChartAccount, cash:ChartAccount, income:ChartAccount, expense:ChartAccount}
 */
function businessCashFlowAccounts(Organization $organization, Branch $branch): array
{
    return [
        'capital' => ChartAccount::create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'code' => $organization->id.'-3000', 'name' => 'Owner Capital', 'type' => 'equity', 'normal_balance' => 'credit', 'is_active' => true]),
        'cash' => ChartAccount::create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'code' => $organization->id.'-1000', 'name' => 'Cash on Hand', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_account' => true, 'is_active' => true]),
        'income' => ChartAccount::create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'code' => $organization->id.'-4300', 'name' => 'Other Business Income', 'type' => 'income', 'normal_balance' => 'credit', 'is_active' => true]),
        'expense' => ChartAccount::create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'code' => $organization->id.'-5200', 'name' => 'Operating Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'is_active' => true]),
    ];
}

function businessCashFlowJournal(ChartAccount $account, Branch $branch, string $date, string $entryType, int $amount, string $reference): JournalEntry
{
    return JournalEntry::create([
        'organization_id' => $branch->organization_id,
        'branch_id' => $branch->id,
        'chart_account_id' => $account->id,
        'journal_date' => $date,
        'reference' => $reference,
        'account_name' => $account->name,
        'entry_type' => $entryType,
        'amount' => $amount,
        'status' => 'posted',
        'posted_at' => $date.' 12:00:00',
    ]);
}

function businessCashFlowLoan(Organization $organization, Branch $branch, User $user, int $principal, int $principalBalance, int $interestBalance, int $feesBalance, int $penaltyBalance): Loan
{
    $sequence = Client::query()->where('organization_id', $organization->id)->count() + 1;
    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'officer_id' => $user->id,
        'client_number' => $organization->id.'-CLIENT-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
        'client_type' => 'individual',
        'first_name' => 'Report',
        'last_name' => 'Client',
        'phone' => '+256700000'.str_pad((string) $organization->id, 3, '0', STR_PAD_LEFT),
        'status' => 'active',
    ]);
    $product = LoanProduct::create([
        'organization_id' => $organization->id,
        'name' => 'Report Loan',
        'code' => $organization->id.'-REPORT-LOAN-'.$sequence,
        'min_amount' => 1_000,
        'max_amount' => 1_000_000,
        'annual_interest_rate' => 12,
        'interest_method' => 'flat',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 12,
        'is_active' => true,
        'status' => 'active',
    ]);

    return Loan::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => $organization->id.'-REPORT-LOAN-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
        'requested_amount' => $principal,
        'approved_amount' => $principal,
        'principal_balance' => $principalBalance,
        'interest_balance' => $interestBalance,
        'fees_balance' => $feesBalance,
        'penalty_balance' => $penaltyBalance,
        'interest_rate' => 12,
        'term_periods' => 12,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
        'disbursed_at' => '2026-01-15',
    ]);
}

test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
