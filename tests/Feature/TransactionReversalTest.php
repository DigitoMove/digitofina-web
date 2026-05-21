<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Client;
use App\Models\FinancialDay;
use App\Models\MakerCheckerRequest;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows admins to request reversal for a posted transaction from a locked backdated business day', function () {
    $branch = Branch::create([
        'code' => '1001',
        'name' => 'Main Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'name' => 'Super Admin',
        'slug' => 'super-admin',
    ]);
    $admin = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $type = TransactionType::create([
        'name' => 'Deposit',
        'code' => 'deposit',
        'direction' => 'credit',
        'affects_cash_drawer' => true,
        'is_active' => true,
    ]);
    $postedAt = today()->subDays(5);
    $transaction = Transaction::create([
        'transaction_type_id' => $type->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'reference' => 'TX-BACKDATED-001',
        'amount' => 50000,
        'balance_after' => 50000,
        'currency' => 'UGX',
        'description' => 'Backdated posting',
        'status' => 'posted',
        'posted_at' => $postedAt,
        'created_at' => $postedAt,
    ]);

    FinancialDay::create([
        'branch_id' => $branch->id,
        'business_date' => $postedAt->toDateString(),
        'status' => 'locked',
        'opened_by' => $admin->id,
        'closed_by' => $admin->id,
        'opened_at' => $postedAt,
        'closed_at' => $postedAt,
    ]);

    $this->actingAs($admin)
        ->from(route('receipts.show', $transaction))
        ->post(route('transactions.reversal-requests.store', $transaction), [
            'reason' => 'Correcting a backdated transaction',
        ])
        ->assertRedirect(route('receipts.show', $transaction))
        ->assertSessionHas('status', 'Reversal request submitted for admin or supervisor approval.');

    $request = MakerCheckerRequest::firstOrFail();

    expect($request->action)->toBe('transaction_reversal')
        ->and($request->subject_id)->toBe($transaction->id)
        ->and($request->status)->toBe('pending')
        ->and($request->reason)->toBe('Correcting a backdated transaction');
});

it('links listed posted transactions to their receipt details', function () {
    $branch = Branch::create([
        'code' => '1002',
        'name' => 'Second Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'name' => 'Super Admin',
        'slug' => 'super-admin',
    ]);
    $admin = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $client = Client::create([
        'branch_id' => $branch->id,
        'client_number' => '1002-C000001',
        'first_name' => 'Sarah',
        'last_name' => 'Nabukeera',
        'phone' => '+256700000002',
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $accountType = AccountType::create([
        'name' => 'Savings',
        'code' => 'SAV',
        'category' => 'savings',
        'minimum_balance' => 0,
        'is_active' => true,
    ]);
    $account = Account::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'account_type_id' => $accountType->id,
        'account_number' => '1002-SAV-000001',
        'account_name' => 'Sarah Nabukeera Savings',
        'status' => 'active',
        'balance' => 50000,
        'opened_at' => today(),
    ]);
    $type = TransactionType::create([
        'name' => 'Deposit',
        'code' => 'deposit',
        'direction' => 'credit',
        'affects_cash_drawer' => true,
        'is_active' => true,
    ]);
    $transaction = Transaction::create([
        'account_id' => $account->id,
        'transaction_type_id' => $type->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'reference' => 'TX-LINK-001',
        'amount' => 50000,
        'balance_after' => 50000,
        'currency' => 'UGX',
        'description' => 'Linked posting',
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('reports.show', 'user-posted-transactions'))
        ->assertSuccessful()
        ->assertSee('TX-LINK-001')
        ->assertSee('href="'.route('receipts.show', $transaction).'"', false);
});
