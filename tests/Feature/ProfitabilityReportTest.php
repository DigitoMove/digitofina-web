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
use App\Models\Role;
use App\Models\User;
use App\Services\ReportCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports profitability standing by branch with an all branches total', function () {
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $admin = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $luweero = Branch::create(['code' => '1001', 'name' => 'Luweero', 'is_active' => true, 'status' => 'active']);
    $matugga = Branch::create(['code' => '1002', 'name' => 'Matugga', 'is_active' => true, 'status' => 'active']);
    $product = LoanProduct::create([
        'name' => 'Business Loan',
        'code' => 'BL-PROFIT',
        'annual_interest_rate' => 24,
        'interest_method' => 'flat',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 1,
        'status' => 'active',
        'is_active' => true,
    ]);

    $luweeroLoan = profitabilityReportLoan($luweero, $product, [
        'loan_number' => 'LN-PROFIT-001',
        'approved_amount' => 1000,
        'principal_balance' => 400,
        'interest_balance' => 60,
        'fees_balance' => 10,
        'penalty_balance' => 5,
        'status' => 'active',
    ]);
    $matuggaLoan = profitabilityReportLoan($matugga, $product, [
        'loan_number' => 'LN-PROFIT-002',
        'approved_amount' => 2000,
        'principal_balance' => 1500,
        'interest_balance' => 100,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'status' => 'disbursed',
    ]);

    profitabilityReportLedger($luweero, 'income', 'credit', 900);
    profitabilityReportLedger($luweero, 'expense', 'debit', 200);
    profitabilityReportLedger($matugga, 'expense', 'debit', 30);

    LoanDisbursement::create([
        'branch_id' => $luweero->id,
        'loan_id' => $luweeroLoan->id,
        'disbursed_amount' => 1000,
        'fees_deducted' => 50,
        'net_amount' => 950,
        'payment_method' => 'cash',
        'transaction_reference' => 'DISB-PROFIT-001',
        'disbursement_date' => today(),
        'status' => 'successful',
    ]);
    LoanDisbursement::create([
        'branch_id' => $matugga->id,
        'loan_id' => $matuggaLoan->id,
        'disbursed_amount' => 2000,
        'fees_deducted' => 20,
        'net_amount' => 1980,
        'payment_method' => 'cash',
        'transaction_reference' => 'DISB-PROFIT-002',
        'disbursement_date' => today(),
        'status' => 'successful',
    ]);

    LoanSchedule::create([
        'loan_id' => $luweeroLoan->id,
        'installment_number' => 1,
        'due_date' => today(),
        'principal_due' => 200,
        'interest_due' => 100,
        'total_due' => 300,
        'outstanding_amount' => 50,
        'status' => 'partial',
    ]);
    LoanSchedule::create([
        'loan_id' => $matuggaLoan->id,
        'installment_number' => 1,
        'due_date' => today(),
        'principal_due' => 500,
        'interest_due' => 100,
        'total_due' => 600,
        'outstanding_amount' => 200,
        'status' => 'partial',
    ]);

    LoanRepayment::create([
        'loan_id' => $luweeroLoan->id,
        'client_id' => $luweeroLoan->client_id,
        'branch_id' => $luweero->id,
        'amount' => 250,
        'interest_paid' => 120,
        'principal_paid' => 130,
        'paid_at' => today(),
        'status' => 'successful',
    ]);
    LoanRepayment::create([
        'loan_id' => $matuggaLoan->id,
        'client_id' => $matuggaLoan->client_id,
        'branch_id' => $matugga->id,
        'amount' => 400,
        'interest_paid' => 80,
        'principal_paid' => 320,
        'paid_at' => today(),
        'status' => 'successful',
    ]);

    $report = app(ReportCatalogService::class)->generate('branch-profitability-standing', [
        'from' => today()->toDateString(),
        'to' => today()->toDateString(),
    ], $admin);

    expect($report['definition']['category'])->toBe('Profitability')
        ->and($report['columns'])->toBe(['Branch', 'Income', 'Expenses', 'Total Loans', 'Interest', 'Expected', 'Collected', 'Current Standing', 'Net']);

    $rows = $report['rows']->keyBy('Branch');

    expect($rows['Luweero']['Income'])->toBe('UGX 900.00')
        ->and($rows['Luweero']['Expenses'])->toBe('UGX 200.00')
        ->and($rows['Luweero']['Total Loans'])->toBe('UGX 1,000.00')
        ->and($rows['Luweero']['Interest'])->toBe('UGX 120.00')
        ->and($rows['Luweero']['Expected'])->toBe('UGX 300.00')
        ->and($rows['Luweero']['Collected'])->toBe('UGX 250.00')
        ->and($rows['Luweero']['Current Standing'])->toBe('UGX 475.00')
        ->and($rows['All Branches']['Income'])->toBe('UGX 1,000.00')
        ->and($rows['All Branches']['Expenses'])->toBe('UGX 230.00')
        ->and($rows['All Branches']['Total Loans'])->toBe('UGX 3,000.00')
        ->and($rows['All Branches']['Interest'])->toBe('UGX 200.00')
        ->and($rows['All Branches']['Expected'])->toBe('UGX 900.00')
        ->and($rows['All Branches']['Collected'])->toBe('UGX 650.00')
        ->and($rows['All Branches']['Current Standing'])->toBe('UGX 2,075.00')
        ->and($rows['All Branches']['Net'])->toBe('UGX 770.00')
        ->and($report['summary']['Branches'])->toBe('2');
});

function profitabilityReportLoan(Branch $branch, LoanProduct $product, array $overrides = []): Loan
{
    $client = Client::create([
        'branch_id' => $branch->id,
        'client_number' => $branch->code.'-C-'.fake()->unique()->numerify('####'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->e164PhoneNumber(),
        'status' => 'active',
        'registered_at' => today(),
    ]);

    return Loan::create($overrides + [
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $product->id,
        'loan_number' => fake()->unique()->numerify('LN-PROFIT-######'),
        'requested_amount' => 1000,
        'approved_amount' => 1000,
        'principal_balance' => 0,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
        'application_date' => today(),
        'approved_at' => today(),
        'disbursed_at' => today(),
    ]);
}

function profitabilityReportLedger(Branch $branch, string $type, string $entryType, int $amount): void
{
    $account = ChartAccount::create([
        'branch_id' => $branch->id,
        'code' => $branch->code.'-'.fake()->unique()->numerify('####'),
        'name' => str($type)->headline().' '.$branch->name,
        'type' => $type,
        'normal_balance' => $type === 'expense' ? 'debit' : 'credit',
        'is_active' => true,
    ]);

    JournalEntry::create([
        'branch_id' => $branch->id,
        'chart_account_id' => $account->id,
        'reference' => 'JE-PROFIT-'.fake()->unique()->numerify('####'),
        'account_name' => $account->name,
        'entry_type' => $entryType,
        'amount' => $amount,
        'memo' => 'Profitability report test',
        'status' => 'posted',
        'posted_at' => now(),
    ]);
}
