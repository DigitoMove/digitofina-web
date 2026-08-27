<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\DemoAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('shows the marketing landing page and demo option on an available host', function () {
    $this->get('https://gateoffina.com/')
        ->assertSuccessful()
        ->assertSee('Every loan. Every branch.')
        ->assertSee('Create your 14-day demo')
        ->assertSee('UGX 200,000')
        ->assertSee(route('pricing'))
        ->assertSee(route('login'));
});

it('keeps registered organization domains login only', function () {
    $organization = Organization::create([
        'name' => 'Existing SACCO',
        'slug' => 'existing-sacco',
        'status' => 'active',
    ]);
    OrganizationDomain::create([
        'organization_id' => $organization->id,
        'domain' => 'system.existing.test',
        'type' => 'custom_domain',
        'status' => 'active',
    ]);

    $this->get('https://system.existing.test/')
        ->assertRedirect(route('login'));

    $this->get('https://system.existing.test/login')
        ->assertSuccessful()
        ->assertDontSee('Create a 14-day demo');
});

it('lets platform administrators hide the demo on additional hosts', function () {
    SystemSetting::withoutGlobalScope('tenant')->create([
        'organization_id' => null,
        'key' => 'demo_hidden_hosts',
        'label' => 'Hidden demo hosts',
        'value' => json_encode(['private.example.test', '*.internal.test']),
        'type' => 'json',
        'group' => 'platform',
    ]);

    $this->get('https://private.example.test/demo')
        ->assertRedirect(route('login'));
    $this->post('https://branch.internal.test/demo', validDemoPayload())
        ->assertRedirect(route('login'));
    $this->get('https://public.example.test/demo')
        ->assertSuccessful();
});

it('creates an isolated organization with all modules and a fourteen day expiry', function () {
    $this->travelTo(now()->startOfMinute());
    app(DemoAccessService::class)->ensurePlatformSetting();

    $response = $this->post('https://gateoffina.com/demo', validDemoPayload());

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'owner@trial.test')->firstOrFail();
    $organization = $user->organization;

    expect($organization)
        ->not->toBeNull()
        ->and($organization->is_demo)->toBeTrue()
        ->and($organization->demo_started_at->equalTo(now()))->toBeTrue()
        ->and($organization->demo_expires_at->equalTo(now()->addDays(14)))->toBeTrue()
        ->and($organization->branches()->count())->toBe(1)
        ->and($organization->users()->count())->toBe(1)
        ->and($organization->modules()->wherePivot('is_enabled', true)->count())->toBe(Module::query()->count())
        ->and(SystemSetting::forOrganization($organization->id)->where('key', 'demo_hidden_hosts')->doesntExist())->toBeTrue()
        ->and($user->role?->slug)->toBe('organization-admin');
});

it('blocks a demo exactly at its expiry while leaving normal organizations unchanged', function () {
    $this->travelTo(now()->startOfMinute());
    [$demoOrganization, $demoUser] = demoTrialTenant(now()->addDays(14));
    [$normalOrganization, $normalUser] = demoTrialTenant(now()->subDay(), false);

    $this->actingAs($demoUser);
    $this->travel(14)->days();

    $this->get(route('dashboard'))
        ->assertRedirect(route('demo.expired'));
    $this->assertGuest();
    expect($demoOrganization->fresh()->status)->toBe('active');

    $this->actingAs($normalUser)
        ->get(route('dashboard'))
        ->assertSuccessful();
    expect($normalOrganization->fresh()->is_demo)->toBeFalse();
});

it('blocks expired demo logins but allows active demo logins', function () {
    [, $expiredUser] = demoTrialTenant(now()->subMinute());
    [, $activeUser] = demoTrialTenant(now()->addDay());

    $this->post(route('login'), [
        'login' => $expiredUser->email,
        'password' => 'Password123!',
    ])->assertSessionHasErrors('login');

    $this->post(route('login'), [
        'login' => $activeUser->email,
        'password' => 'Password123!',
    ])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($activeUser);
});

it('blocks expired demos from authenticated api routes', function () {
    [, $expiredUser] = demoTrialTenant(now()->subSecond());
    Sanctum::actingAs($expiredUser, ['*']);

    $this->getJson('/api/v1/auth/me')
        ->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'This demo expired after 14 days. Contact DigitoFina to continue with a full account.');
});

it('enforces demo record caps without changing existing data', function () {
    config()->set('demo.limits.clients', 1);
    [$organization, $user, $branch] = demoTrialTenant(now()->addDays(14));
    Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => 'CAP-001',
        'client_type' => 'individual',
        'first_name' => 'Existing',
        'last_name' => 'Client',
        'phone' => '+256700000001',
        'status' => 'active',
        'registered_at' => today(),
    ]);

    Route::middleware(['web', 'auth', 'tenant', 'demo.active', 'demo.capacity:clients'])
        ->post('/demo-capacity-probe', fn () => response()->noContent());

    $this->actingAs($user)
        ->post('/demo-capacity-probe')
        ->assertSessionHasErrors('demo_limit');

    expect($organization->clients()->count())->toBe(1);
});

/**
 * @return array<string, string>
 */
function validDemoPayload(): array
{
    return [
        'organization_name' => 'Sunrise Trial Finance',
        'name' => 'Trial Owner',
        'email' => 'owner@trial.test',
        'phone' => '+256701822382',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ];
}

/**
 * @return array{0: Organization, 1: User, 2: Branch}
 */
function demoTrialTenant(DateTimeInterface $expiresAt, bool $isDemo = true): array
{
    $suffix = Str::lower(Str::random(8));
    $organization = Organization::create([
        'name' => 'Trial '.$suffix,
        'slug' => 'trial-'.$suffix,
        'status' => 'active',
        'is_demo' => $isDemo,
        'demo_started_at' => now()->subDay(),
        'demo_expires_at' => $expiresAt,
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'TB-'.Str::upper($suffix),
        'name' => 'Main Branch',
        'status' => 'active',
        'is_active' => true,
    ]);
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Trial Admin '.$suffix,
        'slug' => 'trial-admin-'.$suffix,
    ]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'email' => "{$suffix}@trial.test",
        'phone' => '+256'.fake()->unique()->numerify('7########'),
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'is_active' => true,
    ]);

    return [$organization, $user, $branch];
}
