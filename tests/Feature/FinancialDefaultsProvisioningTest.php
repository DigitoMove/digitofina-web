<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\ChartAccountTemplate;
use App\Models\LoanProduct;
use App\Models\LoanProductTemplate;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\FinancialDefaultsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('provisions financial defaults when a super administrator creates an organization', function () {
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $superAdministrator = User::factory()->create([
        'role_id' => $role->id,
        'organization_id' => null,
        'branch_id' => null,
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->actingAs($superAdministrator)->post(route('organizations.store'), [
        'name' => 'New Platform Tenant',
        'slug' => 'new-platform-tenant',
        'currency' => 'UGX',
        'timezone' => 'Africa/Kampala',
        'status' => 'active',
    ])->assertRedirect(route('organizations.index'));

    $organization = Organization::query()->where('slug', 'new-platform-tenant')->firstOrFail();

    expect(LoanProduct::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->count())->toBe(5)
        ->and(ChartAccount::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->whereNull('branch_id')->count())->toBe(15);
});

it('keeps system templates and gives every new organization its own financial defaults', function () {
    $firstOrganization = Organization::create([
        'name' => 'First SACCO',
        'slug' => 'first-sacco',
        'status' => 'active',
    ]);
    $secondOrganization = Organization::create([
        'name' => 'Second SACCO',
        'slug' => 'second-sacco',
        'status' => 'active',
    ]);
    $financialDefaults = app(FinancialDefaultsService::class);
    $financialDefaults->provisionOrganization($firstOrganization);
    $financialDefaults->provisionOrganization($secondOrganization);

    expect(LoanProductTemplate::query()->count())->toBe(5)
        ->and(ChartAccountTemplate::query()->where('scope', 'organization')->count())->toBe(15)
        ->and(ChartAccountTemplate::query()->where('scope', 'branch')->count())->toBe(11)
        ->and(LoanProduct::withoutGlobalScope('tenant')->where('organization_id', $firstOrganization->id)->count())->toBe(5)
        ->and(LoanProduct::withoutGlobalScope('tenant')->where('organization_id', $secondOrganization->id)->count())->toBe(5)
        ->and(ChartAccount::withoutGlobalScope('tenant')->where('organization_id', $firstOrganization->id)->whereNull('branch_id')->count())->toBe(15)
        ->and(ChartAccount::withoutGlobalScope('tenant')->where('organization_id', $secondOrganization->id)->whereNull('branch_id')->count())->toBe(15);

    $firstMicroLoan = LoanProduct::withoutGlobalScope('tenant')
        ->where('organization_id', $firstOrganization->id)
        ->where('code', 'MICRO-12')
        ->firstOrFail();
    $secondMicroLoan = LoanProduct::withoutGlobalScope('tenant')
        ->where('organization_id', $secondOrganization->id)
        ->where('code', 'MICRO-12')
        ->firstOrFail();

    expect($firstMicroLoan->id)->not->toBe($secondMicroLoan->id)
        ->and($firstMicroLoan->loan_product_template_id)->toBe($secondMicroLoan->loan_product_template_id)
        ->and($firstMicroLoan->max_amount)->toBe('10000000.00')
        ->and($firstMicroLoan->annual_interest_rate)->toBe('24.0000');
});

it('gives each new branch branch-owned chart accounts without changing the organization defaults', function () {
    $organization = Organization::create([
        'name' => 'Branching SACCO',
        'slug' => 'branching-sacco',
        'status' => 'active',
    ]);
    app(FinancialDefaultsService::class)->provisionOrganization($organization);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'HQ01',
        'name' => 'Head Office',
        'status' => 'active',
        'is_active' => true,
    ]);

    expect(ChartAccount::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->whereNull('branch_id')->count())->toBe(15)
        ->and(ChartAccount::withoutGlobalScope('tenant')->where('branch_id', $branch->id)->count())->toBe(11);

    $openingEquity = ChartAccount::withoutGlobalScope('tenant')
        ->where('branch_id', $branch->id)
        ->where('code', 'HQ01-3990')
        ->firstOrFail();

    expect($openingEquity->name)->toBe('Opening Balance Equity')
        ->and($openingEquity->is_system)->toBeTrue()
        ->and($openingEquity->chart_account_template_id)->not->toBeNull();
});

it('does not add catalog products to an existing organization that already has a product', function () {
    $organization = Organization::withoutEvents(fn (): Organization => Organization::create([
        'name' => 'Existing Lender',
        'slug' => 'existing-lender',
        'status' => 'active',
    ]));
    LoanProduct::withoutEvents(fn (): LoanProduct => LoanProduct::create([
        'organization_id' => $organization->id,
        'name' => 'Existing Product',
        'code' => 'EXISTING',
        'min_amount' => 10000,
        'max_amount' => 100000,
        'annual_interest_rate' => 12,
        'status' => 'active',
        'is_active' => true,
    ]));

    app(FinancialDefaultsService::class)->provisionOrganization($organization);

    expect(LoanProduct::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->count())->toBe(1)
        ->and(LoanProduct::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->firstOrFail()->code)->toBe('EXISTING');
});

it('is retry safe and preserves tenant customizations', function () {
    $organization = Organization::create([
        'name' => 'Custom SACCO',
        'slug' => 'custom-sacco',
        'status' => 'active',
    ]);
    app(FinancialDefaultsService::class)->provisionOrganization($organization);
    $product = LoanProduct::withoutGlobalScope('tenant')
        ->where('organization_id', $organization->id)
        ->where('code', 'MICRO-12')
        ->firstOrFail();
    $product->update(['name' => 'Our Custom Micro Loan']);

    app(FinancialDefaultsService::class)->provisionOrganization($organization);

    expect(LoanProduct::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->count())->toBe(5)
        ->and($product->fresh()->name)->toBe('Our Custom Micro Loan')
        ->and(ChartAccount::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->whereNull('branch_id')->count())->toBe(15);
});
