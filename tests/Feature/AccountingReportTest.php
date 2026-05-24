<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\ReportCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates accountant style income statement and balance sheet reports', function () {
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $admin = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $branch = Branch::create(['code' => '1001', 'name' => 'Luweero', 'is_active' => true, 'status' => 'active']);

    accountingReportEntry($branch, '1000', 'Cash on Hand', 'asset', 'debit', 'debit', 1000);
    accountingReportEntry($branch, '1200', 'Loans Receivable', 'asset', 'debit', 'debit', 500);
    accountingReportEntry($branch, '2100', 'Client Savings Liability', 'liability', 'credit', 'credit', 700);
    accountingReportEntry($branch, '3000', 'Member Capital', 'equity', 'credit', 'credit', 600);
    accountingReportEntry($branch, '4100', 'Loan Interest Income', 'income', 'credit', 'credit', 300);
    accountingReportEntry($branch, '5200', 'Rent Expense', 'expense', 'debit', 'debit', 100);

    $service = app(ReportCatalogService::class);
    $filters = ['from' => today()->toDateString(), 'to' => today()->toDateString(), 'branch_id' => $branch->id];

    $incomeStatement = $service->generate('income-statement', $filters, $admin);
    $incomeRows = $incomeStatement['rows']->keyBy('Account');

    expect($incomeStatement['columns'])->toBe(['Section', 'Code', 'Account', 'Debit', 'Credit', 'Amount'])
        ->and($incomeRows['Loan Interest Income']['Amount'])->toBe('UGX 300.00')
        ->and($incomeRows['Rent Expense']['Amount'])->toBe('UGX 100.00')
        ->and($incomeRows['Net Income']['Amount'])->toBe('UGX 200.00')
        ->and($incomeStatement['summary']['Income'])->toBe('UGX 300.00')
        ->and($incomeStatement['summary']['Expenses'])->toBe('UGX 100.00')
        ->and($incomeRows['Loan Interest Income']['_links'])->toHaveKey('Section');

    $balanceSheet = $service->generate('balance-sheet', $filters, $admin);
    $balanceRows = $balanceSheet['rows']->keyBy('Account');

    expect($balanceRows['Total Assets']['Amount'])->toBe('UGX 1,500.00')
        ->and($balanceRows['Total Liabilities']['Amount'])->toBe('UGX 700.00')
        ->and($balanceRows['Current / Retained Earnings']['Amount'])->toBe('UGX 200.00')
        ->and($balanceRows['Total Equity']['Amount'])->toBe('UGX 800.00')
        ->and($balanceRows['Assets - Liabilities - Equity']['Amount'])->toBe('UGX 0.00')
        ->and($balanceSheet['summary']['Balance check'])->toBe('UGX 0.00');
});

function accountingReportEntry(Branch $branch, string $code, string $name, string $type, string $normalBalance, string $entryType, int $amount): void
{
    $account = ChartAccount::create([
        'branch_id' => $branch->id,
        'code' => $code,
        'name' => $name,
        'type' => $type,
        'normal_balance' => $normalBalance,
        'is_active' => true,
    ]);

    JournalEntry::create([
        'branch_id' => $branch->id,
        'chart_account_id' => $account->id,
        'reference' => 'JE-ACCOUNTING-'.$code,
        'account_name' => $account->name,
        'entry_type' => $entryType,
        'amount' => $amount,
        'memo' => 'Accounting report test',
        'status' => 'posted',
        'posted_at' => now(),
    ]);
}
