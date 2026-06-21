<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\JournalLine;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TellerDrawer;
use App\Models\User;
use App\Services\DrawerTransferService;
use App\Services\LedgerOpeningBalanceService;
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
        ->and((float) $journal->lines()->sum('debit'))->toBe(101000.0)
        ->and((float) $journal->lines()->sum('credit'))->toBe(101000.0)
        ->and($journal->branch_id)->toBe($branch->id)
        ->and((float) DrawerMovement::where('reference', $journal->reference)->value('amount'))->toBe(99000.0);
});
