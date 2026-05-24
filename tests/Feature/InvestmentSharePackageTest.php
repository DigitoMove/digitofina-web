<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
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
            'duration' => 12,
            'duration_unit' => 'months',
            'expected_return_rate' => 15,
            'return_calculation_method' => 'profit_share',
            'return_frequency' => 'annually',
            'allow_top_ups' => true,
            'allow_early_withdrawal' => false,
            'risk_level' => 'medium',
            'status' => 'active',
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_share_package', true)
        ->assertJsonPath('data.unit_label', 'goat')
        ->assertJsonPath('data.unit_value', 450000)
        ->assertJsonPath('data.maximum_units', 5)
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
            'payment_method' => 'cash',
        ])
        ->assertCreated()
        ->assertJsonPath('data.client.id', $child->id)
        ->assertJsonPath('data.sponsor_client.id', $parent->id)
        ->assertJsonPath('data.unit_quantity', 3)
        ->assertJsonPath('data.unit_label', 'goat')
        ->assertJsonPath('data.principal_amount', 1350000)
        ->assertJsonPath('data.current_balance', 1350000);

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
