<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\IdempotencyKey;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('replays deposits submitted with the same idempotency key without duplicating money movement', function () {
    [$user, $account] = idempotencySavingsFixture();
    $token = idempotencyTokenFor($user);

    $first = $this->withToken($token)
        ->withHeader('Idempotency-Key', 'deposit-0001')
        ->postJson("/api/v1/savings/accounts/{$account->id}/deposit", [
            'amount' => 25000,
            'payment_method' => 'cash',
            'narration' => 'Counter deposit',
        ])
        ->assertCreated()
        ->assertHeader('Idempotency-Key', 'deposit-0001')
        ->assertHeader('Idempotency-Replayed', 'false')
        ->assertJsonPath('success', true)
        ->json();

    $second = $this->withToken($token)
        ->withHeader('Idempotency-Key', 'deposit-0001')
        ->postJson("/api/v1/savings/accounts/{$account->id}/deposit", [
            'amount' => 25000,
            'payment_method' => 'cash',
            'narration' => 'Counter deposit',
        ])
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true')
        ->json();

    expect($second)->toBe($first)
        ->and(SavingsTransaction::count())->toBe(1)
        ->and((float) $account->refresh()->current_balance)->toBe(25000.0)
        ->and(IdempotencyKey::where('key', 'deposit-0001')->first())
        ->status->toBe('completed');
});

it('rejects reused idempotency keys when the request payload changes', function () {
    [$user, $account] = idempotencySavingsFixture();
    $token = idempotencyTokenFor($user);

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'deposit-0002')
        ->postJson("/api/v1/savings/accounts/{$account->id}/deposit", [
            'amount' => 10000,
            'payment_method' => 'cash',
        ])
        ->assertCreated();

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'deposit-0002')
        ->postJson("/api/v1/savings/accounts/{$account->id}/deposit", [
            'amount' => 20000,
            'payment_method' => 'cash',
        ])
        ->assertConflict()
        ->assertJsonPath('success', false);

    expect(SavingsTransaction::count())->toBe(1)
        ->and((float) $account->refresh()->current_balance)->toBe(10000.0);
});

it('blocks deposits when the company savings module is disabled without moving money', function () {
    [$user, $account] = idempotencySavingsFixture(false);

    $this->withToken(idempotencyTokenFor($user))
        ->withHeader('Idempotency-Key', 'disabled-savings')
        ->postJson("/api/v1/savings/accounts/{$account->id}/deposit", ['amount' => 10000, 'payment_method' => 'cash'])
        ->assertForbidden()
        ->assertJsonPath('errors.module', 'savings');

    expect(SavingsTransaction::count())->toBe(0)
        ->and((float) $account->fresh()->current_balance)->toBe(0.0);
});

/**
 * @return array{0: User, 1: SavingsAccount}
 */
function idempotencySavingsFixture(bool $enableSavings = true): array
{
    $organization = Organization::create(['name' => 'Idempotency SACCO', 'slug' => fake()->unique()->slug()]);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => fake()->unique()->numerify('####'), 'name' => 'Main', 'is_active' => true, 'status' => 'active']);
    ChartAccount::create(['organization_id' => $organization->id, 'code' => '1000', 'name' => 'Cash on Hand', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_account' => true, 'is_active' => true]);
    ChartAccount::create(['organization_id' => $organization->id, 'code' => '2100', 'name' => 'Client Savings Liability', 'type' => 'liability', 'normal_balance' => 'credit', 'is_cash_account' => false, 'is_active' => true]);
    $permission = Permission::firstOrCreate(['slug' => 'post-transactions'], ['organization_id' => $organization->id, 'name' => 'Post Transactions']);
    $role = Role::create(['organization_id' => $organization->id, 'name' => 'Cashier', 'slug' => fake()->unique()->slug()]);
    $role->permissions()->sync([$permission->id]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'password' => Hash::make('password'),
        'status' => 'active',
        'is_active' => true,
    ]);
    $modules = app(ModuleService::class);
    $modules->syncDefaults($organization);
    if ($enableSavings) {
        $modules->enable($organization, Module::where('slug', 'savings')->firstOrFail(), $user);
    }
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
        'current_balance' => 0,
        'available_balance' => 0,
        'status' => 'active',
    ]);

    return [$user, $account];
}

function idempotencyTokenFor(User $user): string
{
    return $user->createToken('Pest', ['post-transactions'])->plainTextToken;
}
