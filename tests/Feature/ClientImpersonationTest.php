<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('lets an organization admin securely view and leave an eligible client portal', function () {
    [$organization, , $administrator, $member, $client] = clientImpersonationFixture();
    $member->update(['must_change_password' => true]);

    $this->actingAs($administrator)
        ->post(route('clients.impersonate', $client))
        ->assertRedirect(route('member.dashboard'))
        ->assertSessionHas('impersonator_user_id', $administrator->id);

    $this->assertAuthenticatedAs($member);

    $this->get(route('member.dashboard'))
        ->assertSuccessful()
        ->assertSee('Support session — Acting as '.$member->name)
        ->assertSee('Actions are live and audited')
        ->assertSee($client->client_number);

    $this->delete(route('impersonation.destroy'))
        ->assertRedirect(route('clients.show', $client))
        ->assertSessionMissing('impersonator_user_id');

    $this->assertAuthenticatedAs($administrator);

    expect(AuditLog::query()->withoutGlobalScope('tenant')->where('action', 'auth.impersonation.started')->exists())->toBeTrue()
        ->and(AuditLog::query()->withoutGlobalScope('tenant')->where('action', 'auth.impersonation.stopped')->exists())->toBeTrue()
        ->and($administrator->organization_id)->toBe($organization->id);
});

it('lets a super admin view a client and restores the previous organization selection', function () {
    [$organization, , , $member, $client] = clientImpersonationFixture();
    $superAdminRole = Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
    $superAdmin = User::factory()->create([
        'organization_id' => null,
        'branch_id' => null,
        'role_id' => $superAdminRole->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->actingAs($superAdmin)
        ->withSession(['super_admin_organization_id' => $organization->id])
        ->post(route('clients.impersonate', $client))
        ->assertRedirect(route('member.dashboard'));

    $this->assertAuthenticatedAs($member);

    $this->delete(route('impersonation.destroy'))
        ->assertSessionHas('super_admin_organization_id', $organization->id);

    $this->assertAuthenticatedAs($superAdmin);
});

it('blocks non administrators and cross organization administrators from client impersonation', function () {
    [$organization, $branch, , , $client] = clientImpersonationFixture();
    $loanOfficerRole = Role::query()->firstOrCreate(['slug' => 'loan-officer'], ['name' => 'Loan Officer']);
    $loanOfficer = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $loanOfficerRole->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    $loanOfficerResponse = $this->actingAs($loanOfficer)->post(route('clients.impersonate', $client));

    $loanOfficerResponse
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('warning')
        ->assertSessionMissing('impersonator_user_id');
    $this->assertAuthenticatedAs($loanOfficer);

    [$otherOrganization, , $otherAdministrator] = clientImpersonationFixture('Other Organization');
    $response = $this->actingAs($otherAdministrator)->post(route('clients.impersonate', $client));

    $response
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('warning')
        ->assertSessionMissing('impersonator_user_id');
    expect($otherAdministrator->organization_id)->toBe($otherOrganization->id);
    $this->assertAuthenticatedAs($otherAdministrator);
});

it('requires approved client portal access and shows the action only to administrators', function () {
    [$organization, $branch, $administrator, , $client] = clientImpersonationFixture();
    $client->update(['user_id' => null]);

    $this->actingAs($administrator)
        ->post(route('clients.impersonate', $client))
        ->assertStatus(422);

    $this->get(route('clients.show', $client))->assertDontSee('View as client');

    $memberRole = Role::query()->firstOrCreate(['slug' => 'member'], ['name' => 'Member']);
    $member = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $memberRole->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $client->update(['user_id' => $member->id]);

    $this->actingAs($administrator)
        ->get(route('clients.show', $client))
        ->assertSuccessful()
        ->assertSee('View as client');
});

/** @return array{Organization, Branch, User, User, Client} */
function clientImpersonationFixture(string $organizationName = 'Support SACCO'): array
{
    Cache::clear();
    $organization = Organization::create([
        'name' => $organizationName,
        'slug' => str($organizationName)->slug()->append('-', fake()->unique()->numerify('####'))->toString(),
        'status' => 'active',
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => fake()->unique()->numerify('####'),
        'name' => 'Main Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $administratorRole = Role::query()->firstOrCreate(['slug' => 'organization-admin'], ['name' => 'Organization Admin']);
    $memberRole = Role::query()->firstOrCreate(['slug' => 'member'], ['name' => 'Member']);
    $administrator = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'role_id' => $administratorRole->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $member = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $memberRole->id,
        'status' => 'active',
        'is_active' => true,
        'must_change_password' => false,
    ]);
    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'user_id' => $member->id,
        'client_number' => $branch->code.'-C000001',
        'client_type' => 'individual',
        'first_name' => 'Amina',
        'last_name' => 'Nabirye',
        'phone' => fake()->unique()->numerify('070#######'),
        'national_id' => fake()->unique()->bothify('CM########????'),
        'date_of_birth' => '1990-01-01',
        'gender' => 'female',
        'kyc_status' => 'verified',
        'status' => 'active',
    ]);

    foreach ([['Clients', 'clients'], ['Loans', 'loans']] as [$name, $slug]) {
        $module = Module::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'status' => 'active']);
        OrganizationModule::updateOrCreate(
            ['organization_id' => $organization->id, 'module_id' => $module->id],
            ['is_enabled' => true, 'enabled_at' => now()],
        );
    }

    return [$organization, $branch, $administrator, $member, $client];
}
