<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows members their savings investment growth and posting cycle', function () {
    $branch = Branch::create(['code' => '1001', 'name' => 'Luweero', 'is_active' => true, 'status' => 'active']);
    $permission = Permission::create(['name' => 'View Own Accounts', 'slug' => 'view-own-accounts']);
    $role = Role::create(['name' => 'Member', 'slug' => 'member']);
    $role->permissions()->sync([$permission->id]);
    $user = User::factory()->create(['branch_id' => $branch->id, 'role_id' => $role->id, 'status' => 'active', 'is_active' => true]);
    $client = Client::create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'client_number' => '1001-C000001',
        'client_type' => 'individual',
        'first_name' => 'Amina',
        'last_name' => 'Nakato',
        'phone' => '+256701111222',
        'kyc_status' => 'verified',
        'status' => 'active',
    ]);
    $product = SavingsProduct::create([
        'name' => 'Quarterly Growth Investment',
        'code' => 'QGI',
        'minimum_opening_balance' => 0,
        'minimum_balance' => 0,
        'interest_rate' => 12,
        'interest_posting_frequency' => 'quarterly',
        'status' => 'active',
    ]);
    $account = SavingsAccount::create([
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'savings_product_id' => $product->id,
        'account_number' => '1001-INV-000001',
        'opening_date' => today(),
        'current_balance' => 515_000,
        'available_balance' => 515_000,
        'status' => 'active',
    ]);
    SavingsTransaction::create([
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'savings_account_id' => $account->id,
        'reference' => 'INT-TEST-001',
        'type' => 'interest_posting',
        'amount' => 15_000,
        'charges' => 0,
        'balance_before' => 500_000,
        'balance_after' => 515_000,
        'payment_method' => 'system',
        'transaction_date' => today(),
        'value_date' => today(),
        'status' => 'successful',
    ]);

    $this->actingAs($user)
        ->get(route('member.dashboard'))
        ->assertOk()
        ->assertSee('My Savings & Investment Growth', false)
        ->assertSee('Quarterly Growth Investment')
        ->assertSee('Quarterly')
        ->assertSee('12.00%')
        ->assertSee('15,000.00 UGX')
        ->assertSee('515,000.00 UGX');
});
