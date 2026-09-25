<?php

use App\Models\Organization;
use App\Models\OrganizationContract;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets a super administrator create and share organization contract versions', function () {
    $organization = Organization::create(['name' => 'Contract Organization', 'slug' => 'contract-org', 'status' => 'active']);
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $admin = User::factory()->create(['organization_id' => null, 'role_id' => $role->id, 'status' => 'active', 'is_active' => true]);

    $this->actingAs($admin)->post(route('organizations.contracts.store', $organization), [
        'title' => 'Software Services Agreement',
        'term_months' => 24,
        'total_fee' => 750000,
        'paid_amount' => 300000,
        'annual_hosting_fee' => 200000,
        'included_modules' => 'Loans, savings, accounting',
    ])->assertRedirect();

    $contract = OrganizationContract::firstOrFail();
    expect($contract->balance_due)->toBe('450000.00');

    $this->actingAs($admin)->post(route('organizations.contracts.share', [$organization, $contract]))
        ->assertRedirect();
    expect($contract->fresh()->status)->toBe('shared');
});

it('accepts customer comments through the public contract review link', function () {
    $organization = Organization::create(['name' => 'Review Organization', 'slug' => 'review-org', 'status' => 'active']);
    $contract = OrganizationContract::create([
        'organization_id' => $organization->id,
        'title' => 'Software Services Agreement',
        'public_token' => str()->random(64),
        'status' => 'shared',
        'term_months' => 24,
        'total_fee' => 750000,
        'paid_amount' => 300000,
        'balance_due' => 450000,
        'annual_hosting_fee' => 200000,
    ]);

    $this->post(route('contracts.review.comment', [$contract, $contract->public_token]), [
        'name' => 'Customer reviewer',
        'email' => 'reviewer@example.test',
        'comment' => 'Please clarify the renewal review date.',
    ])->assertRedirect();

    expect($contract->fresh()->status)->toBe('changes_requested')
        ->and($contract->comments()->count())->toBe(1);
});
