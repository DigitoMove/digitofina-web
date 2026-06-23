<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\DailyAccountingSummary;
use App\Models\DrawerSession;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TellerDrawer;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\LedgerAccountMergeService;
use App\Services\LedgerAccountStatementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ledgerManagementContext(): array
{
    $organization = Organization::create([
        'name' => 'Unified Merchant SACCO',
        'slug' => 'unified-merchant-sacco',
        'status' => 'active',
    ]);
    $branchOne = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'BR01',
        'name' => 'Main Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $branchTwo = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'BR02',
        'name' => 'Second Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Organization Admin',
        'slug' => 'organization-admin',
    ]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    return [$organization, $branchOne, $branchTwo, $user];
}

function ledgerJournal(ChartAccount $account, Branch $branch, User $user, string $number, string $date, string $entryType, float $amount, ?Transaction $transaction = null): JournalEntry
{
    return JournalEntry::create([
        'organization_id' => $account->organization_id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'posted_by' => $user->id,
        'journal_number' => $number,
        'journal_date' => $date,
        'description' => $number,
        'status' => 'posted',
        'posted_at' => $date.' 12:00:00',
        'chart_account_id' => $account->id,
        'transaction_id' => $transaction?->id,
        'reference' => $transaction?->reference ?? $number,
        'account_name' => $account->name,
        'entry_type' => $entryType,
        'amount' => $amount,
    ]);
}

it('uses activity before the selected period as opening balance and breaks down merchant flows', function () {
    [$organization, $branch, , $user] = ledgerManagementContext();
    $merchant = ChartAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'code' => 'MTN-MERCHANT',
        'name' => 'MTN Merchant Code',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'drawer_direction' => 'cash_in',
        'is_active' => true,
    ]);
    $accountType = AccountType::create(['name' => 'Loan', 'code' => 'LOAN', 'category' => 'loan', 'is_active' => true]);
    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => 'CL001',
        'first_name' => 'Amina',
        'last_name' => 'Kato',
        'phone' => '0700000001',
    ]);
    $account = Account::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'account_type_id' => $accountType->id,
        'account_number' => 'LN001',
        'account_name' => 'Amina Loan',
        'opened_at' => '2026-01-01',
    ]);
    $type = TransactionType::create(['name' => 'Loan Repayment', 'code' => 'loan_repayment', 'direction' => 'credit', 'affects_cash_drawer' => false, 'is_active' => true]);
    $drawer = TellerDrawer::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'name' => 'Main Drawer',
        'code' => 'DR01',
        'currency' => 'UGX',
        'current_balance' => 0,
        'status' => 'open',
    ]);
    $session = DrawerSession::create([
        'organization_id' => $organization->id,
        'teller_drawer_id' => $drawer->id,
        'user_id' => $user->id,
        'opening_balance' => 0,
        'status' => 'open',
        'opened_at' => '2026-01-01 08:00:00',
    ]);
    $clientPayment = Transaction::create([
        'organization_id' => $organization->id,
        'account_id' => $account->id,
        'transaction_type_id' => $type->id,
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'reference' => 'PAY001',
        'amount' => 500_000,
        'posted_at' => '2026-01-15 12:00:00',
    ]);
    $drawerFunding = Transaction::create([
        'organization_id' => $organization->id,
        'transaction_type_id' => $type->id,
        'drawer_session_id' => $session->id,
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'reference' => 'DRAW001',
        'amount' => 1_200_000,
        'posted_at' => '2026-01-20 12:00:00',
    ]);

    ledgerJournal($merchant, $branch, $user, 'OB001', '2025-12-31', 'debit', 1_000_000);
    ledgerJournal($merchant, $branch, $user, 'PAY-JE001', '2026-01-15', 'debit', 500_000, $clientPayment);
    ledgerJournal($merchant, $branch, $user, 'DRAW-JE001', '2026-01-20', 'credit', 1_200_000, $drawerFunding);

    $statement = app(LedgerAccountStatementService::class)->build(
        $merchant,
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-01-31'),
    );

    expect($statement['opening_balance'])->toBe(1000000.0)
        ->and($statement['client_payments'])->toBe(500000.0)
        ->and($statement['drawer_funding_out'])->toBe(1200000.0)
        ->and($statement['period_debits'])->toBe(500000.0)
        ->and($statement['period_credits'])->toBe(1200000.0)
        ->and($statement['closing_balance'])->toBe(300000.0)
        ->and((float) $statement['entries']->last()->running_balance_after)->toBe(300000.0);
});

it('merges branch ledgers into a global ledger without losing transaction branches', function () {
    [$organization, $branchOne, $branchTwo, $user] = ledgerManagementContext();
    $target = ChartAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'code' => 'MTN-MERCHANT',
        'name' => 'MTN Merchant Code',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'drawer_direction' => 'both',
        'is_active' => true,
    ]);
    $sourceOne = ChartAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => $branchOne->id,
        'code' => 'BR01-MTN',
        'name' => 'MTN Merchant Code',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'drawer_direction' => 'both',
        'is_active' => true,
    ]);
    $sourceTwo = ChartAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => $branchTwo->id,
        'code' => 'BR02-MTN',
        'name' => 'MTN Merchant Code',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'drawer_direction' => 'both',
        'is_active' => true,
    ]);
    $transactionType = TransactionType::create(['name' => 'Merchant Receipt', 'code' => 'merchant_receipt', 'direction' => 'credit', 'affects_cash_drawer' => false, 'is_active' => true]);
    $transaction = Transaction::create([
        'organization_id' => $organization->id,
        'transaction_type_id' => $transactionType->id,
        'branch_id' => $branchOne->id,
        'user_id' => $user->id,
        'reference' => 'MERGE-TXN001',
        'amount' => 300_000,
        'description' => 'Original branch merchant receipt',
        'posted_at' => '2026-01-10 12:00:00',
    ]);
    $journalOne = ledgerJournal($sourceOne, $branchOne, $user, 'MERGE001', '2026-01-10', 'debit', 300_000, $transaction);
    $journalTwo = ledgerJournal($sourceTwo, $branchTwo, $user, 'MERGE002', '2026-01-11', 'debit', 200_000);
    JournalLine::create(['journal_entry_id' => $journalOne->id, 'chart_account_id' => $sourceOne->id, 'debit' => 300_000, 'credit' => 0]);
    JournalLine::create(['journal_entry_id' => $journalTwo->id, 'chart_account_id' => $sourceTwo->id, 'debit' => 200_000, 'credit' => 0]);
    DailyAccountingSummary::create([
        'organization_id' => $organization->id,
        'branch_id' => $branchOne->id,
        'chart_account_id' => $target->id,
        'business_date' => '2026-01-10',
        'account_code' => $target->code,
        'account_name' => $target->name,
        'debit_total' => 100_000,
        'credit_total' => 0,
        'net_movement' => 100_000,
        'line_count' => 1,
    ]);
    DailyAccountingSummary::create([
        'organization_id' => $organization->id,
        'branch_id' => $branchOne->id,
        'chart_account_id' => $sourceOne->id,
        'business_date' => '2026-01-10',
        'account_code' => $sourceOne->code,
        'account_name' => $sourceOne->name,
        'debit_total' => 300_000,
        'credit_total' => 0,
        'net_movement' => 300_000,
        'line_count' => 1,
    ]);

    $result = app(LedgerAccountMergeService::class)->merge($target, [$sourceOne->id, $sourceTwo->id], $user, 'Unify MTN across branches');

    expect($result['journal_entries'])->toBe(2)
        ->and($result['journal_lines'])->toBe(2)
        ->and($journalOne->fresh()->chart_account_id)->toBe($target->id)
        ->and($journalOne->fresh()->branch_id)->toBe($branchOne->id)
        ->and($journalOne->fresh()->transaction_id)->toBe($transaction->id)
        ->and($transaction->fresh()->branch_id)->toBe($branchOne->id)
        ->and($transaction->fresh()->description)->toBe('Original branch merchant receipt')
        ->and($journalTwo->fresh()->branch_id)->toBe($branchTwo->id)
        ->and($sourceOne->fresh()->is_active)->toBeFalse()
        ->and($sourceOne->fresh()->merged_into_id)->toBe($target->id)
        ->and((float) DailyAccountingSummary::query()->where('branch_id', $branchOne->id)->where('chart_account_id', $target->id)->value('debit_total'))->toBe(400000.0)
        ->and(DailyAccountingSummary::query()->where('branch_id', $branchOne->id)->whereDate('business_date', '2026-01-10')->count())->toBe(1);
});
