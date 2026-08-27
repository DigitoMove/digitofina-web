<?php

use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows both public pricing models with server-defined amounts', function () {
    $this->get('https://digitofina.digitomove.com/pricing')
        ->assertSuccessful()
        ->assertSee('Pay monthly')
        ->assertSee('UGX 200,000')
        ->assertSee('UGX 350,000')
        ->assertSee('One-time licence')
        ->assertSee('UGX 1,500,000')
        ->assertSee('UGX 3,000,000');
});

it('hides pricing from registered organization domains', function () {
    $organization = Organization::create([
        'name' => 'Private Tenant',
        'slug' => 'private-tenant',
        'status' => 'active',
    ]);
    OrganizationDomain::create([
        'organization_id' => $organization->id,
        'domain' => 'finance.private.test',
        'type' => 'custom_domain',
        'status' => 'active',
    ]);

    $this->get('https://finance.private.test/pricing')
        ->assertRedirect(route('login'));
});

it('hides pricing from exact and wildcard platform host rules', function () {
    SystemSetting::withoutGlobalScope('tenant')->create([
        'organization_id' => null,
        'key' => 'demo_hidden_hosts',
        'label' => 'Hidden marketing hosts',
        'value' => json_encode(['private.example.test', '*.internal.test']),
        'type' => 'json',
        'group' => 'platform',
    ]);

    $this->get('https://private.example.test/pricing')->assertRedirect(route('login'));
    $this->get('https://branch.internal.test/pricing')->assertRedirect(route('login'));
    $this->get('https://public.example.test/pricing')->assertSuccessful();
});
