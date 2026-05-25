<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\InvestmentAccount;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\InvestmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates share-style investment packages and lets a client invest for a related child', function () {
    [$user, $branch] = investmentShareUser();
    $parent = investmentShareClient($user, 'Parent', '+256701100001');
    $child = investmentShareClient($user, 'Child', '+256701100002');

    $planId = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/investment-plans', [
            'name' => 'Goat Ownership Package',
            'code' => 'GOAT',
            'type' => 'share_package',
            'unit_label' => 'goat',
            'unit_value' => 450_000,
            'minimum_units' => 1,
            'maximum_units' => 5,
            'is_share_package' => true,
            'minimum_amount' => 450_000,
            'maximum_amount' => 2_250_000,
            'monthly_maintenance_fee' => 25_000,
            'duration' => 36,
            'duration_unit' => 'months',
            'min_holding_months' => 36,
            'max_holding_months' => 60,
            'expected_return_rate' => 15,
            'return_calculation_method' => 'profit_share',
            'return_frequency' => 'annually',
            'allow_top_ups' => true,
            'allow_early_withdrawal' => false,
            'default_required_information' => [
                ['key' => 'tag_number', 'label' => 'Goat tag number', 'type' => 'text', 'required' => true],
                ['key' => 'breed', 'label' => 'Breed', 'type' => 'text', 'required' => true],
                ['key' => 'cross_breeding_notes', 'label' => 'Cross-breeding notes', 'type' => 'textarea', 'required' => false],
            ],
            'risk_level' => 'medium',
            'status' => 'active',
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_share_package', true)
        ->assertJsonPath('data.unit_label', 'goat')
        ->assertJsonPath('data.unit_value', 450000)
        ->assertJsonPath('data.maximum_units', 5)
        ->assertJsonPath('data.monthly_maintenance_fee', 25000)
        ->assertJsonPath('data.default_required_information.0.key', 'tag_number')
        ->json('data.id');

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/clients/{$parent->id}/relationships", [
            'related_client_id' => $child->id,
            'relationship_type' => 'child',
        ])
        ->assertCreated()
        ->assertJsonPath('data.relationship_type', 'child');

    $investorId = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/investor-accounts', [
            'client_id' => $child->id,
            'branch_id' => $branch->id,
        ])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/investment-accounts', [
            'investor_account_id' => $investorId,
            'investment_plan_id' => $planId,
            'sponsor_client_id' => $parent->id,
            'unit_quantity' => 3,
            'target_duration_months' => 48,
            'client_investment_details' => [
                'tag_number' => 'GT-001',
                'breed' => 'Boer cross',
                'cross_breeding_notes' => 'Existing local goat crossed with Boer buck.',
            ],
            'payment_method' => 'cash',
        ])
        ->assertCreated()
        ->assertJsonPath('data.client.id', $child->id)
        ->assertJsonPath('data.sponsor_client.id', $parent->id)
        ->assertJsonPath('data.unit_quantity', 3)
        ->assertJsonPath('data.unit_label', 'goat')
        ->assertJsonPath('data.principal_amount', 1350000)
        ->assertJsonPath('data.current_balance', 1350000)
        ->assertJsonPath('data.target_duration_months', 48)
        ->assertJsonPath('data.monthly_maintenance_fee', 25000)
        ->assertJsonPath('data.client_investment_details.tag_number', 'GT-001');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/investment-accounts', [
            'investor_account_id' => $investorId,
            'investment_plan_id' => $planId,
            'sponsor_client_id' => $parent->id,
            'unit_quantity' => 6,
            'payment_method' => 'cash',
        ])
        ->assertUnprocessable();
});

it('prorates premature withdrawal ROI against the minimum holding period', function () {
    [$user, $branch] = investmentShareUser();
    $client = investmentShareClient($user, 'Early', '+256701100003');

    $plan = app(InvestmentService::class)->createPlan([
        'name' => 'Three Year Goat Project',
        'code' => 'GOAT-36',
        'type' => 'asset_backed',
        'unit_label' => 'goat',
        'unit_value' => 1_000_000,
        'minimum_units' => 1,
        'minimum_amount' => 1_000_000,
        'duration' => 36,
        'duration_unit' => 'months',
        'min_holding_months' => 36,
        'max_holding_months' => 60,
        'expected_return_rate' => 36,
        'return_calculation_method' => 'fixed_percentage',
        'return_frequency' => 'at_maturity',
        'allow_early_withdrawal' => true,
        'risk_level' => 'medium',
    ], $user);

    $investorId = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/investor-accounts', [
            'client_id' => $client->id,
            'branch_id' => $branch->id,
        ])
        ->assertCreated()
        ->json('data.id');

    $investmentId = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/investment-accounts', [
            'investor_account_id' => $investorId,
            'investment_plan_id' => $plan->id,
            'unit_quantity' => 1,
            'target_duration_months' => 36,
            'payment_method' => 'cash',
        ])
        ->assertCreated()
        ->json('data.id');

    InvestmentAccount::whereKey($investmentId)->update([
        'start_date' => today()->subMonthsNoOverflow(18)->toDateString(),
        'maturity_date' => today()->addMonthsNoOverflow(18)->toDateString(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/investment-accounts/{$investmentId}")
        ->assertSuccessful()
        ->assertJsonPath('data.early_withdrawal_quote.elapsed_months', 18)
        ->assertJsonPath('data.early_withdrawal_quote.minimum_months', 36)
        ->assertJsonPath('data.early_withdrawal_quote.effective_return_rate', 18)
        ->assertJsonPath('data.progress_percentage', 50);
});

/**
 * @return array{0: User, 1: Branch}
 */
function investmentShareUser(): array
{
    $organization = Organization::create(['name' => 'Investment SACCO', 'slug' => fake()->unique()->slug(), 'status' => 'active']);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => '1001', 'name' => 'Luweero', 'is_active' => true, 'status' => 'active']);
    foreach (['clients', 'investments'] as $slug) {
        $module = Module::create(['name' => str($slug)->headline(), 'slug' => $slug, 'status' => 'active']);
        OrganizationModule::create(['organization_id' => $organization->id, 'module_id' => $module->id, 'is_enabled' => true, 'enabled_at' => now()]);
    }

    $role = Role::create(['organization_id' => $organization->id, 'name' => 'Investment Officer', 'slug' => 'investment-officer']);
    foreach (['manage-clients', 'manage-accounts', 'manage-investments'] as $permission) {
        $permissionModel = Permission::create(['name' => str($permission)->headline(), 'slug' => $permission]);
        $role->permissions()->syncWithoutDetaching([$permissionModel->id]);
    }

    foreach ([
        ['1000', 'Cash on Hand', 'asset', 'debit'],
        ['2200', 'Investment Liability', 'liability', 'credit'],
    ] as [$code, $name, $type, $normalBalance]) {
        ChartAccount::create([
            'organization_id' => $organization->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'normal_balance' => $normalBalance,
            'is_active' => true,
        ]);
    }

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    return [$user, $branch];
}

function investmentShareClient(User $user, string $name, string $phone): Client
{
    return Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => $name,
        'last_name' => 'Investor',
        'phone' => $phone,
        'kyc_status' => 'verified',
        'status' => 'active',
    ]);
}
