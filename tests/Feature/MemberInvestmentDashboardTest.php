<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\InvestmentAccount;
use App\Models\InvestmentPlan;
use App\Models\InvestorAccount;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
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
    $organization = Organization::create(['name' => 'Member SACCO', 'slug' => fake()->unique()->slug(), 'status' => 'active']);
    $branch->update(['organization_id' => $organization->id]);
    $module = Module::create(['name' => 'Client Management', 'slug' => 'clients', 'status' => 'active']);
    OrganizationModule::create(['organization_id' => $organization->id, 'module_id' => $module->id, 'is_enabled' => true, 'enabled_at' => now()]);
    $permission = Permission::create(['name' => 'View Own Accounts', 'slug' => 'view-own-accounts']);
    $role = Role::create(['organization_id' => $organization->id, 'name' => 'Member', 'slug' => 'member']);
    $role->permissions()->sync([$permission->id]);
    $user = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'role_id' => $role->id, 'status' => 'active', 'is_active' => true]);
    $client = Client::create([
        'organization_id' => $organization->id,
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
        'organization_id' => $organization->id,
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
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'savings_product_id' => $product->id,
        'account_number' => '1001-INV-000001',
        'opening_date' => today(),
        'current_balance' => 515_000,
        'available_balance' => 515_000,
        'status' => 'active',
    ]);
    SavingsTransaction::create([
        'organization_id' => $organization->id,
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
    $plan = InvestmentPlan::create([
        'organization_id' => $organization->id,
        'name' => 'Goat Project',
        'code' => 'GOAT',
        'type' => 'asset_backed',
        'unit_label' => 'goat',
        'unit_value' => 500_000,
        'minimum_units' => 1,
        'minimum_amount' => 500_000,
        'monthly_maintenance_fee' => 25_000,
        'duration' => 36,
        'duration_unit' => 'months',
        'min_holding_months' => 36,
        'max_holding_months' => 60,
        'expected_return_rate' => 30,
        'return_calculation_method' => 'fixed_percentage',
        'return_frequency' => 'at_maturity',
        'risk_level' => 'medium',
        'status' => 'active',
    ]);
    $investor = InvestorAccount::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'account_number' => '1001-INVESTOR-000001',
        'status' => 'active',
    ]);
    InvestmentAccount::create([
        'organization_id' => $organization->id,
        'investor_account_id' => $investor->id,
        'client_id' => $client->id,
        'investment_plan_id' => $plan->id,
        'branch_id' => $branch->id,
        'investment_number' => '1001-GOAT-000001',
        'principal_amount' => 500_000,
        'unit_quantity' => 1,
        'unit_value' => 500_000,
        'unit_label' => 'goat',
        'start_date' => today()->subMonthsNoOverflow(9),
        'maturity_date' => today()->addMonthsNoOverflow(27),
        'target_duration_months' => 36,
        'expected_return_amount' => 150_000,
        'current_balance' => 500_000,
        'return_rate' => 30,
        'monthly_maintenance_fee' => 25_000,
        'return_method' => 'fixed_percentage',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->get(route('member.dashboard'))
        ->assertOk()
        ->assertSee('My Savings & Investment Growth', false)
        ->assertSee('Quarterly Growth Investment')
        ->assertSee('Quarterly')
        ->assertSee('12.00%')
        ->assertSee('15,000.00 UGX')
        ->assertSee('515,000.00 UGX')
        ->assertSee('Goat Project')
        ->assertSee('25,000.00')
        ->assertSee('30.00%');
});
