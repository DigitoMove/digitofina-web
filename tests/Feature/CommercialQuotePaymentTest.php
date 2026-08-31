<?php

use App\Models\CommercialQuote;
use App\Models\NylonPayCredential;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

it('lets an organization admin request a quote and a super admin issue it', function () {
    [$organization, $organizationAdmin] = quoteOrganizationAdministrator('Quote Request Tenant');
    $superAdmin = quoteSuperAdministrator();

    $this->actingAs($organizationAdmin)
        ->post(route('commercial-quotes.store'), [
            'billing_type' => 'subscription',
            'subscription_months' => 3,
            'requested_scope' => 'We need three branches, staff onboarding, data migration, and management training.',
        ])
        ->assertRedirect(route('commercial-quotes.index'))
        ->assertSessionHasNoErrors();

    $quote = CommercialQuote::firstOrFail();

    expect($quote->organization_id)->toBe($organization->id)
        ->and($quote->status)->toBe('requested')
        ->and($superAdmin->notifications()->count())->toBe(1);

    $this->actingAs($superAdmin)
        ->put(route('commercial-quotes.issue', $quote), [
            'billing_type' => 'subscription',
            'subscription_months' => 3,
            'amount' => 600000,
            'currency' => 'UGX',
            'valid_until' => today()->addDays(14)->toDateString(),
            'terms' => 'Includes setup, training, and support for the quoted period.',
        ])
        ->assertRedirect(route('commercial-quotes.index'))
        ->assertSessionHasNoErrors();

    expect($quote->fresh()->status)->toBe('issued')
        ->and((float) $quote->fresh()->amount)->toBe(600000.0)
        ->and($organizationAdmin->notifications()->count())->toBe(1);

    $this->actingAs($organizationAdmin)
        ->get(route('commercial-quotes.index'))
        ->assertSuccessful()
        ->assertSee($quote->quote_number)
        ->assertSee('Pay securely with Nylon Pay');
});

it('lets a super admin create and send a quote directly to an organization', function () {
    [$organization, $organizationAdmin] = quoteOrganizationAdministrator('Direct Quote Tenant');
    $superAdmin = quoteSuperAdministrator();

    $this->actingAs($superAdmin)
        ->post(route('commercial-quotes.organizations.store'), [
            'organization_id' => $organization->id,
            'billing_type' => 'subscription',
            'subscription_months' => 6,
            'requested_scope' => 'Six months of service for two branches, setup, onboarding, and staff training.',
            'amount' => 900000,
            'currency' => 'UGX',
            'valid_until' => today()->addDays(14)->toDateString(),
            'terms' => 'The agreed amount includes onboarding, training, support, and two branches.',
        ])
        ->assertRedirect(route('commercial-quotes.index'))
        ->assertSessionHasNoErrors();

    $quote = CommercialQuote::query()->sole();

    expect($quote->organization_id)->toBe($organization->id)
        ->and($quote->requested_by)->toBeNull()
        ->and($quote->issued_by)->toBe($superAdmin->id)
        ->and($quote->status)->toBe('issued')
        ->and((float) $quote->amount)->toBe(900000.0)
        ->and($organizationAdmin->notifications()->count())->toBe(1);

    $this->actingAs($organizationAdmin)
        ->get(route('commercial-quotes.index'))
        ->assertSuccessful()
        ->assertSee($quote->quote_number)
        ->assertSee('Created by DigitoFina')
        ->assertSee('Pay securely with Nylon Pay');
});

it('keeps automatic hosting charges out of the commercial quote queue', function () {
    [$organization, $organizationAdmin] = quoteOrganizationAdministrator('Separated Hosting Tenant');
    $superAdmin = quoteSuperAdministrator();
    $commercialQuote = quoteIssuedCommercialQuote($organization, $organizationAdmin, 250000);
    $hostingQuote = CommercialQuote::create([
        'organization_id' => $organization->id,
        'requested_by' => null,
        'quote_number' => 'DFH-'.fake()->unique()->numerify('############'),
        'billing_type' => 'hosting',
        'subscription_months' => 12,
        'amount' => 200000,
        'currency' => 'UGX',
        'requested_scope' => 'Required annual hosting, infrastructure, backups, and service availability.',
        'status' => 'issued',
        'valid_until' => today()->addDays(30),
        'issued_at' => now(),
    ]);

    $this->actingAs($superAdmin)
        ->get(route('commercial-quotes.index'))
        ->assertSuccessful()
        ->assertSee($commercialQuote->quote_number)
        ->assertDontSee($hostingQuote->quote_number)
        ->assertSee('Hosting fees and direct plan assignments are managed separately');
});

it('prevents organization admins from creating quotes for an organization', function () {
    [$organization, $organizationAdmin] = quoteOrganizationAdministrator('Forbidden Direct Quote Tenant');

    $this->from(route('commercial-quotes.index'))
        ->actingAs($organizationAdmin)
        ->post(route('commercial-quotes.organizations.store'), [
            'organization_id' => $organization->id,
            'billing_type' => 'subscription',
            'subscription_months' => 1,
            'requested_scope' => 'A direct quote that an organization administrator must not be allowed to create.',
            'amount' => 200000,
            'currency' => 'UGX',
            'valid_until' => today()->addDays(14)->toDateString(),
        ])
        ->assertRedirect(route('commercial-quotes.index'))
        ->assertSessionHas('warning');

    expect(CommercialQuote::query()->count())->toBe(0);
});

it('creates a signed Nylon Pay invoice and activates the organization only after a verified webhook', function () {
    [$organization, $organizationAdmin] = quoteOrganizationAdministrator('Nylon Payment Tenant');
    $quote = quoteIssuedCommercialQuote($organization, $organizationAdmin, 350000);
    $apiSecret = 'nps_live_test_secret';
    $webhookSecret = 'webhook-secret-for-tests';
    NylonPayCredential::create([
        'profile' => 'default',
        'api_key' => 'npk_live_test_key',
        'api_secret' => $apiSecret,
        'webhook_secret' => $webhookSecret,
        'base_url' => 'https://api.nylonpay.nilesquad.com/api/services',
    ]);
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($apiSecret) {
        $nonce = $request->header('X-Nylon-Nonce')[0];
        $data = [
            '_requestNonce' => $nonce,
            'expiresAt' => now()->addHour()->toIso8601String(),
            'id' => 'invoice_123',
            'status' => 'pending',
            'token' => 'invoice_token',
            'url' => 'https://checkout.nylonpay.nilesquad.com/invoice_token',
        ];
        $data['_responseSignature'] = hash_hmac('sha256', quoteCanonicalJson($data), $apiSecret);

        return Http::response(['status' => true, 'message' => 'Invoice created', 'data' => $data]);
    });

    $this->actingAs($organizationAdmin)
        ->post(route('commercial-quotes.pay', $quote))
        ->assertRedirect('https://checkout.nylonpay.nilesquad.com/invoice_token');

    $quote->refresh();
    expect($quote->status)->toBe('payment_pending')
        ->and($quote->nylon_reference)->toHaveLength(13)
        ->and($organization->fresh()->is_demo)->toBeTrue();

    Http::assertSent(function (Request $request) use ($quote): bool {
        $data = $request->data();
        $payload = $data['payload'];

        return $request->url() === 'https://api.nylonpay.nilesquad.com/api/services'
            && str_starts_with($request->header('X-Nylon-Key')[0], 'npk_')
            && filled($request->header('X-Nylon-Signature')[0])
            && $data['action'] === 'sdk-create-invoice'
            && $payload['reference'] === $quote->nylon_reference
            && $payload['amount'] === 350000;
    });

    $webhook = json_encode([
        'event' => 'success',
        'timestamp' => now()->timestamp,
        'transaction' => [
            'id' => 'nylon_tx_123',
            'reference' => $quote->nylon_reference,
            'status' => 'successful',
            'amount' => 350000,
            'currency' => 'UGX',
        ],
    ], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $webhook, $webhookSecret);

    quotePostRawWebhook($this, $webhook, $signature)
        ->assertSuccessful()
        ->assertJson(['received' => true, 'matched' => true]);
    quotePostRawWebhook($this, $webhook, $signature)->assertSuccessful();

    expect($quote->fresh()->status)->toBe('paid')
        ->and($organization->fresh()->is_demo)->toBeFalse()
        ->and($organization->fresh()->demo_expires_at)->toBeNull()
        ->and(OrganizationSubscription::count())->toBe(1)
        ->and(OrganizationSubscription::firstOrFail()->current_period_ends_at)->not->toBeNull();
});

it('rejects invalid or mismatched Nylon Pay webhooks without activating a demo', function () {
    [$organization, $organizationAdmin] = quoteOrganizationAdministrator('Rejected Webhook Tenant');
    $quote = quoteIssuedCommercialQuote($organization, $organizationAdmin, 200000);
    $quote->update(['status' => 'payment_pending', 'nylon_reference' => 'dfq0000000999']);
    NylonPayCredential::create([
        'profile' => 'default',
        'api_key' => 'npk_live_test_key',
        'api_secret' => 'nps_live_test_secret',
        'webhook_secret' => 'webhook-secret-for-tests',
    ]);
    $payload = json_encode([
        'event' => 'success',
        'timestamp' => now()->timestamp,
        'reference' => $quote->nylon_reference,
        'status' => 'successful',
        'amount' => 100000,
        'currency' => 'UGX',
    ], JSON_THROW_ON_ERROR);

    quotePostRawWebhook($this, $payload, str_repeat('0', 64))->assertUnauthorized();
    quotePostRawWebhook($this, $payload, hash_hmac('sha256', $payload, 'webhook-secret-for-tests'))->assertUnprocessable();

    expect($quote->fresh()->status)->not->toBe('paid')
        ->and($organization->fresh()->is_demo)->toBeTrue()
        ->and(OrganizationSubscription::count())->toBe(0);
});

it('keeps quotes tenant scoped and reserves quote issuance for super admins', function () {
    [$organizationA, $adminA] = quoteOrganizationAdministrator('Scoped Quote Tenant A');
    [$organizationB, $adminB] = quoteOrganizationAdministrator('Scoped Quote Tenant B');
    $quoteA = quoteIssuedCommercialQuote($organizationA, $adminA, 200000);
    $quoteB = quoteIssuedCommercialQuote($organizationB, $adminB, 300000);
    Http::preventStrayRequests();

    $this->actingAs($adminA)
        ->get(route('commercial-quotes.index'))
        ->assertSuccessful()
        ->assertSee($quoteA->quote_number)
        ->assertDontSee($quoteB->quote_number);

    $this->from(route('commercial-quotes.index'))
        ->post(route('commercial-quotes.pay', $quoteB))
        ->assertRedirect(route('commercial-quotes.index'))
        ->assertSessionHas('warning');

    $this->from(route('commercial-quotes.index'))
        ->put(route('commercial-quotes.issue', $quoteA), [
            'billing_type' => 'subscription',
            'subscription_months' => 1,
            'amount' => 400000,
            'currency' => 'UGX',
            'valid_until' => today()->addDays(14)->toDateString(),
        ])
        ->assertRedirect(route('commercial-quotes.index'))
        ->assertSessionHas('warning');

    expect((float) $quoteA->fresh()->amount)->toBe(200000.0)
        ->and($quoteB->fresh()->status)->toBe('issued');
});

it('stores Nylon Pay credentials encrypted and preserves blank secret updates', function () {
    $superAdmin = quoteSuperAdministrator();

    $this->actingAs($superAdmin)
        ->put(route('nylon-pay.settings.update'), [
            'api_key' => 'npk_live_saved_key',
            'api_secret' => 'nps_live_saved_secret',
            'webhook_secret' => 'saved-webhook-secret',
            'base_url' => 'https://api.nylonpay.nilesquad.com/api/services',
        ])
        ->assertRedirect(route('commercial-quotes.index'))
        ->assertSessionHasNoErrors();

    $credential = NylonPayCredential::firstOrFail();
    expect($credential->api_secret)->toBe('nps_live_saved_secret')
        ->and($credential->getRawOriginal('api_secret'))->not->toContain('nps_live_saved_secret');

    $this->actingAs($superAdmin)
        ->put(route('nylon-pay.settings.update'), [
            'api_key' => '',
            'api_secret' => '',
            'webhook_secret' => '',
            'base_url' => 'https://api.nylonpay.nilesquad.com/api/services',
        ])
        ->assertSessionHasNoErrors();

    expect($credential->fresh()->api_key)->toBe('npk_live_saved_key')
        ->and($credential->fresh()->api_secret)->toBe('nps_live_saved_secret')
        ->and($credential->fresh()->webhook_secret)->toBe('saved-webhook-secret');
});

it('restricts an expired paid subscription while keeping renewal quotes accessible', function () {
    [$organization, $organizationAdmin] = quoteOrganizationAdministrator('Expired Subscription Tenant');
    $organization->update(['is_demo' => false, 'demo_started_at' => null, 'demo_expires_at' => null]);
    $quote = quoteIssuedCommercialQuote($organization, $organizationAdmin, 200000);
    $quote->update(['status' => 'paid', 'paid_at' => now()->subMonths(2)]);
    OrganizationSubscription::create([
        'organization_id' => $organization->id,
        'commercial_quote_id' => $quote->id,
        'billing_type' => 'subscription',
        'status' => 'active',
        'amount' => $quote->amount,
        'currency' => 'UGX',
        'provider' => 'nylon-pay',
        'provider_reference' => 'expired-subscription-reference',
        'starts_at' => now()->subMonths(2),
        'current_period_ends_at' => now()->subMonth(),
        'activated_at' => now()->subMonths(2),
    ]);

    $this->actingAs($organizationAdmin)
        ->get(route('dashboard'))
        ->assertRedirect(route('commercial-quotes.index'))
        ->assertSessionHas('warning');

    $this->get(route('commercial-quotes.index'))
        ->assertSuccessful()
        ->assertSee('Request a quote');
});

/** @return array{Organization, User} */
function quoteOrganizationAdministrator(string $name): array
{
    $organization = Organization::create([
        'name' => $name,
        'slug' => fake()->unique()->slug(),
        'status' => 'active',
        'is_demo' => true,
        'demo_started_at' => now(),
        'demo_expires_at' => now()->addDays(14),
    ]);
    $role = Role::firstOrCreate(['slug' => 'organization-admin'], ['organization_id' => null, 'name' => 'Organization Admin']);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    return [$organization, $user];
}

function quoteSuperAdministrator(): User
{
    $role = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);

    return User::factory()->create([
        'organization_id' => null,
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
}

function quoteIssuedCommercialQuote(Organization $organization, User $requester, int $amount): CommercialQuote
{
    return CommercialQuote::create([
        'organization_id' => $organization->id,
        'requested_by' => $requester->id,
        'quote_number' => 'DFQ-'.fake()->unique()->numerify('############'),
        'billing_type' => 'subscription',
        'subscription_months' => 1,
        'amount' => $amount,
        'currency' => 'UGX',
        'requested_scope' => 'Configured commercial quote for secure Nylon Pay testing.',
        'status' => 'issued',
        'valid_until' => today()->addDays(14),
        'issued_at' => now(),
    ]);
}

function quoteCanonicalJson(mixed $value): string
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            $value = array_map('quoteCanonicalJsonValue', $value);
        } else {
            ksort($value, SORT_STRING);
            $value = array_map('quoteCanonicalJsonValue', $value);
        }
    }

    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function quoteCanonicalJsonValue(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (! array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return array_map('quoteCanonicalJsonValue', $value);
}

function quotePostRawWebhook($test, string $payload, string $signature): TestResponse
{
    return $test->call('POST', route('api.v1.webhooks.nylon-pay'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_NYLON_SIGNATURE' => $signature,
    ], $payload);
}
