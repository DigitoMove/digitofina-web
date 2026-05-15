<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function importUser(): User
{
    $branch = Branch::create(['code' => '1001', 'name' => 'Luweero', 'is_active' => true, 'status' => 'active']);
    $permission = Permission::create(['name' => 'Import Data', 'slug' => 'import-data']);
    $role = Role::create(['name' => 'Data Clerk', 'slug' => fake()->unique()->slug(2)]);
    $role->permissions()->sync([$permission->id]);

    return User::factory()->create([
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
}

function csvUpload(string $name, string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'digitofina-import-');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

it('previews validates and commits client imports', function () {
    $user = importUser();
    $csv = "client_number,first_name,last_name,phone,branch_code,status,kyc_status\n1001-C900001,Grace,Nakato,+256700111222,1001,active,verified\n";

    $response = $this->actingAs($user)->post(route('imports.store'), [
        'type' => 'clients',
        'file' => csvUpload('clients.csv', $csv),
    ]);

    $batch = ImportBatch::first();

    $response->assertRedirect(route('imports.show', $batch));
    expect($batch->total_rows)->toBe(1)
        ->and($batch->valid_rows)->toBe(1)
        ->and($batch->invalid_rows)->toBe(0);

    $this->actingAs($user)->post(route('imports.commit', $batch))->assertSessionHasNoErrors();

    expect(Client::where('client_number', '1001-C900001')->exists())->toBeTrue()
        ->and($batch->fresh()->status)->toBe('imported');
});

it('shows validation errors before saving invalid rows', function () {
    $user = importUser();
    $csv = "client_number,first_name,last_name,phone,branch_code\n1001-C900002,Grace,Nakato,,1001\n";

    $this->actingAs($user)->post(route('imports.store'), [
        'type' => 'clients',
        'file' => csvUpload('clients.csv', $csv),
    ])->assertRedirect();

    $row = ImportBatch::first()->rows()->first();

    expect($row->status)->toBe('invalid')
        ->and($row->validation_errors)->toHaveKey('phone')
        ->and(Client::where('client_number', '1001-C900002')->exists())->toBeFalse();
});

it('rolls back safe opening balance imports', function () {
    $user = importUser();
    $client = Client::create([
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => '1001-C900003',
        'client_type' => 'individual',
        'first_name' => 'Amina',
        'last_name' => 'Nakato',
        'phone' => '+256701111222',
        'status' => 'active',
        'kyc_status' => 'verified',
    ]);
    $product = SavingsProduct::create([
        'name' => 'Regular Savings',
        'code' => 'SAV-REG',
        'status' => 'active',
        'minimum_opening_balance' => 0,
        'minimum_balance' => 0,
    ]);
    $account = SavingsAccount::create([
        'branch_id' => $user->branch_id,
        'client_id' => $client->id,
        'savings_product_id' => $product->id,
        'created_by' => $user->id,
        'account_number' => '1001-SAV-900003',
        'opening_date' => today(),
        'current_balance' => 0,
        'available_balance' => 0,
        'status' => 'active',
    ]);

    $csv = "account_number,balance,transaction_date,narration\n1001-SAV-900003,250000,".today()->toDateString().",Legacy balance\n";

    $this->actingAs($user)->post(route('imports.store'), [
        'type' => 'opening_balances',
        'file' => csvUpload('opening-balances.csv', $csv),
    ])->assertRedirect();

    $batch = ImportBatch::first();

    $this->actingAs($user)->post(route('imports.commit', $batch))->assertSessionHasNoErrors();
    expect((float) $account->fresh()->current_balance)->toBe(250000.0)
        ->and(SavingsTransaction::where('type', 'opening_balance')->where('status', 'successful')->exists())->toBeTrue();

    $this->actingAs($user)->post(route('imports.rollback', $batch->fresh()))->assertSessionHasNoErrors();

    expect((float) $account->fresh()->current_balance)->toBe(0.0)
        ->and(SavingsTransaction::where('type', 'opening_balance')->where('status', 'reversed')->exists())->toBeTrue()
        ->and($batch->fresh()->status)->toBe('rolled_back');
});
