<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\InvestmentAccount;
use App\Models\InvestmentPlan;
use App\Models\InvestmentTransaction;
use App\Models\InvestorAccount;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('lists concrete investment reports when investments are enabled', function () {
    $user = investmentReportUser();

    $this->actingAs($user)
        ->get(route('reports.index'))
        ->assertSuccessful()
        ->assertSee('Investments')
        ->assertSee('Active Investments')
        ->assertSee('Matured Investments')
        ->assertSee('Investment Contributions')
        ->assertSee('Investment Withdrawals')
        ->assertSee('Investment Returns')
        ->assertSee('Investment Plan Performance')
        ->assertSee('Investment Branch Performance')
        ->assertSee('Investment Officer Performance');
});

it('generates investment account, transaction, and performance reports', function () {
    $user = investmentReportUser();
    [$activeInvestment] = seedInvestmentReportData($user);

    $this->actingAs($user)
        ->get(route('reports.show', 'active-investments'))
        ->assertSuccessful()
        ->assertSee($activeInvestment->investment_number)
        ->assertSee('Growth Plan')
        ->assertSee('UGX 125,000.00');

    $this->actingAs($user)
        ->get(route('reports.show', 'investment-contributions'))
        ->assertSuccessful()
        ->assertSee('INV-CONTRIB-001')
        ->assertSee('Top Up')
        ->assertSee('UGX 25,000.00');

    $this->actingAs($user)
        ->get(route('reports.show', 'investment-plan-performance'))
        ->assertSuccessful()
        ->assertSee('Growth Plan')
        ->assertSee('UGX 125,000.00');

    $this->actingAs($user)
        ->get(route('reports.show', 'investment-branch-performance'))
        ->assertSuccessful()
        ->assertSee('Main Branch')
        ->assertSee('UGX 125,000.00');

    $this->actingAs($user)
        ->get(route('reports.show', 'investment-officer-performance'))
        ->assertSuccessful()
        ->assertSee($user->name)
        ->assertSee('UGX 125,000.00');
});

it('wires the retained dashboard investment card to the investment report', function () {
    $user = investmentReportUser();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Investment balance')
        ->assertSee(route('reports.show', 'investment-plan-performance'), false)
        ->assertDontSee(route('reports.show', 'investment-officer-performance'), false)
        ->assertDontSee(route('reports.show', 'active-investments'), false)
        ->assertDontSee(route('reports.show', 'investment-returns'), false)
        ->assertDontSee('investment-maturities', false)
        ->assertDontSee('investment-withdrawals', false)
        ->assertDontSee('Active investments')
        ->assertDontSee('Returns posted')
        ->assertDontSee('Maturing this month')
        ->assertDontSee('Investment withdrawals');
});

function investmentReportUser(): User
{
    $organization = Organization::create([
        'name' => 'Investment Report Tenant',
        'slug' => fake()->unique()->slug(),
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => fake()->unique()->numerify('####'),
        'name' => 'Main Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Investment Reporter',
        'slug' => fake()->unique()->slug(),
    ]);
    $permission = Permission::firstOrCreate(
        ['slug' => 'view-reports'],
        ['organization_id' => $organization->id, 'name' => 'View Reports']
    );
    $role->permissions()->syncWithoutDetaching([$permission->id]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'password' => Hash::make('password'),
        'status' => 'active',
        'is_active' => true,
    ]);

    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($organization);
    $modules->enable($organization, Module::where('slug', 'investments')->firstOrFail(), $user);

    return $user;
}

function seedInvestmentReportData(User $user): array
{
    $client = Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => 'Investing',
        'last_name' => 'Member',
        'phone' => fake()->unique()->numerify('+2567########'),
        'status' => 'active',
    ]);
    $plan = InvestmentPlan::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'created_by' => $user->id,
        'name' => 'Growth Plan',
        'code' => fake()->unique()->bothify('INV-??'),
        'type' => 'fixed',
        'minimum_amount' => 10000,
        'maximum_amount' => 1000000,
        'min_amount' => 10000,
        'max_amount' => 1000000,
        'duration' => 12,
        'duration_unit' => 'months',
        'expected_return_rate' => 12,
        'return_calculation_method' => 'fixed_percentage',
        'return_method' => 'fixed_percentage',
        'return_frequency' => 'at_maturity',
        'risk_level' => 'low',
        'status' => 'active',
    ]);
    $investor = InvestorAccount::create([
        'organization_id' => $user->organization_id,
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'assigned_officer_id' => $user->id,
        'created_by' => $user->id,
        'account_number' => fake()->unique()->numerify('1001-IA-######'),
        'total_invested' => 125000,
        'total_returns_earned' => 10000,
        'total_withdrawn' => 5000,
        'current_balance' => 125000,
        'current_investment_balance' => 125000,
        'status' => 'active',
    ]);
    $activeInvestment = InvestmentAccount::create([
        'organization_id' => $user->organization_id,
        'investor_account_id' => $investor->id,
        'client_id' => $client->id,
        'investment_plan_id' => $plan->id,
        'branch_id' => $user->branch_id,
        'created_by' => $user->id,
        'investment_number' => 'INV-ACT-001',
        'principal_amount' => 100000,
        'start_date' => today()->subMonth(),
        'maturity_date' => today()->addMonths(2),
        'expected_return_amount' => 12000,
        'actual_return_amount' => 10000,
        'current_balance' => 125000,
        'return_rate' => 12,
        'return_method' => 'fixed_percentage',
        'status' => 'active',
    ]);
    InvestmentTransaction::create([
        'organization_id' => $user->organization_id,
        'investor_account_id' => $investor->id,
        'investment_account_id' => $activeInvestment->id,
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'created_by' => $user->id,
        'reference' => 'INV-CONTRIB-001',
        'type' => 'top_up',
        'amount' => 25000,
        'balance_before' => 100000,
        'balance_after' => 125000,
        'payment_method' => 'cash',
        'transaction_date' => today(),
        'status' => 'successful',
    ]);

    return [$activeInvestment, $plan, $investor];
}
