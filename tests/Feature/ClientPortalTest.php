<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\OrganizationModule;
use App\Models\Role;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('provides a friendly client login link and preserves the previous address', function () {
    $this->get('/client')->assertOk()->assertSee('Access your loan account');
    $this->get('/client/login')->assertRedirect('/client');
    $this->post('/client/login', ['phone' => 'unknown-client', 'password' => 'incorrect-password'])
        ->assertSessionHasErrors('phone');

    expect(route('client.login', absolute: false))->toBe('/client')
        ->and(route('client.login.store', absolute: false))->toBe('/client');
});

it('serves the client login on an organization custom domain', function () {
    [$organization] = clientPortalFixture();
    config()->set('client_portal.domains', ['clients.*']);
    OrganizationDomain::create([
        'organization_id' => $organization->id,
        'domain' => 'clients.hope-sacco.test',
        'type' => 'custom_domain',
        'is_primary' => true,
        'status' => 'active',
        'verified_at' => now(),
    ]);

    $this->get('https://clients.hope-sacco.test/')
        ->assertRedirect('https://clients.hope-sacco.test/client');
    $this->get('https://clients.hope-sacco.test/client')
        ->assertOk()
        ->assertSee('Access your loan account');
});

it('does not redirect a non-client subdomain to the client login', function () {
    config()->set('client_portal.domains', ['clients.*']);

    $this->get('https://staff.hope-sacco.test/')
        ->assertOk()
        ->assertSee('DigitoFina');
});

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

it('automatically gives every newly registered client the default temporary portal password', function () {
    [$organization, $branch, , , $officer] = clientPortalFixture();

    $client = app(ClientService::class)->register([
        'branch_id' => $branch->id,
        'client_type' => 'individual',
        'first_name' => 'Sarah',
        'middle_name' => null,
        'last_name' => 'Nansubuga',
        'gender' => 'female',
        'date_of_birth' => '1992-04-10',
        'national_id' => 'CM90000002ABCD',
        'phone' => '0701555666',
        'email' => null,
        'kyc_status' => 'pending',
        'status' => 'active',
    ], $officer);

    $portalUser = $client->fresh()->user;

    expect($portalUser)->not->toBeNull()
        ->and($portalUser->isMember())->toBeTrue()
        ->and($portalUser->organization_id)->toBe($organization->id)
        ->and($portalUser->branch_id)->toBe($branch->id)
        ->and($portalUser->must_change_password)->toBeTrue()
        ->and(Hash::check('12345678', $portalUser->password))->toBeTrue();

    $this->post(route('client.login.store'), [
        'phone' => $client->client_number,
        'password' => '12345678',
    ])->assertRedirect(route('profile.edit'));
});

it('backfills and resets existing member portal accounts without changing staff-linked clients', function () {
    [$organization, $branch, $member, $client, $officer] = clientPortalFixture();
    $member->update(['password' => Hash::make('Old-Password-123!'), 'must_change_password' => false]);
    $unlinkedClient = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'officer_id' => $officer->id,
        'user_id' => null,
        'client_number' => $branch->code.'-C000002',
        'client_type' => 'individual',
        'first_name' => 'Grace',
        'last_name' => 'Nakku',
        'phone' => $client->phone,
        'kyc_status' => 'pending',
        'status' => 'active',
    ]);
    $staffLinkedClient = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'officer_id' => $officer->id,
        'user_id' => $officer->id,
        'client_number' => $branch->code.'-C000003',
        'client_type' => 'individual',
        'first_name' => 'Staff',
        'last_name' => 'Savings',
        'phone' => '0701999888',
        'kyc_status' => 'pending',
        'status' => 'active',
    ]);

    $this->artisan('clients:provision-portal-access')->assertSuccessful();

    expect(Hash::check('12345678', $member->fresh()->password))->toBeTrue()
        ->and($member->fresh()->must_change_password)->toBeTrue()
        ->and($unlinkedClient->fresh()->user?->isMember())->toBeTrue()
        ->and(Hash::check('12345678', $unlinkedClient->fresh()->user->password))->toBeTrue()
        ->and($staffLinkedClient->fresh()->user_id)->toBe($officer->id)
        ->and($officer->fresh()->isMember())->toBeFalse();

    $userCount = User::query()->count();
    $this->artisan('clients:provision-portal-access')->assertSuccessful();
    expect(User::query()->count())->toBe($userCount);

    $this->post(route('client.login.store'), [
        'phone' => $unlinkedClient->client_number,
        'password' => '12345678',
    ])->assertRedirect(route('profile.edit'));
});

it('allows organization admins to reset client passwords and blocks non-admin staff', function () {
    [$organization, , $member, $client, $officer] = clientPortalFixture();
    $administratorRole = Role::query()->firstOrCreate(['slug' => 'organization-admin'], ['name' => 'Organization Admin']);
    $administrator = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'role_id' => $administratorRole->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->actingAs($administrator)->post(route('clients.portal-access.store', $client), [
        'password' => 'Admin-Reset-123!',
        'password_confirmation' => 'Admin-Reset-123!',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Hash::check('Admin-Reset-123!', $member->fresh()->password))->toBeTrue()
        ->and($member->fresh()->must_change_password)->toBeTrue();

    $this->actingAs($officer)->post(route('clients.portal-access.store', $client), [
        'password' => 'Officer-Reset-123!',
        'password_confirmation' => 'Officer-Reset-123!',
    ])->assertRedirect(route('dashboard'))->assertSessionHas('warning');

    expect(Hash::check('Admin-Reset-123!', $member->fresh()->password))->toBeTrue();
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
