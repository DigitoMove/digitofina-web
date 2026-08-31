<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\LoanSchedule;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\ReportCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('places the simplified income statement first and defaults it to a full month', function () {
    $user = generalReportUser();

    $response = $this->actingAs($user)->get(route('reports.index'));

    $response
        ->assertSuccessful()
        ->assertSeeInOrder(['General Reports', 'Simplified Income Statement', 'Posted Transactions'])
        ->assertSee('data-report-from="'.now()->startOfMonth()->toDateString().'"', false)
        ->assertSee('data-report-to="'.now()->endOfMonth()->toDateString().'"', false);
});

it('generates a branch and period filtered simplified income statement', function () {
    $user = generalReportUser();
    $this->actingAs($user);
    $branch = Branch::create(['code' => '1001', 'name' => 'Main Branch', 'is_active' => true, 'status' => 'active']);
    $otherBranch = Branch::create(['code' => '1002', 'name' => 'Other Branch', 'is_active' => true, 'status' => 'active']);
    $weekly = generalReportLoanProduct('Weekly Loan', 'WEEKLY');
    $monthly = generalReportLoanProduct('Monthly Loan', 'MONTHLY');

    generalReportRepayment($branch, $weekly, 120, 30);
    generalReportRepayment($branch, $monthly, 80, 20);
    generalReportRepayment($otherBranch, $weekly, 999, 999);

    generalReportLedger($branch, '1001-4330', 'Application Fees Income', 'income', 'credit', 50);
    generalReportLedger($branch, '1001-4320', 'Other Business Income', 'income', 'credit', 70);
    generalReportLedger($branch, '1001-5200', 'Transport Expense', 'expense', 'debit', 90);
    generalReportLedger($otherBranch, '1002-5200', 'Other Branch Expense', 'expense', 'debit', 500);

    $report = app(ReportCatalogService::class)->generate('general-income-statement', [
        'from' => today()->startOfMonth()->toDateString(),
        'to' => today()->endOfMonth()->toDateString(),
        'branch_id' => $branch->id,
    ], $user);
    $rows = $report['rows']->keyBy('Source');

    expect($report['definition']['category'])->toBe('General Reports')
        ->and($rows['Interest - Weekly Loan']['Amount'])->toBe('UGX 120.00')
        ->and($rows['Interest - Monthly Loan']['Amount'])->toBe('UGX 80.00')
        ->and($rows['Application Fees']['Amount'])->toBe('UGX 50.00')
        ->and($rows['Penalties']['Amount'])->toBe('UGX 50.00')
        ->and($rows['Other Business Income']['Amount'])->toBe('UGX 70.00')
        ->and($rows['Total Income / Money In']['Amount'])->toBe('UGX 370.00')
        ->and($rows['Total Expenses / Money Out']['Amount'])->toBe('UGX 90.00')
        ->and($rows['Profit']['Amount'])->toBe('UGX 280.00')
        ->and($report['summary']['Income'])->toBe('UGX 370.00')
        ->and($report['summary']['Money in'])->toBe('UGX 370.00')
        ->and($report['summary']['Money out'])->toBe('UGX 90.00')
        ->and($report['summary']['Profit'])->toBe('UGX 280.00');

    $this->actingAs($user)
        ->get(route('reports.show', [
            'report' => 'general-income-statement',
            'from' => today()->startOfMonth()->toDateString(),
            'to' => today()->endOfMonth()->toDateString(),
            'branch_id' => $branch->id,
        ]))
        ->assertSuccessful()
        ->assertSee('Apply Filters')
        ->assertSee('Interest - Weekly Loan')
        ->assertSee('Total Income / Money In')
        ->assertDontSee('Other Branch Expense');
});

it('generates the projected simplified income statement from scheduled interest', function () {
    $user = generalReportUser();
    $this->actingAs($user);
    $branch = Branch::create(['code' => '1001', 'name' => 'Main Branch', 'is_active' => true, 'status' => 'active']);
    $product = generalReportLoanProduct('Projected Loan', 'PROJECTED');
    $loan = generalReportRepayment($branch, $product, 40, 10);

    LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today(),
        'principal_due' => 1000,
        'interest_due' => 200,
        'total_due' => 1200,
        'outstanding_amount' => 1160,
        'balance_due' => 1160,
        'interest_paid' => 40,
        'status' => 'partial',
    ]);

    $report = app(ReportCatalogService::class)->generate('projected-general-income-statement', [
        'from' => today()->startOfMonth()->toDateString(),
        'to' => today()->endOfMonth()->toDateString(),
        'branch_id' => $branch->id,
    ], $user);
    $rows = $report['rows']->keyBy('Source');

    expect($report['definition']['title'])->toBe('Simplified Income Statement - Projected')
        ->and($rows['Interest - Projected Loan']['Amount'])->toBe('UGX 200.00')
        ->and($rows['Interest - Projected Loan']['Explanation'])->toContain('projected')
        ->and($rows['Total Income / Money In']['Amount'])->toBe('UGX 210.00')
        ->and($report['summary']['Income'])->toBe('UGX 210.00');
});

it('includes legacy and rejected application fees in income and profitability reports', function () {
    $user = generalReportUser();
    $this->actingAs($user);
    $branch = Branch::create(['code' => 'FEE1', 'name' => 'Fee Branch', 'is_active' => true, 'status' => 'active']);
    $product = generalReportLoanProduct('Fee Loan', 'FEE-LOAN');
    $loan = generalReportRepayment($branch, $product, 0, 0);

    DB::table('loan_disbursements')->insert([
        'organization_id' => null,
        'branch_id' => $branch->id,
        'loan_id' => $loan->id,
        'disbursed_by' => $user->id,
        'disbursed_amount' => 1_000,
        'fees_deducted' => 75,
        'net_amount' => 925,
        'payment_method' => 'cash',
        'transaction_reference' => 'LEGACY-FEE-001',
        'disbursement_date' => today()->startOfDay(),
        'status' => 'successful',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $feeType = TransactionType::create([
        'organization_id' => $branch->organization_id,
        'name' => 'Loan Application Fee',
        'code' => 'loan_application_fee',
        'direction' => 'debit',
        'affects_cash_drawer' => false,
        'is_active' => true,
    ]);
    Transaction::create([
        'organization_id' => $branch->organization_id,
        'transaction_type_id' => $feeType->id,
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'reference' => 'REJECTED-FEE-001',
        'amount' => 25,
        'description' => 'Loan application fee for rejected loan FEE-LN-001',
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    $filters = [
        'from' => today()->toDateString(),
        'to' => today()->toDateString(),
        'branch_id' => $branch->id,
    ];
    $reports = app(ReportCatalogService::class);
    $simplified = $reports->generate('general-income-statement', $filters, $user);
    $accounting = $reports->generate('income-statement', $filters, $user);
    $profitability = $reports->generate('profitability-summary', $filters, $user);
    $feeDetails = $reports->generate('application-fees-income', $filters, $user);

    expect($simplified['rows']->keyBy('Source')['Application Fees']['Amount'])->toBe('UGX 100.00')
        ->and($accounting['rows']->keyBy('Account')['Application Fees Income (Reconciled)']['Amount'])->toBe('UGX 100.00')
        ->and($accounting['summary']['Income'])->toBe('UGX 100.00')
        ->and($profitability['rows']->keyBy('Metric')['Application fees received']['Amount'])->toBe('UGX 100.00')
        ->and($feeDetails['summary']['Application fees charged'])->toBe('UGX 100.00')
        ->and($feeDetails['rows']->pluck('Loan'))->toContain('Rejected loan application');
});

function generalReportUser(): User
{
    $organization = Organization::create([
        'name' => fake()->company(),
        'slug' => fake()->unique()->slug(),
        'status' => 'active',
    ]);
    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($organization);
    Module::query()->pluck('id')->each(fn (int $moduleId) => OrganizationModule::updateOrCreate(
        ['organization_id' => $organization->id, 'module_id' => $moduleId],
        ['is_enabled' => true, 'enabled_at' => now()],
    ));
    app(ModuleService::class)->clearCache($organization);
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);

    return User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
}

function generalReportLoanProduct(string $name, string $code): LoanProduct
{
    return LoanProduct::create([
        'name' => $name,
        'code' => $code,
        'annual_interest_rate' => 24,
        'interest_method' => 'flat',
        'repayment_frequency' => str($name)->contains('Weekly') ? 'weekly' : 'monthly',
        'default_term_months' => 1,
        'status' => 'active',
        'is_active' => true,
    ]);
}

function generalReportRepayment(Branch $branch, LoanProduct $product, int $interest, int $penalty): Loan
{
    $client = Client::create([
        'branch_id' => $branch->id,
        'client_number' => fake()->unique()->numerify('C-######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->e164PhoneNumber(),
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $product->id,
        'loan_number' => fake()->unique()->numerify('LN-######'),
        'requested_amount' => 1000,
        'approved_amount' => 1000,
        'interest_rate' => 24,
        'term_periods' => 1,
        'repayment_frequency' => $product->repayment_frequency,
        'status' => 'active',
        'application_date' => today(),
    ]);

    LoanRepayment::create([
        'loan_id' => $loan->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'amount' => $interest + $penalty,
        'interest_paid' => $interest,
        'penalty_paid' => $penalty,
        'paid_at' => today(),
        'status' => 'successful',
    ]);

    return $loan;
}

function generalReportLedger(Branch $branch, string $code, string $name, string $type, string $entryType, int $amount): void
{
    $account = ChartAccount::updateOrCreate([
        'organization_id' => $branch->organization_id,
        'code' => $code,
    ], [
        'branch_id' => $branch->id,
        'name' => $name,
        'type' => $type,
        'normal_balance' => $type === 'expense' ? 'debit' : 'credit',
        'is_active' => true,
    ]);

    JournalEntry::create([
        'branch_id' => $branch->id,
        'chart_account_id' => $account->id,
        'reference' => fake()->unique()->numerify('JE-######'),
        'account_name' => $account->name,
        'entry_type' => $entryType,
        'amount' => $amount,
        'status' => 'posted',
        'posted_at' => now(),
    ]);
}
