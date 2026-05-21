<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientGroup;
use App\Models\FixedDepositAccount;
use App\Models\FixedDepositProduct;
use App\Models\Guarantor;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\LoanRepaymentAllocation;
use App\Models\LoanSchedule;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\ShareAccount;
use App\Models\ShareProduct;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('lets an admin permanently delete a client and connected records', function () {
    Storage::fake('local');

    $admin = deletionAdmin();
    $client = deletionClient($admin);
    $otherClient = deletionClient($admin, '1001-C000002', '+256700000002');
    $accountType = AccountType::create(['name' => 'Loan Account', 'code' => 'LON', 'category' => 'loan']);
    $transactionType = TransactionType::create(['name' => 'Loan Repayment', 'code' => 'loan_repayment', 'direction' => 'credit']);
    $account = Account::create([
        'client_id' => $client->id,
        'branch_id' => $admin->branch_id,
        'account_type_id' => $accountType->id,
        'account_number' => 'LN-1001-001',
        'account_name' => 'Client Loan Account',
        'status' => 'active',
        'balance' => 1000,
        'opened_at' => today(),
    ]);
    $transaction = Transaction::create([
        'account_id' => $account->id,
        'transaction_type_id' => $transactionType->id,
        'branch_id' => $admin->branch_id,
        'user_id' => $admin->id,
        'reference' => 'TX-DELETE-001',
        'amount' => 1000,
        'balance_after' => 0,
        'status' => 'posted',
        'posted_at' => now(),
    ]);
    $chartAccount = ChartAccount::create(['code' => '4100', 'name' => 'Interest Income', 'type' => 'income', 'normal_balance' => 'credit']);
    $journal = JournalEntry::create(['transaction_id' => $transaction->id, 'chart_account_id' => $chartAccount->id, 'reference' => 'JE-DELETE-001', 'account_name' => 'Interest Income', 'entry_type' => 'credit', 'amount' => 1000]);
    $journalLine = JournalLine::create(['journal_entry_id' => $journal->id, 'chart_account_id' => $chartAccount->id, 'debit' => 0, 'credit' => 1000]);

    $loanProduct = LoanProduct::create([
        'name' => 'Delete Test Product',
        'code' => 'DTP',
        'min_amount' => 100,
        'max_amount' => 5000,
        'min_term' => 1,
        'max_term' => 12,
        'annual_interest_rate' => 10,
        'term_unit' => 'months',
        'interest_method' => 'flat',
        'interest_frequency' => 'monthly',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 1,
        'is_active' => true,
    ]);
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $admin->branch_id,
        'loan_product_id' => $loanProduct->id,
        'loan_account_id' => $account->id,
        'officer_id' => $admin->id,
        'loan_number' => 'LN-DELETE-001',
        'requested_amount' => 1000,
        'approved_amount' => 1000,
        'principal_balance' => 500,
        'interest_balance' => 100,
        'interest_rate' => 10,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
    ]);
    $schedule = LoanSchedule::create(['loan_id' => $loan->id, 'installment_number' => 1, 'due_date' => today(), 'principal_due' => 1000, 'interest_due' => 100, 'total_due' => 1100, 'outstanding_amount' => 100]);
    $repayment = LoanRepayment::create(['loan_id' => $loan->id, 'client_id' => $client->id, 'branch_id' => $admin->branch_id, 'transaction_id' => $transaction->id, 'amount' => 1000, 'paid_at' => today()]);
    $allocation = LoanRepaymentAllocation::create(['loan_repayment_id' => $repayment->id, 'loan_schedule_id' => $schedule->id, 'principal_paid' => 900, 'interest_paid' => 100, 'total_paid' => 1000]);
    Guarantor::create(['loan_id' => $loan->id, 'guarantor_client_id' => $otherClient->id, 'type' => 'existing_client', 'name' => 'Other Client', 'guaranteed_amount' => 500]);
    Guarantor::create(['loan_id' => $loan->id, 'guarantor_client_id' => $client->id, 'type' => 'existing_client', 'name' => 'Deleted Client', 'guaranteed_amount' => 500]);

    $savingsProduct = SavingsProduct::create(['name' => 'Savings', 'code' => 'SAVP']);
    $savings = SavingsAccount::create(['branch_id' => $admin->branch_id, 'client_id' => $client->id, 'savings_product_id' => $savingsProduct->id, 'account_number' => 'SAV-DELETE-001', 'opening_date' => today(), 'status' => 'active']);
    $savingsTransaction = SavingsTransaction::create(['branch_id' => $admin->branch_id, 'client_id' => $client->id, 'savings_account_id' => $savings->id, 'reference' => 'SAV-TX-001', 'type' => 'deposit', 'amount' => 100, 'balance_before' => 0, 'balance_after' => 100, 'transaction_date' => today(), 'value_date' => today()]);

    $shareProduct = ShareProduct::create(['name' => 'Shares', 'code' => 'SHP', 'share_value' => 1000]);
    $share = ShareAccount::create(['branch_id' => $admin->branch_id, 'client_id' => $client->id, 'share_product_id' => $shareProduct->id, 'account_number' => 'SH-DELETE-001', 'share_value' => 1000]);
    DB::table('share_transactions')->insert(['share_account_id' => $share->id, 'reference' => 'SH-TX-001', 'type' => 'purchase', 'shares' => 1, 'amount' => 1000, 'status' => 'successful', 'created_at' => now(), 'updated_at' => now()]);
    $shareTransactionId = DB::table('share_transactions')->where('reference', 'SH-TX-001')->value('id');

    $fixedProduct = FixedDepositProduct::create(['name' => 'Fixed', 'code' => 'FIX', 'minimum_amount' => 1000, 'interest_rate' => 10, 'term_days' => 30]);
    $fixed = FixedDepositAccount::create(['branch_id' => $admin->branch_id, 'client_id' => $client->id, 'fixed_deposit_product_id' => $fixedProduct->id, 'account_number' => 'FD-DELETE-001', 'amount' => 1000, 'start_date' => today(), 'maturity_date' => today()->addDays(30)]);
    DB::table('fixed_deposit_transactions')->insert(['fixed_deposit_account_id' => $fixed->id, 'reference' => 'FD-TX-001', 'type' => 'funding', 'amount' => 1000, 'status' => 'successful', 'created_at' => now(), 'updated_at' => now()]);
    $fixedTransactionId = DB::table('fixed_deposit_transactions')->where('reference', 'FD-TX-001')->value('id');

    $group = ClientGroup::create(['branch_id' => $admin->branch_id, 'chairperson_client_id' => $client->id, 'group_number' => 'GRP-DELETE-001', 'name' => 'Delete Group']);
    DB::table('client_group_members')->insert(['client_group_id' => $group->id, 'client_id' => $client->id, 'role' => 'member', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $groupMemberId = DB::table('client_group_members')->where('client_id', $client->id)->value('id');
    Storage::disk('local')->put('client-documents/delete-me.txt', 'delete');
    $document = ClientDocument::create(['client_id' => $client->id, 'type' => 'national_id_front', 'title' => 'ID', 'disk' => 'local', 'path' => 'client-documents/delete-me.txt']);
    DB::table('client_notes')->insert(['client_id' => $client->id, 'note' => 'delete me', 'created_at' => now(), 'updated_at' => now()]);
    $noteId = DB::table('client_notes')->where('client_id', $client->id)->value('id');

    $response = $this->actingAs($admin)->delete(route('clients.destroy', $client), [
        'confirmation' => $client->client_number,
    ]);

    $response->assertRedirect(route('clients.index'));

    foreach ([
        'clients' => $client->id,
        'accounts' => $account->id,
        'transactions' => $transaction->id,
        'journal_entries' => $journal->id,
        'journal_lines' => $journalLine->id,
        'loans' => $loan->id,
        'loan_schedules' => $schedule->id,
        'loan_repayments' => $repayment->id,
        'loan_repayment_allocations' => $allocation->id,
        'savings_accounts' => $savings->id,
        'savings_transactions' => $savingsTransaction->id,
        'share_accounts' => $share->id,
        'share_transactions' => $shareTransactionId,
        'fixed_deposit_accounts' => $fixed->id,
        'fixed_deposit_transactions' => $fixedTransactionId,
        'client_group_members' => $groupMemberId,
        'client_documents' => $document->id,
        'client_notes' => $noteId,
    ] as $table => $id) {
        expect(DB::table($table)->where('id', $id)->exists())->toBeFalse($table.' still has the deleted client record.');
    }

    expect(DB::table('guarantors')->where('loan_id', $loan->id)->exists())->toBeFalse()
        ->and(DB::table('guarantors')->where('guarantor_client_id', $client->id)->exists())->toBeFalse()
        ->and(DB::table('client_groups')->whereKey($group->id)->value('chairperson_client_id'))->toBeNull()
        ->and(Client::whereKey($otherClient->id)->exists())->toBeTrue();
    Storage::disk('local')->assertMissing('client-documents/delete-me.txt');
});

it('does not let a non-admin delete a client', function () {
    $user = deletionUser(['manage-clients']);
    $client = deletionClient($user);

    $response = $this->actingAs($user)->delete(route('clients.destroy', $client), [
        'confirmation' => $client->client_number,
    ]);

    expect($response->getStatusCode())->toBeIn([302, 403]);
    expect(Client::whereKey($client->id)->exists())->toBeTrue();
});

it('lets an organization admin delete a client', function () {
    $admin = deletionOrganizationAdmin();
    $client = deletionClient($admin);

    $response = $this->actingAs($admin)->delete(route('clients.destroy', $client), [
        'confirmation' => $client->client_number,
    ]);

    $response->assertRedirect();
    expect(Client::whereKey($client->id)->exists())->toBeFalse();
});

function deletionUser(array $permissions, string $roleSlug = 'loan-officer'): User
{
    $branch = Branch::create(['code' => fake()->unique()->numerify('####'), 'name' => fake()->city(), 'is_active' => true, 'status' => 'active']);
    $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => str($roleSlug)->headline()]);
    $role->permissions()->sync(collect($permissions)->map(
        fn (string $slug): int => Permission::firstOrCreate(['slug' => $slug], ['name' => str($slug)->headline()])->id
    )->all());

    return User::factory()->create([
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
}

function deletionAdmin(): User
{
    return deletionUser(['manage-organization', 'manage-clients'], 'super-admin');
}

function deletionOrganizationAdmin(): User
{
    $organization = Organization::create([
        'name' => 'Deletion Tenant',
        'slug' => fake()->unique()->slug(),
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => fake()->unique()->numerify('####'),
        'name' => fake()->city(),
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::firstOrCreate(
        ['slug' => 'organization-admin'],
        ['organization_id' => $organization->id, 'name' => 'Organization Admin'],
    );
    $role->permissions()->sync(collect(['manage-clients'])->map(
        fn (string $slug): int => Permission::firstOrCreate(['slug' => $slug], ['organization_id' => $organization->id, 'name' => str($slug)->headline()])->id
    )->all());

    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($organization);

    return User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
}

function deletionClient(User $user, string $number = '1001-C000001', string $phone = '+256700000001'): Client
{
    return Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id ?: Branch::where('organization_id', $user->organization_id)->value('id'),
        'officer_id' => $user->id,
        'client_number' => $number,
        'client_type' => 'individual',
        'first_name' => 'Delete',
        'last_name' => 'Client',
        'phone' => $phone,
        'kyc_status' => 'verified',
        'status' => 'active',
    ]);
}
