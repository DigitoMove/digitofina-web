<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function phaseTwoAdmin(): User
{
    $branch = Branch::create([
        'code' => '1001',
        'name' => 'Luweero',
        'is_active' => true,
        'status' => 'active',
    ]);

    $permissions = collect(['manage-clients', 'verify-kyc'])->map(
        fn (string $slug): Permission => Permission::create(['name' => str($slug)->headline(), 'slug' => $slug])
    );

    $role = Role::create(['name' => 'Administrator', 'slug' => 'administrator']);
    $role->permissions()->sync($permissions->pluck('id')->all());

    return User::factory()->create([
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

it('stores client documents on the private local disk', function () {
    Storage::fake('local');
    $admin = phaseTwoAdmin();
    $client = Client::create([
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
