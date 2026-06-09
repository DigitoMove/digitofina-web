<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\TransactionPostingService;
use App\Support\TransactionTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('combines a backdated business date with the current recording time', function () {
    $recordedAt = CarbonImmutable::parse('2026-06-09 14:27:35.123456');
    $businessDate = CarbonImmutable::parse('2026-06-03');

    expect(TransactionTimestamp::forBusinessDate($businessDate, $recordedAt)->format('Y-m-d H:i:s.u'))
        ->toBe('2026-06-03 14:27:35.123456');
});

it('keeps the exact recording timestamp separate from a backdated posted date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-09 14:27:35'));

    [$user, $account, $type] = transactionTimestampContext();
    $this->actingAs($user);

    $transaction = app(TransactionPostingService::class)->postNonCashCredit(
        $account,
        $type,
        1000,
        $user,
        'Backdated credit',
        postedAt: CarbonImmutable::parse('2026-06-03'),
    )->refresh();

    expect($transaction->posted_at->format('Y-m-d H:i:s'))->toBe('2026-06-03 14:27:35')
        ->and($transaction->created_at->format('Y-m-d H:i:s'))->toBe('2026-06-09 14:27:35');
});

/**
 * @return array{0: User, 1: Account, 2: TransactionType}
 */
function transactionTimestampContext(): array
{
    $organization = Organization::create([
        'name' => 'Timestamp Organization',
        'slug' => fake()->unique()->slug(),
        'status' => 'active',
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'name' => 'Timestamp Branch',
        'code' => fake()->unique()->bothify('TS-###'),
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Timestamp Staff',
        'slug' => fake()->unique()->slug(),
    ]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => fake()->unique()->bothify('TS-C-####'),
        'first_name' => 'Timestamp',
        'last_name' => 'Client',
        'phone' => fake()->unique()->numerify('+2567########'),
        'status' => 'active',
    ]);
    $accountType = AccountType::create([
        'name' => 'Timestamp Savings',
        'code' => fake()->unique()->bothify('TS-AT-###'),
        'category' => 'savings',
        'minimum_balance' => 0,
        'is_active' => true,
    ]);
    $account = Account::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'account_type_id' => $accountType->id,
        'account_number' => fake()->unique()->bothify('TS-A-####'),
        'account_name' => 'Timestamp Account',
        'status' => 'active',
        'balance' => 0,
        'opened_at' => today(),
    ]);
    $type = TransactionType::create([
        'name' => 'Timestamp Credit',
        'code' => fake()->unique()->bothify('timestamp-credit-###'),
        'direction' => 'credit',
        'affects_cash_drawer' => false,
        'is_active' => true,
    ]);

    return [$user, $account, $type];
}
