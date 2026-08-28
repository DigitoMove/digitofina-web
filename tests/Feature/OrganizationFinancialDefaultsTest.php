<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\ChartAccountTemplate;
use App\Models\Client;
use App\Models\DailyAccountingSummary;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanProductTemplate;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\FinancialDefaultsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function financialDefaultsAdministrator(string $roleSlug = 'super-admin'): User
{
    $role = Role::create(['name' => str($roleSlug)->headline(), 'slug' => $roleSlug]);

    return User::factory()->create([
        'role_id' => $role->id,
        'organization_id' => null,
        'branch_id' => null,
        'status' => 'active',
        'is_active' => true,
    ]);
}

function financialDefaultsOrganization(): Organization
{
    return Organization::create([
        'name' => 'Defaults Test SACCO',
        'slug' => fake()->unique()->slug(),
        'status' => 'active',
    ]);
}

it('lets only a super administrator view and selectively assign defaults', function () {
    $organization = financialDefaultsOrganization();
    $branch = Branch::withoutEvents(fn (): Branch => Branch::create([
        'organization_id' => $organization->id,
        'code' => 'SELECT',
        'name' => 'Selection Branch',
        'status' => 'active',
        'is_active' => true,
    ]));
    app(FinancialDefaultsService::class)->syncCatalog();
    $loanTemplates = LoanProductTemplate::query()->orderBy('id')->limit(2)->pluck('id');
    $organizationAccount = ChartAccountTemplate::query()->where('scope', 'organization')->firstOrFail();
    $branchAccount = ChartAccountTemplate::query()->where('scope', 'branch')->firstOrFail();
    $administrator = financialDefaultsAdministrator();

    $this->actingAs($administrator)
        ->get(route('organizations.financial-defaults.index', $organization))
        ->assertSuccessful()
        ->assertSee($organization->name)
        ->assertSee('Assign selected defaults');

    $payload = [
        'loan_product_template_ids' => $loanTemplates->all(),
        'chart_account_template_ids' => [$organizationAccount->id, $branchAccount->id],
    ];
    $this->post(route('organizations.financial-defaults.store', $organization), $payload)
        ->assertRedirect()
        ->assertSessionHas('status');
    $this->post(route('organizations.financial-defaults.store', $organization), $payload)
        ->assertRedirect();

    expect(LoanProduct::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->count())->toBe(2)
        ->and(ChartAccount::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->count())->toBe(2)
        ->and(ChartAccount::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->where('code', $organizationAccount->code)->exists())->toBeTrue()
        ->and(ChartAccount::withoutGlobalScope('tenant')->where('branch_id', $branch->id)->where('code', 'SELECT-'.$branchAccount->code)->exists())->toBeTrue();
});

it('forbids organization administrators from managing system defaults', function () {
    $organization = financialDefaultsOrganization();
    $administrator = financialDefaultsAdministrator('organization-admin');
    $administrator->update(['organization_id' => $organization->id]);

    $this->actingAs($administrator)
        ->get(route('organizations.financial-defaults.index', $organization))
        ->assertRedirect();
    $this->actingAs($administrator)->post(route('organizations.financial-defaults.store', $organization), [
        'loan_product_template_ids' => [1],
    ])->assertRedirect();
    $this->actingAs($administrator)->delete(route('organizations.financial-defaults.destroy', $organization), [
        'resource' => 'loan_products',
        'confirmation' => 'RESET '.$organization->slug,
    ])->assertRedirect();

    expect(LoanProduct::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->exists())->toBeFalse();
});

it('resets unreferenced loan products in place and removes custom products', function () {
    $organization = financialDefaultsOrganization();
    $financialDefaults = app(FinancialDefaultsService::class);
    $financialDefaults->provisionOrganization($organization);
    $microLoan = LoanProduct::withoutGlobalScope('tenant')
        ->where('organization_id', $organization->id)
        ->where('code', 'MICRO-12')
        ->firstOrFail();
    $microLoan->update(['name' => 'Changed Product Name']);
    LoanProduct::create([
        'organization_id' => $organization->id,
        'name' => 'Custom Product',
        'code' => 'CUSTOM',
        'min_amount' => 10000,
        'max_amount' => 100000,
        'annual_interest_rate' => 10,
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->actingAs(financialDefaultsAdministrator())
        ->delete(route('organizations.financial-defaults.destroy', $organization), [
            'resource' => 'loan_products',
            'confirmation' => 'RESET '.$organization->slug,
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(LoanProduct::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->count())->toBe(5)
        ->and(LoanProduct::withoutGlobalScope('tenant')->findOrFail($microLoan->id)->name)->toBe('Micro Business Loan')
        ->and(LoanProduct::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->where('code', 'CUSTOM')->exists())->toBeFalse();
});

it('blocks a loan product reset when historical loans reference the products', function () {
    $organization = financialDefaultsOrganization();
    app(FinancialDefaultsService::class)->provisionOrganization($organization);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'HISTORY',
        'name' => 'History Branch',
        'status' => 'active',
        'is_active' => true,
    ]);
    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => 'HISTORY-C001',
        'client_type' => 'individual',
        'first_name' => 'Historical',
        'last_name' => 'Borrower',
        'phone' => '+256700000123',
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $product = LoanProduct::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->firstOrFail();
    Loan::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $product->id,
        'loan_number' => 'HISTORY-L001',
        'requested_amount' => 100000,
        'interest_rate' => 10,
        'term_periods' => 4,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
    ]);

    $this->actingAs(financialDefaultsAdministrator())
        ->delete(route('organizations.financial-defaults.destroy', $organization), [
            'resource' => 'loan_products',
            'confirmation' => 'RESET '.$organization->slug,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('financial_defaults');

    $this->assertModelExists($product);
});

it('resets unused chart accounts but blocks accounts with accounting summaries', function () {
    $organization = financialDefaultsOrganization();
    $financialDefaults = app(FinancialDefaultsService::class);
    $financialDefaults->provisionOrganization($organization);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'LEDGER',
        'name' => 'Ledger Branch',
        'status' => 'active',
        'is_active' => true,
    ]);
    $cash = ChartAccount::withoutGlobalScope('tenant')
        ->where('organization_id', $organization->id)
        ->where('code', '1000')
        ->firstOrFail();
    $cash->update(['name' => 'Changed Cash']);
    $custom = ChartAccount::create([
        'organization_id' => $organization->id,
        'code' => '9998',
        'name' => 'Unused Custom',
        'type' => 'expense',
        'normal_balance' => 'debit',
        'is_active' => true,
    ]);
    $administrator = financialDefaultsAdministrator();

    $this->actingAs($administrator)
        ->delete(route('organizations.financial-defaults.destroy', $organization), [
            'resource' => 'chart_accounts',
            'confirmation' => 'RESET '.$organization->slug,
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(ChartAccount::withoutGlobalScope('tenant')->findOrFail($cash->id)->name)->toBe('Cash on Hand')
        ->and(ChartAccount::withoutGlobalScope('tenant')->whereKey($custom->id)->exists())->toBeFalse();

    DailyAccountingSummary::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'chart_account_id' => $cash->id,
        'business_date' => today(),
        'account_code' => $cash->code,
        'account_name' => $cash->name,
    ]);

    $this->delete(route('organizations.financial-defaults.destroy', $organization), [
        'resource' => 'chart_accounts',
        'confirmation' => 'RESET '.$organization->slug,
    ])->assertSessionHasErrors('financial_defaults');

    $this->assertModelExists($cash);
});

it('requires the organization-specific reset phrase', function () {
    $organization = financialDefaultsOrganization();

    $this->actingAs(financialDefaultsAdministrator())
        ->delete(route('organizations.financial-defaults.destroy', $organization), [
            'resource' => 'loan_products',
            'confirmation' => 'RESET something-else',
        ])
        ->assertSessionHasErrors('confirmation');
});
