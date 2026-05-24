<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\GroupSavingsAccount;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsGoal;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\GroupSavingsService;
use App\Services\SavingsGoalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function savingsAdmin(): User
{
    $organization = Organization::create(['name' => 'Digitofina SACCO', 'slug' => fake()->unique()->slug(), 'status' => 'active']);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => '1001', 'name' => 'Luweero', 'is_active' => true, 'status' => 'active']);
    $permissions = collect(['manage-accounts', 'manage-clients', 'post-transactions'])->map(fn (string $slug) => Permission::create(['name' => str($slug)->headline(), 'slug' => $slug]));
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $role->permissions()->sync($permissions->pluck('id')->all());

    foreach ([
        ['1000', 'Cash on Hand', 'asset', 'debit'],
        ['2100', 'Client Savings Liability', 'liability', 'credit'],
        ['4200', 'Fees and Penalties Income', 'income', 'credit'],
    ] as [$code, $name, $type, $normalBalance]) {
        ChartAccount::create(['organization_id' => $organization->id, 'code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normalBalance, 'is_active' => true]);
    }

    return User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'role_id' => $role->id, 'status' => 'active', 'is_active' => true]);
}

function savingsClient(User $user, string $phone = '+256700000001'): Client
{
    return Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => $phone,
        'kyc_status' => 'verified',
        'status' => 'active',
    ]);
}

function savingsProduct(): SavingsProduct
{
    return SavingsProduct::create([
        'organization_id' => Organization::query()->value('id'),
        'name' => 'Regular Savings',
        'code' => fake()->unique()->bothify('SAV-??'),
        'minimum_opening_balance' => 10_000,
        'minimum_balance' => 5_000,
        'interest_rate' => 2,
        'interest_posting_frequency' => 'monthly',
        'withdrawal_charge' => 1_000,
        'deposit_charge' => 0,
        'monthly_maintenance_fee' => 0,
        'account_number_prefix' => 'SAV',
        'allows_overdraft' => false,
        'status' => 'active',
    ]);
}

it('opens a savings account with an opening deposit', function () {
    $user = savingsAdmin();
    $client = savingsClient($user);
    $product = savingsProduct();

    $response = $this->actingAs($user)->post(route('savings-accounts.store'), [
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'savings_product_id' => $product->id,
        'opening_date' => today()->toDateString(),
        'status' => 'active',
        'opening_deposit' => 50_000,
    ]);

    $account = SavingsAccount::first();

    $response->assertRedirect(route('savings-accounts.show', $account));
    expect($account->current_balance)->toBe('50000.00')
        ->and($account->transactions)->toHaveCount(1);
});

it('attaches multiple savings product packages to the same client intentionally', function () {
    $user = savingsAdmin();
    $client = savingsClient($user);
    $firstProduct = savingsProduct();
    $secondProduct = SavingsProduct::create([
        'organization_id' => $user->organization_id,
        'name' => 'Education Growth Savings',
        'code' => fake()->unique()->bothify('EDU-??'),
        'minimum_opening_balance' => 0,
        'minimum_balance' => 0,
        'interest_rate' => 8,
        'interest_posting_frequency' => 'quarterly',
        'withdrawal_charge' => 0,
        'deposit_charge' => 0,
        'monthly_maintenance_fee' => 0,
        'account_number_prefix' => 'EDU',
        'allows_overdraft' => false,
        'status' => 'active',
    ]);

    foreach ([$firstProduct, $secondProduct] as $product) {
        $this->actingAs($user)->post(route('savings-accounts.store'), [
            'client_id' => $client->id,
            'branch_id' => $user->branch_id,
            'savings_product_id' => $product->id,
            'opening_date' => today()->toDateString(),
            'status' => 'active',
            'opening_deposit' => 0,
        ])->assertRedirect();
    }

    $this->actingAs($user)
        ->get(route('clients.savings-accounts', $client))
        ->assertOk()
        ->assertSee('Attach Package')
        ->assertSee($client->client_number)
        ->assertSee('Regular Savings')
        ->assertSee('Education Growth Savings');

    expect($client->savingsAccounts()->count())->toBe(2);
});

it('prevents attaching the same savings product package twice to a client', function () {
    $user = savingsAdmin();
    $client = savingsClient($user);
    $product = savingsProduct();

    $payload = [
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'savings_product_id' => $product->id,
        'opening_date' => today()->toDateString(),
        'status' => 'active',
        'opening_deposit' => 0,
    ];

    $this->actingAs($user)->post(route('savings-accounts.store'), $payload)->assertRedirect();

    $response = $this->actingAs($user)
        ->post(route('savings-accounts.store'), $payload);

    $response
        ->assertSessionHasErrors('savings_product_id');

    expect($client->savingsAccounts()->count())->toBe(1);
});

it('prevents withdrawals below minimum balance', function () {
    $user = savingsAdmin();
    $product = savingsProduct();
    $account = SavingsAccount::create([
        'branch_id' => $user->branch_id,
        'client_id' => savingsClient($user)->id,
        'savings_product_id' => $product->id,
        'account_number' => '1001-SAV-0000001',
        'opening_date' => today(),
        'current_balance' => 20_000,
        'available_balance' => 20_000,
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->post(route('savings-transactions.withdrawal.store', $account), [
        'amount' => 15_000,
        'payment_method' => 'cash',
    ]);

    $response->assertSessionHasErrors('amount');
    expect($account->fresh()->current_balance)->toBe('20000.00');
});

it('transfers funds between savings accounts', function () {
    $user = savingsAdmin();
    $product = savingsProduct();
    $from = SavingsAccount::create(['branch_id' => $user->branch_id, 'client_id' => savingsClient($user, '+256700000002')->id, 'savings_product_id' => $product->id, 'account_number' => '1001-SAV-0000001', 'opening_date' => today(), 'current_balance' => 100_000, 'available_balance' => 100_000, 'status' => 'active']);
    $to = SavingsAccount::create(['branch_id' => $user->branch_id, 'client_id' => savingsClient($user, '+256700000003')->id, 'savings_product_id' => $product->id, 'account_number' => '1001-SAV-0000002', 'opening_date' => today(), 'current_balance' => 10_000, 'available_balance' => 10_000, 'status' => 'active']);

    $response = $this->actingAs($user)->post(route('savings-transfers.store'), [
        'from_savings_account_id' => $from->id,
        'to_savings_account_id' => $to->id,
        'amount' => 25_000,
        'narration' => 'Member transfer',
    ]);

    $response->assertRedirect();
    expect($from->fresh()->current_balance)->toBe('75000.00')
        ->and($to->fresh()->current_balance)->toBe('35000.00');
});

it('tracks savings goal progress after contributions', function () {
    $user = savingsAdmin();
    $product = savingsProduct();
    $account = SavingsAccount::create(['branch_id' => $user->branch_id, 'client_id' => savingsClient($user, '+256700000004')->id, 'savings_product_id' => $product->id, 'account_number' => '1001-SAV-0000004', 'savings_type' => 'target', 'opening_date' => today(), 'current_balance' => 0, 'available_balance' => 0, 'status' => 'active']);
    $goal = app(SavingsGoalService::class)->create([
        'name' => 'School fees',
        'client_id' => $account->client_id,
        'branch_id' => $user->branch_id,
        'savings_account_id' => $account->id,
        'target_amount' => 100_000,
        'target_date' => today()->addMonths(5)->toDateString(),
        'contribution_frequency' => 'monthly',
        'expected_contribution_amount' => 20_000,
    ], $user);

    app(SavingsGoalService::class)->contribute($goal, 25_000, $user, 'cash', 'Goal contribution');

    $goal = SavingsGoal::first();
    expect($goal->current_saved_amount)->toBe('25000.00')
        ->and($goal->percentage_achieved)->toBe(25.0)
        ->and($goal->remaining_amount)->toBe(75_000.0)
        ->and($account->fresh()->current_balance)->toBe('25000.00');
});

it('tracks group savings member contributions', function () {
    $user = savingsAdmin();
    $client = savingsClient($user, '+256700000005');
    $group = GroupSavingsAccount::create([
        'branch_id' => $user->branch_id,
        'name' => 'Market Vendors',
        'contribution_amount' => 10_000,
        'contribution_frequency' => 'weekly',
        'meeting_day' => 'friday',
        'status' => 'active',
    ]);

    $contribution = app(GroupSavingsService::class)->contribute($group, $client->id, 15_000, $user, 'cash', 'Weekly group savings');

    expect($contribution->amount)->toBe('15000.00')
        ->and($group->fresh()->group_savings_balance)->toBe('15000.00');
});
