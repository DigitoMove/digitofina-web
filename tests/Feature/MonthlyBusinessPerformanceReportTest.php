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
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the nine monthly business performance lines with branch-specific opening and closing positions', function () {
    $organization = Organization::create(['name' => 'Performance SACCO', 'slug' => 'performance-sacco', 'status' => 'active']);
    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($organization);
    Module::query()->pluck('id')->each(fn (int $moduleId) => OrganizationModule::updateOrCreate(
        ['organization_id' => $organization->id, 'module_id' => $moduleId],
        ['is_enabled' => true, 'enabled_at' => now()],
    ));
    app(ModuleService::class)->clearCache($organization);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => 'PERF-A', 'name' => 'North Branch', 'status' => 'active', 'is_active' => true]);
    $otherBranch = Branch::create(['organization_id' => $organization->id, 'code' => 'PERF-B', 'name' => 'South Branch', 'status' => 'active', 'is_active' => true]);
    $role = Role::firstOrCreate(['slug' => 'organization-admin'], ['name' => 'Organization Admin']);
    $role->permissions()->sync(Permission::firstOrCreate(['slug' => 'view-reports'], ['name' => 'View Reports'])->id);
    $user = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => null, 'role_id' => $role->id, 'status' => 'active', 'is_active' => true]);
    $this->actingAs($user);

    $cash = performanceAccount($organization, $branch, '1000', 'Cash on Hand', true);
    $bank = performanceAccount($organization, $branch, '1100', 'Bank Current Account');
    $receivable = performanceAccount($organization, $branch, '1200', 'Loans Receivable');
    $income = ChartAccount::create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'code' => 'PERF-4300', 'name' => 'Operating Income', 'type' => 'income', 'normal_balance' => 'credit', 'is_active' => true]);
    $expense = ChartAccount::create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'code' => 'PERF-5200', 'name' => 'Operating Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'is_active' => true]);
    $otherCash = performanceAccount($organization, $otherBranch, 'B-1000', 'Other Cash', true);
    $otherReceivable = performanceAccount($organization, $otherBranch, 'B-1200', 'Other Loans Receivable');

    performanceJournal($cash, '2026-01-31', 'debit', 100000);
    performanceJournal($bank, '2026-01-31', 'debit', 10000);
    performanceJournal($receivable, '2026-01-31', 'debit', 200000);
    performanceJournal($income, '2026-01-31', 'credit', 4000);
    performanceJournal($cash, '2026-02-10', 'debit', 20000);
    performanceJournal($bank, '2026-02-12', 'credit', 5000);
    performanceJournal($receivable, '2026-02-15', 'credit', 10000);
    performanceJournal($expense, '2026-02-20', 'debit', 7000);
    performanceJournal($otherCash, '2026-01-31', 'debit', 900000);
    performanceJournal($otherReceivable, '2026-01-31', 'debit', 900000);

    $client = Client::create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'client_number' => 'PERF-CLIENT-001', 'client_type' => 'individual', 'first_name' => 'Report', 'last_name' => 'Client', 'phone' => '+256700001111', 'status' => 'active']);
    $product = LoanProduct::create(['organization_id' => $organization->id, 'name' => 'Performance Loan', 'code' => 'PERF-LOAN', 'annual_interest_rate' => 12, 'status' => 'active', 'is_active' => true]);
    $loan = Loan::create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'client_id' => $client->id, 'loan_product_id' => $product->id, 'loan_number' => 'PERF-LN-001', 'requested_amount' => 70000, 'approved_amount' => 70000, 'principal_balance' => 60000, 'interest_balance' => 15000, 'interest_rate' => 12, 'term_periods' => 2, 'repayment_frequency' => 'monthly', 'status' => 'active']);
    foreach ([12000, 3000] as $index => $interest) {
        LoanSchedule::create(['loan_id' => $loan->id, 'installment_number' => $index + 1, 'due_date' => '2026-03-10', 'interest_due' => $interest, 'total_due' => $interest, 'outstanding_amount' => $interest, 'status' => 'pending']);
    }
    foreach ([['2026-02-10', 50000, 1000, 'PERF-DISB-001'], ['2026-02-20', 20000, 200, 'PERF-DISB-002']] as [$date, $amount, $fee, $reference]) {
        LoanDisbursement::create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'loan_id' => $loan->id, 'disbursed_amount' => $amount, 'fees_deducted' => $fee, 'net_amount' => $amount - $fee, 'payment_method' => 'cash', 'transaction_reference' => $reference, 'disbursement_date' => $date, 'status' => 'successful']);
    }
    LoanDisbursement::create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'loan_id' => $loan->id, 'disbursed_amount' => 99999, 'fees_deducted' => 999, 'net_amount' => 99000, 'payment_method' => 'cash', 'transaction_reference' => 'PERF-REVERSED', 'disbursement_date' => '2026-02-25', 'status' => 'reversed']);
    LoanRepayment::create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'loan_id' => $loan->id, 'client_id' => $client->id, 'amount' => 5500, 'principal_paid' => 2000, 'interest_paid' => 3000, 'penalty_paid' => 500, 'paid_at' => '2026-02-15', 'status' => 'successful']);
    LoanRepayment::create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'loan_id' => $loan->id, 'client_id' => $client->id, 'amount' => 10000, 'interest_paid' => 10000, 'paid_at' => '2026-02-20', 'status' => 'reversed']);

    $report = app(ReportCatalogService::class)->generate('monthly-business-performance', ['from' => '2026-02-01', 'to' => '2026-02-28', 'branch_id' => $branch->id], $user);
    $rows = $report['rows']->keyBy(fn (array $row): string => $row['No.']);

    expect($report['definition']['category'])->toBe('Business Performance')
        ->and($report['rows'])->toHaveCount(9)
        ->and($rows['1']['Basis'])->toContain('February 2026', 'Selected branch')
        ->and($rows['2']['Amount'])->toBe('UGX 70,000.00')
        ->and($rows['3']['Amount'])->toBe('UGX 15,000.00')
        ->and($rows['4']['Amount'])->toBe('UGX 1,200.00')
        ->and($rows['5']['Amount'])->toBe('UGX 500.00')
        ->and($rows['6']['Amount'])->toBe('UGX 7,000.00')
        ->and($rows['7']['Amount'])->toBe('UGX -2,300.00')
        ->and($rows['8']['Amount'])->toBe('UGX 310,000.00')
        ->and($rows['8']['Basis'])->toContain('Prior ledger profit UGX 4,000.00')
        ->and($rows['9']['Amount'])->toBe('UGX 315,000.00');

    $allBranches = app(ReportCatalogService::class)->generate('monthly-business-performance', ['from' => '2026-02-01', 'to' => '2026-02-28'], $user);
    expect($allBranches['rows']->keyBy(fn (array $row): string => $row['No.'])['9']['Amount'])->toBe('UGX 2,115,000.00');

    $defaultMonth = app(ReportCatalogService::class)->generate('monthly-business-performance', [], $user);
    expect($defaultMonth['from']->toDateString())->toBe(today()->startOfMonth()->toDateString())
        ->and($defaultMonth['to']->toDateString())->toBe(today()->endOfMonth()->toDateString());

    $this->get(route('reports.index'))->assertOk()->assertSee('Monthly Business Cash Flow &amp; Performance (OP)', false);
    $this->get(route('reports.show', ['report' => 'monthly-business-performance', 'from' => '2026-02-01', 'to' => '2026-02-28', 'branch_id' => $branch->id]))
        ->assertOk()
        ->assertSee('Interest accumulated (current disbursements)')
        ->assertSee('UGX 315,000.00');
});

function performanceAccount(Organization $organization, Branch $branch, string $code, string $name, bool $isCash = false): ChartAccount
{
    return ChartAccount::create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'code' => 'PERF-'.$code, 'name' => $name, 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_account' => $isCash, 'is_active' => true]);
}

function performanceJournal(ChartAccount $account, string $date, string $entryType, int $amount): JournalEntry
{
    return JournalEntry::create(['organization_id' => $account->organization_id, 'branch_id' => $account->branch_id, 'chart_account_id' => $account->id, 'journal_date' => $date, 'reference' => 'PERF-'.uniqid(), 'account_name' => $account->name, 'entry_type' => $entryType, 'amount' => $amount, 'status' => 'posted', 'posted_at' => $date.' 12:00:00']);
}
