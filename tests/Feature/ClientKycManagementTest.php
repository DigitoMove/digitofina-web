<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function phaseTwoAdmin(): User
{
    $organization = Organization::create([
        'name' => 'Client KYC Tenant',
        'slug' => fake()->unique()->slug(),
        'status' => 'active',
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => '1001',
        'name' => 'Luweero',
        'is_active' => true,
        'status' => 'active',
    ]);
    app(ModuleService::class)->syncCatalog();

    Module::query()
        ->whereIn('slug', ['clients', 'documents'])
        ->get()
        ->each(fn (Module $module): OrganizationModule => OrganizationModule::firstOrCreate(
            ['organization_id' => $organization->id, 'module_id' => $module->id],
            ['is_enabled' => true, 'enabled_at' => now()],
        ));

    $permissions = collect(['manage-clients', 'verify-kyc'])->map(
        fn (string $slug): Permission => Permission::create(['organization_id' => $organization->id, 'name' => str($slug)->headline(), 'slug' => $slug])
    );

    $role = Role::create(['organization_id' => $organization->id, 'name' => 'Administrator', 'slug' => 'administrator']);
    $role->permissions()->sync($permissions->pluck('id')->all());

    return User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
}

it('registers a client with kyc details', function () {
    $admin = phaseTwoAdmin();

    expect($admin->canPerform('manage-clients'))->toBeTrue();

    $response = $this->actingAs($admin)->post(route('clients.store'), [
        'branch_id' => $admin->branch_id,
        'client_type' => 'individual',
        'first_name' => 'Grace',
        'last_name' => 'Nabukeera',
        'gender' => 'female',
        'date_of_birth' => today()->subYears(28)->toDateString(),
        'phone' => '+256700111222',
        'national_id' => 'CM12345678',
        'kyc_status' => 'pending',
        'risk_rating' => 'medium',
        'status' => 'active',
        'kyc_checklist' => ['identity_confirmed', 'next_of_kin_confirmed'],
    ]);

    $client = Client::firstWhere('national_id', 'CM12345678');

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();
    expect($client)->not->toBeNull();
    $response->assertRedirect(route('clients.show', $client));
    expect($client->client_number)->toBe('1001-C000001')
        ->and($client->kyc_documents['identity_confirmed'])->toBeTrue()
        ->and($client->kyc_documents['address_confirmed'])->toBeFalse();
});

it('requires individual clients to have nin date of birth and gender', function () {
    $admin = phaseTwoAdmin();

    $this->actingAs($admin)
        ->from(route('clients.create'))
        ->post(route('clients.store'), [
            'branch_id' => $admin->branch_id,
            'client_type' => 'individual',
            'first_name' => 'Grace',
            'last_name' => 'Nabukeera',
            'phone' => '+256700111223',
            'kyc_status' => 'pending',
            'status' => 'active',
        ])
        ->assertRedirect(route('clients.create'))
        ->assertSessionHasErrors(['gender', 'date_of_birth', 'national_id']);

    expect(Client::query()->count())->toBe(0);
});

it('stores client documents on the private local disk', function () {
    Storage::fake('local');
    $admin = phaseTwoAdmin();
    $client = Client::create([
        'organization_id' => $admin->organization_id,
        'branch_id' => $admin->branch_id,
        'officer_id' => $admin->id,
        'client_number' => '1001-C000001',
        'client_type' => 'individual',
        'first_name' => 'Amina',
        'last_name' => 'Nakato',
        'phone' => '+256701111222',
        'kyc_status' => 'pending',
        'status' => 'active',
    ]);

    $response = $this->actingAs($admin)->post(route('clients.documents.store', $client), [
        'type' => 'national_id_front',
        'title' => 'National ID Front',
        'document' => UploadedFile::fake()->image('nin-front.jpg'),
    ]);

    $document = $client->documents()->first();

    $response->assertRedirect(route('clients.show', $client));
    expect($document)->not->toBeNull()
        ->and($document->verification_status)->toBe('pending');
    Storage::disk('local')->assertExists($document->path);
});
