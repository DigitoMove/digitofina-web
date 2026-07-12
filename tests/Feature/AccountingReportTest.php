<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TellerDrawer;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\ModuleService;
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
    accountingReportEntry($branch, '3000', 'Member Capital', 'equity', 'credit', 'credit', 675);
    $interestIncome = accountingReportEntry($branch, '4100', 'Loan Interest Income', 'income', 'credit', 'credit', 300);
    $rentExpense = accountingReportEntry($branch, '5200', 'Rent Expense', 'expense', 'debit', 'debit', 100);
    accountingReportEntry($branch, '5300', 'Advertisement Expense', 'expense', 'debit', 'debit', 75);

    $service = app(ReportCatalogService::class);
    $filters = ['from' => today()->toDateString(), 'to' => today()->toDateString(), 'branch_id' => $branch->id];

    $incomeStatement = $service->generate('income-statement', $filters, $admin);
    $incomeRows = $incomeStatement['rows']->keyBy('Account');

    expect($incomeStatement['columns'])->toBe(['Section', 'Code', 'Account', 'Debit', 'Credit', 'Amount'])
        ->and($incomeRows['Loan Interest Income']['Amount'])->toBe('UGX 300.00')
        ->and($incomeRows['Rent Expense']['Amount'])->toBe('UGX 100.00')
        ->and($incomeRows['Advertisement Expense']['Amount'])->toBe('UGX 75.00')
        ->and($incomeRows['Net Income']['Amount'])->toBe('UGX 125.00')
        ->and($incomeStatement['summary']['Income'])->toBe('UGX 300.00')
        ->and($incomeStatement['summary']['Expenses'])->toBe('UGX 175.00')
        ->and($incomeRows['Loan Interest Income']['_links'])->toHaveKey('Section')
        ->and($incomeRows['Loan Interest Income']['_links']['Account'])->toContain('chart_account_id='.$interestIncome->id)
        ->and($incomeRows['Loan Interest Income']['_links']['Amount'])->toContain('chart_account_id='.$interestIncome->id)
        ->and($incomeRows['Rent Expense']['_links']['Amount'])->toContain('chart_account_id='.$rentExpense->id);

    $this->actingAs($admin)
        ->get(route('reports.show', [
            'report' => 'gl-account-transactions',
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
            'branch_id' => $branch->id,
            'chart_account_id' => $rentExpense->id,
        ]))
        ->assertSuccessful()
        ->assertSee('Rent Expense')
        ->assertSee('JE-ACCOUNTING-5200')
        ->assertDontSee('Advertisement Expense')
        ->assertDontSee('JE-ACCOUNTING-5300');

    $balanceSheet = $service->generate('balance-sheet', $filters, $admin);
    $balanceRows = $balanceSheet['rows']->keyBy('Account');

    expect($balanceRows['Total Assets']['Amount'])->toBe('UGX 1,500.00')
        ->and($balanceRows['Total Liabilities']['Amount'])->toBe('UGX 700.00')
        ->and($balanceRows['Current / Retained Earnings']['Amount'])->toBe('UGX 125.00')
        ->and($balanceRows['Total Equity']['Amount'])->toBe('UGX 800.00')
        ->and($balanceRows['Assets - Liabilities - Equity']['Amount'])->toBe('UGX 0.00')
        ->and($balanceSheet['summary']['Balance check'])->toBe('UGX 0.00');
});

it('summarizes selected ledger accounts by day with opening and closing balances', function () {
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $admin = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $branch = Branch::create(['code' => '1001', 'name' => 'Kavule Branch', 'is_active' => true, 'status' => 'active']);
    $mtn = accountingReportLedgerAccount($branch, '1001-MTN', 'MTN Merchant Code');
    $airtel = accountingReportLedgerAccount($branch, '1001-AIR', 'Airtel Merchant Code');
    $unselected = accountingReportLedgerAccount($branch, '1001-BANK', 'Unselected Bank Account');

    accountingReportJournalEntry($mtn, $branch, '2026-01-31', 'debit', 1000, 'MTN-OPEN');
    accountingReportJournalEntry($mtn, $branch, '2026-02-01', 'debit', 300, 'MTN-IN-1');
    accountingReportJournalEntry($mtn, $branch, '2026-02-01', 'debit', 50, 'MTN-IN-2');
    accountingReportJournalEntry($mtn, $branch, '2026-02-01', 'credit', 125, 'MTN-OUT-1');
    accountingReportJournalEntry($mtn, $branch, '2026-02-02', 'credit', 75, 'MTN-OUT-2');
    accountingReportLineBackedDrawerLedgerEntry($mtn, $branch, $admin, '2026-02-02', 25);
    accountingReportJournalEntry($airtel, $branch, '2026-02-01', 'debit', 200, 'AIR-IN-1');
    accountingReportJournalEntry($unselected, $branch, '2026-02-01', 'debit', 999, 'BANK-IN-1');

    $report = app(ReportCatalogService::class)->generate('gl-daily-account-summary', [
        'from' => '2026-02-01',
        'to' => '2026-02-02',
        'branch_id' => $branch->id,
        'chart_account_ids' => [$mtn->id, $airtel->id],
    ], $admin);
    $rows = $report['rows'];
    $mtnOpening = $rows->first(fn (array $row): bool => str_contains($row['Account'], 'MTN Merchant Code') && str_starts_with($row['Description'], 'Opening balance'));
    $mtnFirstDay = $rows->first(fn (array $row): bool => str_contains($row['Account'], 'MTN Merchant Code') && $row['Date'] === '01 Feb 2026');
    $mtnSecondDay = $rows->first(fn (array $row): bool => str_contains($row['Account'], 'MTN Merchant Code') && $row['Date'] === '02 Feb 2026' && str_starts_with($row['Description'], 'Daily total'));
    $airtelFirstDay = $rows->first(fn (array $row): bool => str_contains($row['Account'], 'Airtel Merchant Code') && $row['Date'] === '01 Feb 2026');

    expect($report['definition']['slug'])->toBe('gl-daily-account-summary')
        ->and($report['columns'])->toBe(['Account', 'Date', 'Description', 'Money In', 'Money Out', 'Balance'])
        ->and($mtnOpening['Balance'])->toBe('UGX 1,000.00')
        ->and($mtnFirstDay['Description'])->toBe('Daily total for MTN Merchant Code (3 entries)')
        ->and($mtnFirstDay['Money In'])->toBe('UGX 350.00')
        ->and($mtnFirstDay['Money Out'])->toBe('UGX 125.00')
        ->and($mtnFirstDay['Balance'])->toBe('UGX 1,225.00')
        ->and($mtnSecondDay['Money Out'])->toBe('UGX 100.00')
        ->and($mtnSecondDay['Balance'])->toBe('UGX 1,125.00')
        ->and($airtelFirstDay['Money In'])->toBe('UGX 200.00')
        ->and($report['summary']['Money in'])->toBe('UGX 550.00')
        ->and($report['summary']['Money out'])->toBe('UGX 225.00')
        ->and($report['summary']['Closing balance'])->toBe('UGX 1,325.00')
        ->and($mtnFirstDay['_links']['Account'])->toContain('reports/gl-account-transactions')
        ->and($rows->contains(fn (array $row): bool => str_contains($row['Account'], 'Unselected Bank Account')))->toBeFalse();

    $drillDown = app(ReportCatalogService::class)->generate('gl-account-transactions', [
        'from' => '2026-02-02',
        'to' => '2026-02-02',
        'branch_id' => $branch->id,
        'chart_account_id' => $mtn->id,
    ], $admin);

    expect($drillDown['rows']->where('Reference', 'DRAW-LINE-MTN')->first())
        ->toMatchArray([
            'Account' => 'MTN Merchant Code',
            'Entry' => 'Credit',
            'Amount' => 'UGX 25.00',
        ]);

    $this->actingAs($admin)
        ->get(route('reports.show', [
            'report' => 'gl-daily-account-summary',
            'from' => '2026-02-01',
            'to' => '2026-02-02',
            'branch_id' => $branch->id,
            'chart_account_ids' => [$mtn->id, $airtel->id],
        ]))
        ->assertSuccessful()
        ->assertSee('GL Daily Account Summary')
        ->assertSee('Daily total for MTN Merchant Code (3 entries)')
        ->assertSee('UGX 1,225.00')
        ->assertDontSee('Daily total for Unselected Bank Account');
});

it('limits teller cashiers to the ledger accounts report category', function () {
    $organization = Organization::create(['name' => 'Kisem Financial Services', 'slug' => 'kisem-financial-services', 'status' => 'active']);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => '1001', 'name' => 'Kavule Branch', 'is_active' => true, 'status' => 'active']);
    $role = Role::create(['name' => 'Teller Cashier', 'slug' => 'teller-cashier']);
    $permission = Permission::firstOrCreate(['slug' => 'view-reports'], ['name' => 'View Reports']);
    $role->permissions()->sync([$permission->id]);
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($organization);
    OrganizationModule::updateOrCreate(
        ['organization_id' => $organization->id, 'module_id' => Module::where('slug', 'accounting')->firstOrFail()->id],
        ['is_enabled' => true, 'enabled_at' => now()],
    );
    $modules->clearCache($organization);
    $cashier = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $merchant = accountingReportLedgerAccount($branch, '1001-MTN', 'MTN Merchant Code');

    accountingReportJournalEntry($merchant, $branch, '2026-02-04', 'debit', 2500, 'CASHIER-MTN-IN');

    $this->actingAs($cashier)
        ->get(route('reports.index'))
        ->assertSuccessful()
        ->assertSee('Ledger Accounts')
        ->assertSee('GL Account Transactions')
        ->assertSee('GL Daily Account Summary')
        ->assertSee('Chart Of Accounts')
        ->assertDontSee('Posted Transactions')
        ->assertDontSee('Loan Accounts')
        ->assertDontSee('Income Statement');

    $this->actingAs($cashier)
        ->get(route('reports.show', [
            'report' => 'gl-account-transactions',
            'from' => '2026-02-04',
            'to' => '2026-02-04',
            'chart_account_id' => $merchant->id,
        ]))
        ->assertSuccessful()
        ->assertSee('GL Account Transactions')
        ->assertSee('MTN Merchant Code')
        ->assertSee('CASHIER-MTN-IN');

    $this->actingAs($cashier)
        ->get(route('reports.show', 'loan-book'))
        ->assertNotFound();

    $this->actingAs($cashier)
        ->get(route('reports.show', 'all-posted-transactions'))
        ->assertNotFound();
});

it('reports both source and destination sides of drawer transfers for the day', function () {
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $admin = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $receiver = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $branch = Branch::create(['code' => '1001', 'name' => 'Kavule Branch', 'is_active' => true, 'status' => 'active']);
    $sourceDrawer = accountingReportDrawer($branch, $admin, 'SRC-REPORT', 400000);
    $destinationDrawer = accountingReportDrawer($branch, $receiver, 'DST-REPORT', 125000);
    $sourceSession = accountingReportDrawerSession($sourceDrawer, $admin, 400000);
    $destinationSession = accountingReportDrawerSession($destinationDrawer, $receiver, 125000);

    DrawerMovement::create([
        'drawer_session_id' => $sourceSession->id,
        'user_id' => $admin->id,
        'type' => 'cash_out',
        'amount' => 101000,
        'balance_after' => 299000,
        'reference' => 'DTR-REPORT-001',
        'description' => 'Transfer float to receiving drawer',
        'created_at' => '2026-02-03 10:00:00',
        'updated_at' => '2026-02-03 10:00:00',
    ]);
    DrawerMovement::create([
        'drawer_session_id' => $destinationSession->id,
        'user_id' => $admin->id,
        'type' => 'cash_in',
        'amount' => 100000,
        'balance_after' => 225000,
        'reference' => 'DTR-REPORT-001',
        'description' => 'Transfer float to receiving drawer',
        'created_at' => '2026-02-03 10:00:00',
        'updated_at' => '2026-02-03 10:00:00',
    ]);

    $report = app(ReportCatalogService::class)->generate('daily-teller-transactions', [
        'from' => '2026-02-03',
        'to' => '2026-02-03',
        'branch_id' => $branch->id,
    ], $admin);
    $transferRows = $report['rows']->where('Reference', 'DTR-REPORT-001')->values();

    expect($transferRows)->toHaveCount(2)
        ->and($transferRows->pluck('Direction')->sort()->values()->all())->toBe(['Cash In', 'Cash Out'])
        ->and($transferRows->pluck('Drawer')->all())->toContain('SRC-REPORT Drawer', 'DST-REPORT Drawer')
        ->and($report['summary']['Cash in'])->toBe('UGX 100,000.00')
        ->and($report['summary']['Cash out'])->toBe('UGX 101,000.00')
        ->and($report['summary']['Net cash movement'])->toBe('UGX -1,000.00');
});

function accountingReportEntry(Branch $branch, string $code, string $name, string $type, string $normalBalance, string $entryType, int $amount): ChartAccount
{
    $account = ChartAccount::create([
        'organization_id' => $branch->organization_id,
        'branch_id' => $branch->id,
        'code' => $code,
        'name' => $name,
        'type' => $type,
        'normal_balance' => $normalBalance,
        'is_active' => true,
    ]);

    JournalEntry::create([
        'organization_id' => $branch->organization_id,
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

    return $account;
}

function accountingReportLedgerAccount(Branch $branch, string $code, string $name): ChartAccount
{
    return ChartAccount::create([
        'organization_id' => $branch->organization_id,
        'branch_id' => $branch->id,
        'code' => $code,
        'name' => $name,
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_active' => true,
    ]);
}

function accountingReportJournalEntry(ChartAccount $account, Branch $branch, string $date, string $entryType, int $amount, string $reference): void
{
    JournalEntry::create([
        'organization_id' => $branch->organization_id,
        'branch_id' => $branch->id,
        'chart_account_id' => $account->id,
        'journal_date' => $date,
        'reference' => $reference,
        'account_name' => $account->name,
        'entry_type' => $entryType,
        'amount' => $amount,
        'memo' => 'Daily ledger report test',
        'status' => 'posted',
        'posted_at' => $date.' 12:00:00',
    ]);
}

function accountingReportLineBackedDrawerLedgerEntry(ChartAccount $merchant, Branch $branch, User $user, string $date, int $amount): void
{
    $cash = accountingReportLedgerAccount($branch, '1001-CASH', 'Branch Cash');
    $cash->update(['is_cash_account' => true, 'is_system' => true]);
    $drawer = accountingReportDrawer($branch, $user, 'LINE-DR', 100000);
    $session = accountingReportDrawerSession($drawer, $user, 100000);
    $type = TransactionType::create([
        'name' => 'Drawer GL Cash In',
        'code' => 'drawer_gl_cash_in',
        'direction' => 'credit',
        'affects_cash_drawer' => true,
        'is_active' => true,
    ]);
    $transaction = Transaction::create([
        'organization_id' => $branch->organization_id,
        'transaction_type_id' => $type->id,
        'drawer_session_id' => $session->id,
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'reference' => 'DRAW-LINE-MTN',
        'amount' => $amount,
        'description' => 'Merchant code withdrawal into drawer',
        'status' => 'posted',
        'posted_at' => $date.' 12:00:00',
    ]);
    $journal = JournalEntry::create([
        'organization_id' => $branch->organization_id,
        'branch_id' => $branch->id,
        'chart_account_id' => $cash->id,
        'journal_date' => $date,
        'reference' => $transaction->reference,
        'transaction_id' => $transaction->id,
        'account_name' => $cash->name,
        'entry_type' => 'debit',
        'amount' => $amount,
        'memo' => 'Line-backed drawer ledger report test',
        'status' => 'posted',
        'posted_at' => $date.' 12:00:00',
    ]);

    JournalLine::create(['journal_entry_id' => $journal->id, 'chart_account_id' => $cash->id, 'debit' => $amount, 'credit' => 0, 'narration' => 'Cash received into teller drawer']);
    JournalLine::create(['journal_entry_id' => $journal->id, 'chart_account_id' => $merchant->id, 'debit' => 0, 'credit' => $amount, 'narration' => 'Merchant code withdrawal']);
}

function accountingReportDrawer(Branch $branch, User $user, string $code, int $balance): TellerDrawer
{
    return TellerDrawer::create([
        'organization_id' => $branch->organization_id,
        'branch_id' => $branch->id,
        'assigned_user_id' => $user->id,
        'name' => $code.' Drawer',
        'code' => $code,
        'currency' => 'UGX',
        'current_balance' => $balance,
        'status' => 'open',
    ]);
}

function accountingReportDrawerSession(TellerDrawer $drawer, User $user, int $openingBalance): DrawerSession
{
    return DrawerSession::create([
        'organization_id' => $drawer->organization_id,
        'teller_drawer_id' => $drawer->id,
        'user_id' => $user->id,
        'opening_balance' => $openingBalance,
        'status' => 'open',
        'opened_at' => '2026-02-03 08:00:00',
    ]);
}
