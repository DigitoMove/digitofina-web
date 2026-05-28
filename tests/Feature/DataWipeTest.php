<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('previews and wipes branch client data with dependent records', function () {
    [$admin, $organization, $branch, $otherBranch] = dataWipeAdmin();
    $client = dataWipeClient($organization, $branch);
    $otherClient = dataWipeClient($organization, $otherBranch);
    $transaction = dataWipeTransaction($organization, $branch, $client);
    $otherTransaction = dataWipeTransaction($organization, $otherBranch, $otherClient);

    $this->actingAs($admin)
        ->post(route('data-wipe.store'), [
            'action' => 'preview',
            'scope_type' => 'branch',
            'branch_id' => $branch->id,
            'categories' => ['clients'],
        ])
        ->assertSuccessful()
        ->assertSee('Preview For Main Branch')
        ->assertSee('Clients')
        ->assertSee('Accounts')
        ->assertSee('Transactions')
        ->assertSee('Journal entries')
        ->assertSee('Journal lines');

    $this->actingAs($admin)
        ->post(route('data-wipe.store'), [
            'action' => 'wipe',
            'scope_type' => 'branch',
            'branch_id' => $branch->id,
            'categories' => ['clients'],
            'confirmation' => 'WIPE',
        ])
        ->assertRedirect(route('data-wipe.index'))
        ->assertSessionHas('status');

    $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    $this->assertDatabaseMissing('journal_entries', ['transaction_id' => $transaction->id]);
    $this->assertDatabaseHas('clients', ['id' => $otherClient->id]);
    $this->assertDatabaseHas('transactions', ['id' => $otherTransaction->id]);
});

it('requires explicit confirmation before executing a wipe', function () {
    [$admin, , $branch] = dataWipeAdmin();

    $this->actingAs($admin)
        ->post(route('data-wipe.store'), [
            'action' => 'wipe',
            'scope_type' => 'branch',
            'branch_id' => $branch->id,
            'categories' => ['clients'],
            'confirmation' => 'DELETE',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('confirmation');
});

it('opens as a branch reset for the logged in organization with operational categories marked to clear', function () {
    [$admin, , $branch, $otherBranch] = dataWipeAdmin();

    $this->actingAs($admin)
        ->get(route('data-wipe.index'))
        ->assertSuccessful()
        ->assertSee('Branch Data Reset')
        ->assertSee($branch->name)
        ->assertSee($otherBranch->name)
        ->assertSee('Clear/delete')
        ->assertSee('Leave')
        ->assertSee('name="scope_type"', false)
        ->assertSee('value="branch"', false)
        ->assertSee('name="branch_id"', false)
        ->assertSee('name="user_id"', false);
});

it('allows selecting a branch and user target for user scoped data wipes', function () {
    [$admin, $organization, $branch, $otherBranch] = dataWipeAdmin();
    $client = dataWipeClient($organization, $branch);
    $otherClient = dataWipeClient($organization, $branch);
    $targetUser = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $branch->id]);
    $otherUser = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $branch->id]);
    $wrongBranchUser = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $otherBranch->id]);
    $targetTransaction = dataWipeTransaction($organization, $branch, $client, $targetUser);
    $otherTransaction = dataWipeTransaction($organization, $branch, $otherClient, $otherUser);

    $this->actingAs($admin)
        ->post(route('data-wipe.store'), [
            'action' => 'preview',
            'scope_type' => 'user',
            'branch_id' => $branch->id,
            'user_id' => $targetUser->id,
            'categories' => ['accounting'],
        ])
        ->assertSuccessful()
        ->assertSee('Preview For '.$targetUser->name)
        ->assertSee('Transactions');

    $this->actingAs($admin)
        ->post(route('data-wipe.store'), [
            'action' => 'preview',
            'scope_type' => 'user',
            'branch_id' => $branch->id,
            'user_id' => $wrongBranchUser->id,
            'categories' => ['accounting'],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('user_id');

    $this->actingAs($admin)
        ->post(route('data-wipe.store'), [
            'action' => 'wipe',
            'scope_type' => 'user',
            'branch_id' => $branch->id,
            'user_id' => $targetUser->id,
            'categories' => ['accounting'],
            'confirmation' => 'WIPE',
        ])
        ->assertRedirect(route('data-wipe.index'))
        ->assertSessionHas('status');

    $this->assertDatabaseMissing('transactions', ['id' => $targetTransaction->id]);
    $this->assertDatabaseHas('transactions', ['id' => $otherTransaction->id]);
});

function dataWipeAdmin(): array
{
    $organization = Organization::create([
        'name' => 'Wipe Tenant',
        'slug' => fake()->unique()->slug(),
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => fake()->unique()->numerify('####'),
        'name' => 'Main Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $otherBranch = Branch::create([
        'organization_id' => $organization->id,
        'code' => fake()->unique()->numerify('####'),
        'name' => 'Other Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Organization Admin',
        'slug' => 'organization-admin',
    ]);
    $admin = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    return [$admin, $organization, $branch, $otherBranch];
}

function dataWipeClient(Organization $organization, Branch $branch): Client
{
    return Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->numerify('+2567########'),
        'status' => 'active',
    ]);
}

function dataWipeTransaction(Organization $organization, Branch $branch, Client $client, ?User $user = null): Transaction
{
    $accountType = AccountType::firstOrCreate(
        ['code' => 'SAVINGS'],
        ['name' => 'Savings', 'category' => 'savings', 'is_active' => true],
    );
    $account = Account::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'account_type_id' => $accountType->id,
        'account_number' => fake()->unique()->numerify('1001-SAV-######'),
        'account_name' => trim($client->first_name.' '.$client->last_name),
        'balance' => 1000,
        'status' => 'active',
        'opened_at' => now(),
    ]);
    $transactionType = TransactionType::firstOrCreate(
        ['code' => 'deposit'],
        ['name' => 'Deposit', 'direction' => 'credit', 'affects_cash_drawer' => true, 'is_active' => true],
    );
    $transaction = Transaction::create([
        'organization_id' => $organization->id,
        'account_id' => $account->id,
        'transaction_type_id' => $transactionType->id,
        'branch_id' => $branch->id,
        'user_id' => ($user ?? User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $branch->id]))->id,
        'reference' => fake()->unique()->bothify('TX-####'),
        'amount' => 1000,
        'balance_after' => 1000,
        'description' => 'Seed transaction',
        'posted_at' => now(),
    ]);
    $chartAccount = ChartAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'code' => fake()->unique()->bothify('CASH-??'),
        'name' => 'Cash Account',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_active' => true,
    ]);
    $journal = JournalEntry::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'transaction_id' => $transaction->id,
        'chart_account_id' => $chartAccount->id,
        'reference' => $transaction->reference,
        'account_name' => $chartAccount->name,
        'entry_type' => 'debit',
        'amount' => 1000,
    ]);
    JournalLine::create([
        'journal_entry_id' => $journal->id,
        'chart_account_id' => $chartAccount->id,
        'debit' => 1000,
        'credit' => 0,
    ]);

    return $transaction;
}
