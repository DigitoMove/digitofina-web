<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\JournalLine;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TellerDrawer;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\DrawerTransferService;
use App\Services\LedgerChargeService;
use App\Services\LedgerOpeningBalanceService;
use App\Services\ModuleService;
use App\Services\TransactionPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function drawerAccountingContext(): array
{
    $organization = Organization::create([
        'name' => 'Drawer Accounting SACCO',
        'slug' => 'drawer-accounting-sacco',
        'status' => 'active',
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'BR01',
        'name' => 'Main Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Super Admin',
        'slug' => 'super-admin',
    ]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $cashAccount = ChartAccount::create([
        'organization_id' => $organization->id,
        'code' => '1010',
        'name' => 'Cash on Hand',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_cash_account' => true,
        'is_system' => true,
        'is_active' => true,
    ]);

    return [$organization, $branch, $user, $cashAccount];
}

function openAccountingDrawer(Organization $organization, Branch $branch, User $user, string $code, float $balance): array
{
    $drawer = TellerDrawer::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'assigned_user_id' => $user->id,
        'name' => $code.' Drawer',
        'code' => $code,
        'currency' => 'UGX',
        'current_balance' => $balance,
        'status' => 'open',
    ]);
    $session = DrawerSession::create([
        'organization_id' => $organization->id,
        'teller_drawer_id' => $drawer->id,
        'user_id' => $user->id,
        'opening_balance' => $balance,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    return [$drawer, $session];
}

it('posts a balanced opening balance for a merchant code ledger', function () {
    [$organization, $branch, $user] = drawerAccountingContext();
    $merchantCode = ChartAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'code' => 'BR01-1105',
        'name' => 'Merchant Code Clearing',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'drawer_direction' => 'both',
        'is_active' => true,
    ]);

    $journal = app(LedgerOpeningBalanceService::class)->post(
        $merchantCode,
        750_000,
        'debit',
        today(),
        $user,
        'Imported merchant code balance',
    );

    expect((float) $journal->lines()->sum('debit'))->toBe(750000.0)
        ->and((float) $journal->lines()->sum('credit'))->toBe(750000.0)
        ->and((float) $merchantCode->journalLines()->sum('debit'))->toBe(750000.0)
        ->and(ChartAccount::query()->where('code', 'BR01-3990')->where('name', 'Opening Balance Equity')->exists())->toBeTrue();
});

it('transfers cash atomically and charges only the source drawer', function () {
    [$organization, $branch, $user] = drawerAccountingContext();
    [$sourceDrawer, $sourceSession] = openAccountingDrawer($organization, $branch, $user, 'SRC-01', 1_000_000);
    [$destinationDrawer, $destinationSession] = openAccountingDrawer($organization, $branch, $user, 'DST-01', 200_000);

    $transaction = app(DrawerTransferService::class)->transfer(
        $sourceSession,
        $destinationSession,
        100_000,
        1_000,
        $user,
        'Reallocate teller float',
        today(),
    );

    expect((float) $sourceDrawer->fresh()->current_balance)->toBe(899000.0)
        ->and((float) $destinationDrawer->fresh()->current_balance)->toBe(300000.0)
        ->and((float) $transaction->charges)->toBe(1000.0)
        ->and((float) DrawerMovement::where('reference', $transaction->reference)->where('type', 'cash_out')->sum('amount'))->toBe(101000.0)
        ->and((float) DrawerMovement::where('reference', $transaction->reference)->where('type', 'cash_in')->sum('amount'))->toBe(100000.0)
        ->and((float) JournalLine::whereHas('journalEntry', fn ($query) => $query->where('transaction_id', $transaction->id))->sum('debit'))->toBe(1000.0)
        ->and((float) JournalLine::whereHas('journalEntry', fn ($query) => $query->where('transaction_id', $transaction->id))->sum('credit'))->toBe(1000.0);
});

it('adds ledger cash to a drawer net of charges and keeps the journal balanced', function () {
    [$organization, $branch, $user] = drawerAccountingContext();
    [$drawer, $session] = openAccountingDrawer($organization, $branch, $user, 'MAIN-01', 200_000);
    $merchantCode = ChartAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'code' => 'MTN-MERCHANT',
        'name' => 'Merchant Code Clearing',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'drawer_direction' => 'cash_in',
        'is_active' => true,
    ]);

    $journal = app(TransactionPostingService::class)->postGeneralLedgerCash(
        $merchantCode,
        $session,
        'credit',
        100_000,
        $user,
        'Withdraw merchant float into drawer',
        today(),
        1_000,
    );

    expect((float) $drawer->fresh()->current_balance)->toBe(299000.0)
        ->and((float) $journal->transaction->charges)->toBe(1000.0)
        ->and($journal->transaction->charge_mode)->toBe(LedgerChargeService::MODE_DEDUCT_FROM_AMOUNT)
        ->and((float) $journal->lines()->sum('debit'))->toBe(100000.0)
        ->and((float) $journal->lines()->sum('credit'))->toBe(100000.0)
        ->and((float) $journal->lines()->where('chart_account_id', $merchantCode->id)->sum('credit'))->toBe(100000.0)
        ->and($journal->branch_id)->toBe($branch->id)
        ->and((float) DrawerMovement::where('reference', $journal->reference)->value('amount'))->toBe(99000.0);
});

it('adds ledger cash to a drawer with charges on top when selected', function () {
    [$organization, $branch, $user] = drawerAccountingContext();
    [$drawer, $session] = openAccountingDrawer($organization, $branch, $user, 'MAIN-02', 200_000);
    $merchantCode = ChartAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'code' => 'AIRTEL-MERCHANT',
        'name' => 'Airtel Merchant Code Clearing',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'drawer_direction' => 'cash_in',
        'is_active' => true,
    ]);

    $journal = app(TransactionPostingService::class)->postGeneralLedgerCash(
        $merchantCode,
        $session,
        'credit',
        100_000,
        $user,
        'Withdraw merchant float into drawer',
        today(),
        1_000,
        LedgerChargeService::MODE_ADD_TO_AMOUNT,
    );

    expect((float) $drawer->fresh()->current_balance)->toBe(300000.0)
        ->and((float) $journal->transaction->charges)->toBe(1000.0)
        ->and($journal->transaction->charge_mode)->toBe(LedgerChargeService::MODE_ADD_TO_AMOUNT)
        ->and((float) $journal->lines()->sum('debit'))->toBe(101000.0)
        ->and((float) $journal->lines()->sum('credit'))->toBe(101000.0)
        ->and((float) $journal->lines()->where('chart_account_id', $merchantCode->id)->sum('credit'))->toBe(101000.0)
        ->and((float) DrawerMovement::where('reference', $journal->reference)->value('amount'))->toBe(100000.0);
});

it('blocks accidental duplicate teller posts for the same cashier request', function () {
    [$organization, $branch] = drawerAccountingContext();
    drawerAccountingEnableTellerModule($organization);
    $cashier = drawerAccountingCashier($organization, $branch);
    [$drawer] = openAccountingDrawer($organization, $branch, $cashier, 'POST-01', 100_000);
    $client = drawerAccountingClient($organization, $branch, $cashier);
    $accountType = AccountType::create([
        'organization_id' => $organization->id,
        'name' => 'Ordinary Savings',
        'code' => fake()->unique()->bothify('SAV-??'),
        'category' => 'savings',
        'is_active' => true,
    ]);
    $account = Account::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'account_type_id' => $accountType->id,
        'account_number' => fake()->unique()->numerify('1001-SAV-####'),
        'account_name' => $client->full_name.' Savings',
        'status' => 'active',
        'balance' => 0,
        'opened_at' => today(),
    ]);
    $transactionType = TransactionType::create([
        'organization_id' => $organization->id,
        'name' => 'Deposit',
        'code' => fake()->unique()->bothify('deposit-??'),
        'direction' => 'credit',
        'is_active' => true,
    ]);
    $payload = [
        'account_scope' => 'client_account',
        'account_number' => $account->account_number,
        'transaction_type_id' => $transactionType->id,
        'amount' => 10_000,
        'charges' => 0,
        'transaction_date' => today()->toDateString(),
        'description' => 'Counter cash deposit',
    ];

    $this->actingAs($cashier)->post(route('teller.post'), $payload)->assertSessionHasNoErrors();
    $this->actingAs($cashier)->post(route('teller.post'), $payload)->assertSessionHasErrors('amount');

    expect(Transaction::where('account_id', $account->id)->count())->toBe(1)
        ->and(DrawerMovement::where('drawer_session_id', $drawer->sessions()->value('id'))->count())->toBe(1)
        ->and((float) $account->fresh()->balance)->toBe(10000.0);
});

it('allows permitted cashiers to find and transact on savings accounts from another branch', function () {
    [$organization, $homeBranch] = drawerAccountingContext();
    drawerAccountingEnableTellerModule($organization);
    $cashier = drawerAccountingCashier($organization, $homeBranch, 'cross-branch');
    [$drawer] = openAccountingDrawer($organization, $homeBranch, $cashier, 'CROSS-01', 100_000);
    $otherBranch = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'BR02',
        'name' => 'Other Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $client = drawerAccountingClient($organization, $otherBranch, $cashier);
    $accountType = AccountType::create([
        'organization_id' => $organization->id,
        'name' => 'Cross Branch Savings',
        'code' => 'SAV-CROSS',
        'category' => 'savings',
        'is_active' => true,
    ]);
    $account = Account::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $otherBranch->id,
        'account_type_id' => $accountType->id,
        'account_number' => 'BR02-SAV-0001',
        'account_name' => $client->full_name.' Savings',
        'status' => 'active',
        'balance' => 50_000,
        'opened_at' => today(),
    ]);
    $depositType = TransactionType::create([
        'organization_id' => $organization->id,
        'name' => 'Cross Branch Deposit',
        'code' => 'cross_branch_deposit',
        'direction' => 'credit',
        'is_active' => true,
    ]);
    $withdrawalType = TransactionType::create([
        'organization_id' => $organization->id,
        'name' => 'Cross Branch Withdrawal',
        'code' => 'cross_branch_withdrawal',
        'direction' => 'withdrawal',
        'is_active' => true,
    ]);

    $this->actingAs($cashier)
        ->get(route('teller.index', ['lookup_scope' => 'account_type:'.$accountType->id, 'account_search' => $account->account_number]))
        ->assertSuccessful()
        ->assertViewHas('accountResults', fn ($accounts): bool => ! $accounts->contains('id', $account->id));

    $this->actingAs($cashier)->post(route('teller.post'), [
        'account_scope' => 'client_account',
        'account_number' => $account->account_number,
        'transaction_type_id' => $depositType->id,
        'amount' => 10_000,
        'charges' => 0,
        'transaction_date' => today()->toDateString(),
    ])->assertSessionHasErrors('amount');

    $crossBranchPermission = Permission::firstOrCreate(
        ['slug' => 'transact-savings-across-branches'],
        ['organization_id' => $organization->id, 'name' => 'Transact Savings Across Branches'],
    );
    $cashier->role->permissions()->syncWithoutDetaching([$crossBranchPermission->id]);
    $cashier->unsetRelation('role');

    expect($cashier->canPerform('transact-savings-across-branches'))->toBeTrue();

    $this->actingAs($cashier)
        ->get(route('teller.index', ['lookup_scope' => 'account_type:'.$accountType->id, 'account_search' => $account->account_number]))
        ->assertSuccessful()
        ->assertViewHas('accountResults', fn ($accounts): bool => $accounts->contains('id', $account->id))
        ->assertSee($account->account_number)
        ->assertSee($otherBranch->name);

    foreach ([[$depositType, 10_000], [$withdrawalType, 5_000]] as [$type, $amount]) {
        $this->actingAs($cashier)->post(route('teller.post'), [
            'account_scope' => 'client_account',
            'account_number' => $account->account_number,
            'transaction_type_id' => $type->id,
            'amount' => $amount,
            'charges' => 0,
            'transaction_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();
    }

    $this->actingAs($cashier)
        ->get(route('teller.index'))
        ->assertSuccessful()
        ->assertViewHas('recentTransactions', fn ($transactions): bool => $transactions->where('account_id', $account->id)->count() === 2);

    expect((float) $account->fresh()->balance)->toBe(55000.0)
        ->and((float) $drawer->fresh()->current_balance)->toBe(105000.0)
        ->and(Transaction::query()->withoutGlobalScope('tenant')->where('account_id', $account->id)->count())->toBe(2);
});

it('allows every branch cashier to use an organization administrator savings account', function () {
    [$organization, $homeBranch, $superAdmin] = drawerAccountingContext();
    drawerAccountingEnableTellerModule($organization);
    $cashier = drawerAccountingCashier($organization, $homeBranch, 'organization-wide');
    [$drawer] = openAccountingDrawer($organization, $homeBranch, $cashier, 'ORG-WIDE-01', 100_000);
    $otherBranch = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'BR-ORG',
        'name' => 'Organization Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $adminRole = Role::create(['organization_id' => $organization->id, 'name' => 'Organization Admin', 'slug' => 'organization-admin']);
    $organizationAdmin = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $otherBranch->id,
        'role_id' => $adminRole->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $client = drawerAccountingClient($organization, $otherBranch, $superAdmin);
    $client->update(['user_id' => $organizationAdmin->id]);
    $accountType = AccountType::create([
        'organization_id' => $organization->id,
        'name' => 'Organization Savings',
        'code' => 'SAV-ORG-WIDE',
        'category' => 'savings',
        'is_active' => true,
    ]);
    $account = Account::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $otherBranch->id,
        'account_type_id' => $accountType->id,
        'account_number' => 'ORG-WIDE-SAV-01',
        'account_name' => 'Organization administrator savings',
        'status' => 'active',
        'balance' => 50_000,
        'opened_at' => today(),
    ]);
    $depositType = TransactionType::create([
        'organization_id' => $organization->id,
        'name' => 'Organization Savings Deposit',
        'code' => 'org_savings_deposit',
        'direction' => 'credit',
        'is_active' => true,
    ]);

    $response = $this->actingAs($cashier)->get(route('teller.index', [
        'lookup_scope' => 'account_type:'.$accountType->id,
        'account_search' => $account->account_number,
    ]));

    expect($response->getContent())->toContain($account->account_number);
    $this->actingAs($cashier)->post(route('teller.post'), [
        'account_scope' => 'client_account',
        'account_number' => $account->account_number,
        'transaction_type_id' => $depositType->id,
        'amount' => 10_000,
        'charges' => 0,
        'transaction_date' => today()->toDateString(),
    ])->assertSessionHasNoErrors();

    expect((float) $account->fresh()->balance)->toBe(60_000.0)
        ->and((float) $drawer->fresh()->current_balance)->toBe(110_000.0);
});

it('shows incoming drawer transfers in the destination cashier records', function () {
    [$organization, $branch, $sourceUser] = drawerAccountingContext();
    drawerAccountingEnableTellerModule($organization);
    $destinationCashier = drawerAccountingCashier($organization, $branch, 'receiver');
    [$sourceDrawer, $sourceSession] = openAccountingDrawer($organization, $branch, $sourceUser, 'SRC-02', 500_000);
    [$destinationDrawer, $destinationSession] = openAccountingDrawer($organization, $branch, $destinationCashier, 'DST-02', 100_000);

    $transaction = app(DrawerTransferService::class)->transfer(
        $sourceSession,
        $destinationSession,
        75_000,
        0,
        $sourceUser,
        'Transfer float to receiver drawer',
        today(),
    );
    $yesterdayReference = 'DST-YESTERDAY-'.fake()->unique()->bothify('??##');
    $drawerTransferType = TransactionType::where('code', 'drawer_transfer')->firstOrFail();
    $yesterday = today()->subDay();
    Transaction::create([
        'organization_id' => $organization->id,
        'transaction_type_id' => $drawerTransferType->id,
        'drawer_session_id' => $destinationSession->id,
        'branch_id' => $branch->id,
        'user_id' => $destinationCashier->id,
        'reference' => $yesterdayReference,
        'amount' => 12_000,
        'charges' => 0,
        'balance_after' => 112_000,
        'currency' => 'UGX',
        'description' => 'Yesterday destination drawer activity',
        'status' => 'posted',
        'posted_at' => $yesterday,
        'created_at' => $yesterday,
        'updated_at' => $yesterday,
    ]);
    DrawerMovement::create([
        'organization_id' => $organization->id,
        'drawer_session_id' => $destinationSession->id,
        'user_id' => $destinationCashier->id,
        'type' => 'cash_in',
        'amount' => 12_000,
        'balance_after' => 112_000,
        'reference' => $yesterdayReference,
        'description' => 'Yesterday destination drawer activity',
        'created_at' => $yesterday,
        'updated_at' => $yesterday,
    ]);

    $response = $this->actingAs($destinationCashier)->get(route('teller.index'));

    $response->assertSuccessful()
        ->assertViewHas('recentMovements', fn ($movements): bool => $movements->contains('reference', $transaction->reference)
            && ! $movements->contains('reference', $yesterdayReference))
        ->assertViewHas('recentTransactions', fn ($transactions): bool => $transactions->contains('reference', $transaction->reference)
            && ! $transactions->contains('reference', $yesterdayReference))
        ->assertSee($transaction->reference)
        ->assertDontSee($yesterdayReference);

    $this->get(route('receipts.show', $transaction))->assertSuccessful()->assertSee($transaction->reference)
        ->assertDontSee('action="'.route('transactions.reversal-requests.store', $transaction).'"', false);

    $unrelated = $transaction->replicate();
    $unrelated->reference = 'UNRELATED-SOURCE-TRANSACTION';
    $unrelated->save();
    $this->from(route('teller.index'))->get(route('receipts.show', $unrelated))
        ->assertRedirect(route('teller.index'))->assertSessionHas('warning');

    expect((float) $sourceDrawer->fresh()->current_balance)->toBe(425000.0)
        ->and((float) $destinationDrawer->fresh()->current_balance)->toBe(175000.0);
});

it('paginates all current session transactions including older incoming transfers without leaking other sessions', function () {
    $this->travelTo(today()->setTime(12, 0));
    [$organization, $branch, $admin] = drawerAccountingContext();
    drawerAccountingEnableTellerModule($organization);
    $cashier = drawerAccountingCashier($organization, $branch);
    [, $sourceSession] = openAccountingDrawer($organization, $branch, $admin, 'PAGE-SRC', 500_000);
    [$drawer, $session] = openAccountingDrawer($organization, $branch, $cashier, 'PAGE-DST', 100_000);
    $transfer = app(DrawerTransferService::class)->transfer($sourceSession, $session, 1000, 0, $admin, 'Session pagination incoming transfer', today());

    for ($index = 1; $index <= 55; $index++) {
        $transaction = $transfer->replicate();
        $transaction->fill([
            'drawer_session_id' => $session->id,
            'reference' => sprintf('SESSION-PAGE-%03d', $index),
            'description' => 'Session pagination administrator posting',
        ])->save();
    }

    $previousSession = DrawerSession::create([
        'organization_id' => $organization->id,
        'teller_drawer_id' => $drawer->id,
        'user_id' => $cashier->id,
        'opening_balance' => 0,
        'status' => 'closed',
        'opened_at' => today(),
        'closed_at' => now()->subHour(),
    ]);
    foreach ([$previousSession, $sourceSession] as $excludedSession) {
        $transaction = $transfer->replicate();
        $transaction->fill([
            'drawer_session_id' => $excludedSession->id,
            'user_id' => $cashier->id,
            'reference' => 'EXCLUDED-SESSION-'.$excludedSession->id,
            'description' => 'Session pagination excluded posting',
        ])->save();
    }

    $yesterdayTransaction = $transfer->replicate();
    $yesterdayTransaction->fill([
        'drawer_session_id' => $session->id,
        'reference' => 'EXCLUDED-YESTERDAY',
        'posted_at' => today()->subDay(),
    ])->save();

    $this->actingAs($cashier)->get(route('teller.index', ['transaction_search' => 'Session pagination']))
        ->assertSuccessful()
        ->assertViewHas('recentTransactions', fn ($transactions): bool => $transactions->total() === 56 && $transactions->count() === 50)
        ->assertSee('transactions_page=2', false)
        ->assertSee('transaction_search=Session%20pagination', false)
        ->assertDontSee('EXCLUDED-SESSION-')
        ->assertDontSee('EXCLUDED-YESTERDAY');

    $this->get(route('teller.index', ['transaction_search' => 'Session pagination', 'transactions_page' => 2]))
        ->assertSuccessful()
        ->assertViewHas('recentTransactions', fn ($transactions): bool => $transactions->total() === 56 && $transactions->count() === 6 && $transactions->contains('reference', $transfer->reference))
        ->assertSee($transfer->reference)
        ->assertSee('Money came in')
        ->assertDontSee('EXCLUDED-SESSION-');

    $this->get(route('teller.index', ['transaction_search' => $transfer->reference]))
        ->assertSuccessful()
        ->assertViewHas('recentTransactions', fn ($transactions): bool => $transactions->total() === 1);

    $this->actingAs($admin)->post(route('teller.drawers.close', $drawer), ['closing_balance' => 101_000])
        ->assertSessionHasNoErrors();

    $this->actingAs($cashier)->get(route('teller.index'))
        ->assertSuccessful()
        ->assertViewHas('recentTransactions', fn ($transactions): bool => $transactions->total() === 0);
    expect(Transaction::query()->count())->toBe(59);
});

it('shows only current-day cashier transactions on the cashier dashboard', function () {
    [$organization, $branch] = drawerAccountingContext();
    drawerAccountingEnableTellerModule($organization);
    $cashier = drawerAccountingCashier($organization, $branch, 'dashboard');
    [, $session] = openAccountingDrawer($organization, $branch, $cashier, 'DASH-01', 100_000);
    $depositType = TransactionType::create([
        'organization_id' => $organization->id,
        'name' => 'Deposit',
        'code' => fake()->unique()->bothify('dashboard-deposit-??'),
        'direction' => 'credit',
        'is_active' => true,
    ]);
    $todayReference = 'DASH-TODAY-'.fake()->unique()->bothify('??##');
    $yesterdayReference = 'DASH-YESTERDAY-'.fake()->unique()->bothify('??##');

    Transaction::create([
        'organization_id' => $organization->id,
        'transaction_type_id' => $depositType->id,
        'drawer_session_id' => $session->id,
        'branch_id' => $branch->id,
        'user_id' => $cashier->id,
        'reference' => $todayReference,
        'amount' => 25_000,
        'charges' => 0,
        'balance_after' => 125_000,
        'currency' => 'UGX',
        'description' => 'Today cashier dashboard transaction',
        'status' => 'posted',
        'posted_at' => now(),
    ]);
    Transaction::create([
        'organization_id' => $organization->id,
        'transaction_type_id' => $depositType->id,
        'drawer_session_id' => $session->id,
        'branch_id' => $branch->id,
        'user_id' => $cashier->id,
        'reference' => $yesterdayReference,
        'amount' => 15_000,
        'charges' => 0,
        'balance_after' => 115_000,
        'currency' => 'UGX',
        'description' => 'Yesterday cashier dashboard transaction',
        'status' => 'posted',
        'posted_at' => today()->subDay(),
        'created_at' => today()->subDay(),
        'updated_at' => today()->subDay(),
    ]);

    $response = $this->actingAs($cashier)->get(route('dashboard'));

    $response->assertSuccessful()
        ->assertViewHas('cashierRecentTransactions', fn ($transactions): bool => $transactions->contains('reference', $todayReference)
            && ! $transactions->contains('reference', $yesterdayReference))
        ->assertViewHas('transactionGraph', fn ($graph): bool => (float) ($graph->first(fn (array $point): bool => $point['date']->isToday())['money_in'] ?? 0) === 25_000.0)
        ->assertSee('Money Transaction Trend')
        ->assertSee($todayReference)
        ->assertDontSee($yesterdayReference);
});

function drawerAccountingEnableTellerModule(Organization $organization): void
{
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($organization);
    Module::query()
        ->whereIn('slug', ['savings', 'teller'])
        ->get()
        ->each(fn (Module $module) => $organization->modules()->syncWithoutDetaching([
            $module->id => ['is_enabled' => true, 'enabled_at' => now()],
        ]));
}

function drawerAccountingCashier(Organization $organization, Branch $branch, string $slugSuffix = 'cashier'): User
{
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Teller Cashier '.str($slugSuffix)->headline(),
        'slug' => 'teller-cashier',
    ]);
    $permission = Permission::firstOrCreate(
        ['slug' => 'post-transactions'],
        ['organization_id' => $organization->id, 'name' => 'Post Transactions']
    );
    $role->permissions()->syncWithoutDetaching([$permission->id]);

    return User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
}

function drawerAccountingClient(Organization $organization, Branch $branch, User $officer): Client
{
    return Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'officer_id' => $officer->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->numerify('+2567########'),
        'status' => 'active',
    ]);
}
