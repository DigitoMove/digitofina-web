<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\MakerCheckerRequest;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleFinancialLimit;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('requires another checker to approve a large withdrawal before it posts', function () {
    [$maker, $checker, $account] = makerCheckerSavingsFixture();
    $makerToken = makerCheckerTokenFor($maker, ['post-transactions', 'approve-maker-checker', 'approve-transactions']);
    $checkerToken = makerCheckerTokenFor($checker, ['approve-maker-checker', 'approve-transactions', 'post-transactions']);

    $this->withHeaders(['Authorization' => 'Bearer '.$makerToken])
        ->withHeader('Idempotency-Key', 'large-withdrawal-001')
        ->postJson("/api/v1/savings/accounts/{$account->id}/withdraw", [
            'amount' => 60000,
            'payment_method' => 'cash',
            'narration' => 'Large cash withdrawal',
        ])
        ->assertAccepted()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.action', 'savings_withdrawal')
        ->assertJsonPath('data.status', 'pending');

    $approval = MakerCheckerRequest::firstOrFail();

    expect((float) $account->fresh()->current_balance)->toBe(100000.0)
        ->and(SavingsTransaction::count())->toBe(0);

    $this->withHeaders(['Authorization' => 'Bearer '.$makerToken])
        ->postJson("/api/v1/financial-controls/approvals/{$approval->id}/approve", [
            'comments' => 'Trying to self approve',
        ])
        ->assertUnprocessable();

    $this->flushHeaders();
    Auth::forgetGuards();

    $this->withHeaders(['Authorization' => 'Bearer '.$checkerToken])
        ->postJson("/api/v1/financial-controls/approvals/{$approval->id}/approve", [
            'comments' => 'Cash checked and approved',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'approved');

    expect((float) $account->fresh()->current_balance)->toBe(40000.0)
        ->and(SavingsTransaction::count())->toBe(1)
        ->and(SavingsTransaction::first()->type)->toBe('withdrawal');
});

/**
 * @return array{0: User, 1: User, 2: SavingsAccount}
 */
function makerCheckerSavingsFixture(): array
{
    $organization = Organization::create(['name' => 'Maker Checker SACCO', 'slug' => fake()->unique()->slug()]);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => '1001', 'name' => 'Main', 'is_active' => true, 'status' => 'active']);

    foreach ([
        ['1000', 'Cash on Hand', 'asset', 'debit'],
        ['2100', 'Client Savings Liability', 'liability', 'credit'],
        ['4200', 'Fees and Penalties Income', 'income', 'credit'],
    ] as [$code, $name, $type, $normalBalance]) {
        ChartAccount::create(['organization_id' => $organization->id, 'code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normalBalance, 'is_active' => true]);
    }

    $makerRole = Role::create(['organization_id' => $organization->id, 'name' => 'Cashier', 'slug' => fake()->unique()->slug()]);
    $checkerRole = Role::create(['organization_id' => $organization->id, 'name' => 'Branch Manager', 'slug' => fake()->unique()->slug()]);

    foreach (['post-transactions', 'approve-maker-checker', 'approve-transactions'] as $permission) {
        Permission::firstOrCreate(['slug' => $permission], ['organization_id' => $organization->id, 'name' => str($permission)->headline()]);
    }

    $makerRole->permissions()->sync(Permission::whereIn('slug', ['post-transactions', 'approve-maker-checker', 'approve-transactions'])->pluck('id')->all());
    $checkerRole->permissions()->sync(Permission::whereIn('slug', ['post-transactions', 'approve-maker-checker', 'approve-transactions'])->pluck('id')->all());

    RoleFinancialLimit::create([
        'organization_id' => $organization->id,
        'role_id' => $makerRole->id,
        'maker_checker_threshold' => 50000,
        'single_transaction_limit' => 100000,
        'daily_transaction_limit' => 200000,
        'is_active' => true,
    ]);

    $maker = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $makerRole->id,
        'password' => Hash::make('password'),
        'status' => 'active',
        'is_active' => true,
    ]);
    $checker = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $checkerRole->id,
        'password' => Hash::make('password'),
        'status' => 'active',
        'is_active' => true,
    ]);
    $product = SavingsProduct::create([
        'organization_id' => $organization->id,
        'name' => 'Regular Savings',
        'code' => 'REG',
        'minimum_opening_balance' => 0,
        'minimum_balance' => 0,
        'interest_rate' => 0,
        'interest_posting_frequency' => 'monthly',
        'withdrawal_charge' => 0,
        'deposit_charge' => 0,
        'monthly_maintenance_fee' => 0,
        'account_number_prefix' => 'SAV',
        'allows_overdraft' => false,
        'status' => 'active',
    ]);
    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => '1001-C000001',
        'client_type' => 'individual',
        'first_name' => 'Amina',
        'last_name' => 'Nakato',
        'phone' => '+256700000001',
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $account = SavingsAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'savings_product_id' => $product->id,
        'account_number' => '1001-SAV-000001',
        'opening_date' => today(),
        'current_balance' => 100000,
        'available_balance' => 100000,
        'status' => 'active',
    ]);

    return [$maker, $checker, $account];
}

/**
 * @param  array<int, string>  $permissions
 */
function makerCheckerTokenFor(User $user, array $permissions): string
{
    return $user->createToken('Pest', $permissions)->plainTextToken;
}
