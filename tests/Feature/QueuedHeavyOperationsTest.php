<?php

use App\Jobs\GenerateReportExport;
use App\Jobs\GenerateStatement;
use App\Jobs\ProcessMobileMoneyCallback;
use App\Jobs\SendSmsMessage;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Organization;
use App\Models\PaymentProvider;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SmsProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('queues SMS sending instead of sending inside the API request', function () {
    Queue::fake();
    $user = queueOpsUser(['manage-settings']);

    SmsProvider::create([
        'organization_id' => $user->organization_id,
        'name' => 'Test SMS',
        'code' => 'test-sms',
        'sender_id' => 'DIGITOFINA',
        'configuration' => ['mode' => 'log'],
        'is_default' => true,
        'status' => 'active',
    ]);

    Sanctum::actingAs($user, ['manage-settings']);

    $this->postJson('/api/v1/notifications/sms', [
        'recipient' => '256700000001',
        'message' => 'Your repayment is due tomorrow.',
        'type' => 'repayment_reminder',
    ])->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(SendSmsMessage::class);
});

it('queues report exports for background generation', function () {
    Queue::fake();
    $user = queueOpsUser(['export-reports']);

    Sanctum::actingAs($user, ['export-reports']);

    $this->postJson('/api/v1/reports/exports', [
        'report_type' => 'clients',
        'format' => 'csv',
        'filters' => ['status' => 'active'],
    ])->assertAccepted()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(GenerateReportExport::class);
});

it('queues savings statement generation', function () {
    Queue::fake();
    $user = queueOpsUser(['manage-accounts']);
    $account = queueOpsSavingsAccount($user);

    Sanctum::actingAs($user, ['manage-accounts']);

    $this->postJson("/api/v1/savings/accounts/{$account->id}/statement/generate", [
        'format' => 'pdf',
        'date_from' => today()->subMonth()->toDateString(),
        'date_to' => today()->toDateString(),
    ])->assertAccepted()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.report_type', 'savings_statement')
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(GenerateStatement::class);
});

it('queues mobile money callback processing', function () {
    Queue::fake();

    PaymentProvider::create([
        'name' => 'MTN Test',
        'code' => 'mtn-test',
        'type' => 'mobile_money',
        'status' => 'active',
    ]);

    $this->postJson('/api/v1/payments/mobile-money/callback', [
        'provider_code' => 'mtn-test',
        'status' => 'successful',
        'amount' => 25000,
        'phone' => '256700000000',
    ])->assertAccepted()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'received');

    Queue::assertPushed(ProcessMobileMoneyCallback::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function queueOpsUser(array $permissions): User
{
    $organization = Organization::create(['name' => 'Queue SACCO', 'slug' => fake()->unique()->slug()]);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => fake()->unique()->numerify('####'), 'name' => 'Queue Branch', 'is_active' => true, 'status' => 'active']);
    $role = Role::create(['organization_id' => $organization->id, 'name' => fake()->unique()->jobTitle(), 'slug' => fake()->unique()->slug()]);

    foreach ($permissions as $permission) {
        $permissionModel = Permission::firstOrCreate(['slug' => $permission], ['organization_id' => $organization->id, 'name' => str($permission)->headline()]);
        $role->permissions()->syncWithoutDetaching([$permissionModel->id]);
    }

    return User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'password' => Hash::make('password'),
        'status' => 'active',
        'is_active' => true,
    ]);
}

function queueOpsSavingsAccount(User $user): SavingsAccount
{
    $product = SavingsProduct::create([
        'organization_id' => $user->organization_id,
        'name' => 'Regular Savings',
        'code' => fake()->unique()->lexify('REG???'),
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
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => 'Grace',
        'last_name' => 'Amina',
        'phone' => '256700000002',
        'status' => 'active',
        'registered_at' => today(),
    ]);

    return SavingsAccount::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'client_id' => $client->id,
        'savings_product_id' => $product->id,
        'account_number' => fake()->unique()->numerify('1001-SAV-######'),
        'opening_date' => today(),
        'current_balance' => 100000,
        'available_balance' => 100000,
        'status' => 'active',
    ]);
}
