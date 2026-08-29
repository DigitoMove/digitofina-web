<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('lets an approved client sign in by phone and forces a temporary password change', function () {
    [, , $member, $client] = clientPortalFixture();
    $member->update(['password' => Hash::make('Temporary-123!'), 'must_change_password' => true]);

    $this->post(route('client.login.store'), ['phone' => '0701 222 333', 'password' => 'Temporary-123!'])
        ->assertRedirect(route('profile.edit'));
    $this->get(route('member.dashboard'))->assertRedirect(route('profile.edit'));
    $this->put(route('profile.password.update'), [
        'current_password' => 'Temporary-123!',
        'password' => 'Changed-Password-456!',
        'password_confirmation' => 'Changed-Password-456!',
    ])->assertRedirect(route('profile.edit'));

    expect($member->fresh()->must_change_password)->toBeFalse();
    $this->get(route('member.dashboard'))->assertOk()->assertSee($client->client_number);
});

it('allows only an eligible client without an open loan to request a loan', function () {
    [$organization, , $member, $client, $officer] = clientPortalFixture();
    $product = LoanProduct::create([
        'organization_id' => $organization->id, 'name' => 'Client Micro Loan', 'code' => 'CML',
        'min_amount' => 100000, 'max_amount' => 2000000, 'min_term' => 1, 'max_term' => 24,
        'annual_interest_rate' => 24, 'interest_method' => 'reducing_balance_equal_installments',
        'repayment_frequency' => 'monthly', 'required_savings_percentage' => 0, 'status' => 'active', 'is_active' => true,
    ]);

    $payload = ['loan_product_id' => $product->id, 'requested_amount' => 500000, 'term_periods' => 6, 'repayment_frequency' => 'monthly', 'purpose' => 'Restock my shop'];
    $this->actingAs($member)->post(route('member.loan-applications.store'), $payload)
        ->assertRedirect(route('member.dashboard'))->assertSessionHasNoErrors();

    $loan = Loan::firstOrFail();
    expect($loan->client_id)->toBe($client->id)->and($loan->officer_id)->toBe($officer->id)
        ->and($loan->created_by)->toBe($officer->id)->and($loan->status)->toBe('draft');

    $this->actingAs($member)->post(route('member.loan-applications.store'), $payload)->assertSessionHasErrors('loan');
    expect(Loan::count())->toBe(1);
});

/** @return array{Organization, Branch, User, Client, User} */
function clientPortalFixture(): array
{
    Cache::clear();
    $organization = Organization::create(['name' => fake()->company(), 'slug' => fake()->unique()->slug(), 'status' => 'active']);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => fake()->unique()->numerify('####'), 'name' => 'Main', 'is_active' => true, 'status' => 'active']);
    $memberRole = Role::query()->firstOrCreate(['slug' => 'member'], ['name' => 'Member']);
    $officerRole = Role::query()->firstOrCreate(['slug' => 'loan-officer'], ['name' => 'Loan Officer']);
    $member = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'role_id' => $memberRole->id, 'phone' => '256701222333', 'status' => 'active', 'is_active' => true, 'must_change_password' => false]);
    $officer = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'role_id' => $officerRole->id, 'status' => 'active', 'is_active' => true]);
    $client = Client::create([
        'organization_id' => $organization->id, 'branch_id' => $branch->id, 'officer_id' => $officer->id, 'user_id' => $member->id,
        'client_number' => $branch->code.'-C000001', 'client_type' => 'individual', 'first_name' => 'Amina', 'last_name' => 'Nabirye',
        'phone' => '0701222333', 'national_id' => fake()->unique()->bothify('CM########????'), 'date_of_birth' => '1990-01-01',
        'gender' => 'female', 'kyc_status' => 'verified', 'status' => 'active',
    ]);
    foreach ([['Loans', 'loans'], ['Clients', 'clients']] as [$name, $slug]) {
        $module = Module::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'status' => 'active']);
        OrganizationModule::updateOrCreate(['organization_id' => $organization->id, 'module_id' => $module->id], ['is_enabled' => true, 'enabled_at' => now()]);
    }

    return [$organization, $branch, $member, $client, $officer];
}
