<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\OrganizationContract;
use App\Models\OrganizationHostingFee;
use App\Models\OrganizationSubscription;
use App\Models\Role;
use App\Models\User;
use App\Services\BillingAdministrationService;
use App\Services\QuoteBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets a super admin directly assign a plan and creates the annual hosting charge', function () {
    [$organization, $organizationAdmin, $superAdmin] = billingAdministrationFixture();

    $this->actingAs($superAdmin)->post(route('organization-subscriptions.store'), [
        'organization_id' => $organization->id,
        'billing_type' => 'subscription',
        'assignment_basis' => 'assigned',
        'subscription_months' => 6,
        'amount' => 1200000,
        'starts_at' => today()->toDateString(),
        'notes' => 'Six months assigned during onboarding.',
    ])->assertRedirect(route('commercial-quotes.index'))->assertSessionHasNoErrors();

    $subscription = OrganizationSubscription::firstOrFail();
    expect($subscription->organization_id)->toBe($organization->id)
        ->and($subscription->activation_source)->toBe('admin_assignment')
        ->and($subscription->payment_status)->toBe('assigned')
        ->and($subscription->current_period_ends_at->toDateString())->toBe(today()->addMonthsNoOverflow(6)->toDateString())
        ->and($organization->fresh()->is_demo)->toBeFalse()
        ->and(OrganizationHostingFee::count())->toBe(1)
        ->and((float) OrganizationHostingFee::firstOrFail()->amount)->toBe(200000.0);

    $this->actingAs($organizationAdmin)->get(route('commercial-quotes.index'))
        ->assertOk()
        ->assertSee('Monthly subscription')
        ->assertSee('Assigned by DigitoFina')
        ->assertSee('200,000 per year');
});

it('starts annual hosting payments on the contract due date and repeats yearly', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
    [$organization, $organizationAdmin] = billingAdministrationFixture();
    OrganizationContract::create([
        'organization_id' => $organization->id,
        'title' => 'Hosting schedule agreement',
        'public_token' => str()->random(64),
        'annual_hosting_fee' => 275000,
        'annual_hosting_first_due_on' => '2027-01-15',
    ]);
    $billing = app(BillingAdministrationService::class);

    expect($billing->ensureCurrentHostingFee($organization))->toBeNull()
        ->and(OrganizationHostingFee::count())->toBe(0);

    $this->actingAs($organizationAdmin)->get(route('commercial-quotes.index'))
        ->assertOk()
        ->assertSee('First annual hosting payment is due 15 Jan 2027');

    $this->travelTo(CarbonImmutable::parse('2027-01-15 09:00:00'));
    $first = $billing->ensureCurrentHostingFee($organization);
    expect($first?->due_on?->toDateString())->toBe('2027-01-15')
        ->and($first?->period_starts_on?->toDateString())->toBe('2027-01-15')
        ->and($first?->period_ends_on?->toDateString())->toBe('2028-01-14')
        ->and((float) $first?->amount)->toBe(275000.0)
        ->and($billing->ensureCurrentHostingFee($organization)?->id)->toBe($first?->id)
        ->and(OrganizationHostingFee::count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2028-01-15 09:00:00'));
    $second = $billing->ensureCurrentHostingFee($organization);
    expect($second?->due_on?->toDateString())->toBe('2028-01-15')
        ->and($second?->period_ends_on?->toDateString())->toBe('2029-01-14')
        ->and(OrganizationHostingFee::count())->toBe(2);
});

it('keeps an issued hosting charge when a contract changes the future due date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
    [$organization] = billingAdministrationFixture();
    $billing = app(BillingAdministrationService::class);
    $issued = $billing->ensureCurrentHostingFee($organization);
    $originalDueOn = $issued?->due_on?->toDateString();
    $firstContractDueOn = today()->addDays(90)->toDateString();
    OrganizationContract::create([
        'organization_id' => $organization->id,
        'title' => 'Revised hosting schedule',
        'public_token' => str()->random(64),
        'annual_hosting_fee' => 300000,
        'annual_hosting_first_due_on' => $firstContractDueOn,
    ]);

    expect($billing->ensureCurrentHostingFee($organization)?->id)->toBe($issued?->id)
        ->and($issued?->fresh()->due_on?->toDateString())->toBe($originalDueOn)
        ->and(OrganizationHostingFee::count())->toBe(1);

    $nextContractDueOn = CarbonImmutable::parse($firstContractDueOn)->addYearNoOverflow();
    $this->travelTo($nextContractDueOn->setTime(9, 0));
    $next = $billing->ensureCurrentHostingFee($organization);
    expect($next?->due_on?->toDateString())->toBe($nextContractDueOn->toDateString())
        ->and((float) $next?->amount)->toBe(300000.0)
        ->and(OrganizationHostingFee::count())->toBe(2);
});

it('does not issue a payable hosting quote when the contract waives the annual fee', function () {
    [$organization] = billingAdministrationFixture();
    OrganizationContract::create([
        'organization_id' => $organization->id,
        'title' => 'Hosting waiver agreement',
        'public_token' => str()->random(64),
        'annual_hosting_fee' => 0,
        'annual_hosting_first_due_on' => today()->toDateString(),
    ]);

    expect(app(BillingAdministrationService::class)->ensureCurrentHostingFee($organization))->toBeNull()
        ->and(OrganizationHostingFee::count())->toBe(0);
});

it('records an externally paid one-time licence and replaces the previous active plan', function () {
    [$organization, , $superAdmin] = billingAdministrationFixture();
    $billing = app(BillingAdministrationService::class);
    $first = $billing->assignSubscription([
        'organization_id' => $organization->id, 'billing_type' => 'subscription', 'assignment_basis' => 'assigned',
        'subscription_months' => 1, 'amount' => 200000, 'starts_at' => today()->toDateString(),
    ], $superAdmin);

    $this->actingAs($superAdmin)->post(route('organization-subscriptions.store'), [
        'organization_id' => $organization->id,
        'billing_type' => 'one_time',
        'assignment_basis' => 'paid',
        'amount' => 2500000,
        'starts_at' => today()->toDateString(),
        'notes' => 'Bank transfer confirmed.',
    ])->assertSessionHasNoErrors();

    expect($first->fresh()->status)->toBe('cancelled');
    $current = $organization->fresh()->latestSubscription;
    expect($current->billing_type)->toBe('one_time')
        ->and($current->activation_source)->toBe('manual_payment')
        ->and($current->payment_status)->toBe('paid')
        ->and($current->current_period_ends_at)->toBeNull();
});

it('prevents organization admins from directly assigning plans', function () {
    [$organization, $organizationAdmin] = billingAdministrationFixture();

    $this->actingAs($organizationAdmin)->post(route('organization-subscriptions.store'), [
        'organization_id' => $organization->id,
        'billing_type' => 'one_time',
        'assignment_basis' => 'assigned',
        'amount' => 1500000,
        'starts_at' => today()->toDateString(),
    ])->assertRedirect();

    expect(OrganizationSubscription::count())->toBe(0);
});

it('marks a paid hosting quote without creating or changing a subscription', function () {
    [$organization, , $superAdmin] = billingAdministrationFixture();
    $hostingFee = app(BillingAdministrationService::class)->ensureCurrentHostingFee($organization);
    $hostingFee->quote->update(['nylon_reference' => 'dfh0000000001', 'status' => 'payment_pending']);

    app(QuoteBillingService::class)->processWebhook([
        'timestamp' => now()->timestamp,
        'transaction' => [
            'id' => 'hosting-payment-1',
            'reference' => 'dfh0000000001',
            'status' => 'successful',
            'amount' => 200000,
            'currency' => 'UGX',
        ],
    ]);

    expect($hostingFee->fresh()->status)->toBe('paid')
        ->and($hostingFee->fresh()->provider)->toBe('nylon-pay')
        ->and(OrganizationSubscription::count())->toBe(0);
});

/** @return array{Organization, User, User} */
function billingAdministrationFixture(): array
{
    $organization = Organization::create([
        'name' => fake()->company(), 'slug' => fake()->unique()->slug(), 'status' => 'active',
        'is_demo' => true, 'demo_started_at' => now(), 'demo_expires_at' => now()->addDays(14),
    ]);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => fake()->unique()->numerify('####'), 'name' => 'Main', 'is_active' => true, 'status' => 'active']);
    $organizationAdminRole = Role::query()->firstOrCreate(['slug' => 'organization-admin'], ['name' => 'Organization Admin']);
    $superAdminRole = Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
    $organizationAdmin = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'role_id' => $organizationAdminRole->id, 'status' => 'active', 'is_active' => true]);
    $superAdmin = User::factory()->create(['organization_id' => null, 'branch_id' => null, 'role_id' => $superAdminRole->id, 'status' => 'active', 'is_active' => true]);

    return [$organization, $organizationAdmin, $superAdmin];
}
