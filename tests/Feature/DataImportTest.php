<?php

use App\Models\Account;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\DataImportService;
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

function importLoanProduct(): LoanProduct
{
    return LoanProduct::create([
        'name' => 'Weekly Loan',
        'code' => 'WEEKLY20',
        'min_amount' => 10_000,
        'max_amount' => 5_000_000,
        'min_term' => 1,
        'max_term' => 52,
        'term_unit' => 'weeks',
        'annual_interest_rate' => 20,
        'interest_method' => 'declining_balance',
        'repayment_frequency' => 'weekly',
        'default_term_months' => 1,
        'required_savings_percentage' => 0,
        'status' => 'active',
        'is_active' => true,
    ]);
}

function importService(): DataImportService
{
    return app(DataImportService::class);
}

it('previews validates and commits client imports', function () {
    $user = importUser();
    importLoanProduct();
    $csv = "client_number,first_name,last_name,phone,branch_code,status,kyc_status,loan_amount,loan_code,installments\n1001-C900001,Grace,Nakato,+256700111222,1001,active,verified,500000,WEEKLY20,4\n";

    $batch = importService()->createBatch($user, csvUpload('clients.csv', $csv), 'clients');

    expect($batch->total_rows)->toBe(1)
        ->and($batch->valid_rows)->toBe(1)
        ->and($batch->invalid_rows)->toBe(0);

    importService()->commit($batch, $user);

    expect(Client::where('client_number', '1001-C900001')->exists())->toBeTrue()
        ->and(Loan::where('client_id', Client::where('client_number', '1001-C900001')->value('id'))->where('status', 'draft')->exists())->toBeTrue()
        ->and($batch->fresh()->status)->toBe('imported');
});

it('shows validation errors before saving invalid rows', function () {
    $user = importUser();
    importLoanProduct();
    $csv = "client_number,first_name,last_name,phone,branch_code,loan_amount,loan_code\n1001-C900002,Grace,Nakato,,1001,500000,WEEKLY20\n";

    $batch = importService()->createBatch($user, csvUpload('clients.csv', $csv), 'clients');
    $row = $batch->rows()->first();

    expect($row->status)->toBe('invalid')
        ->and($row->validation_errors)->toHaveKey('phone')
        ->and(Client::where('client_number', '1001-C900002')->exists())->toBeFalse();
});

it('creates a draft loan for an existing imported client number', function () {
    $user = importUser();
    importLoanProduct();
    $client = Client::create([
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => '1001-C900004',
        'client_type' => 'individual',
        'first_name' => 'Existing',
        'last_name' => 'Client',
        'phone' => '+256701111224',
        'status' => 'active',
        'kyc_status' => 'verified',
    ]);
    $csv = "client_number,branch_code,loan_amount,loan_code,installments\n1001-C900004,1001,750000,WEEKLY20,6\n";

    $batch = importService()->createBatch($user, csvUpload('clients.csv', $csv), 'clients');

    expect($batch->invalid_rows)->toBe(0);

    importService()->commit($batch, $user);

    expect(Client::where('client_number', '1001-C900004')->count())->toBe(1)
        ->and(Loan::where('client_id', $client->id)->where('status', 'draft')->where('requested_amount', 750000)->exists())->toBeTrue();
});

it('reimports existing clients without duplicating savings accounts or same-date loans', function () {
    $user = importUser();
    $product = importLoanProduct();
    $client = Client::create([
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => '1001-C900014',
        'client_type' => 'individual',
        'first_name' => 'Repeat',
        'last_name' => 'Client',
        'phone' => '+256701111234',
        'status' => 'active',
        'kyc_status' => 'verified',
    ]);
    Loan::create([
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'created_by' => $user->id,
        'loan_number' => '1001-LN-900014',
        'requested_amount' => 500000,
        'approved_amount' => null,
        'principal_balance' => 0,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 20,
        'term_periods' => 4,
        'repayment_frequency' => 'weekly',
        'application_date' => '2026-02-01',
        'status' => 'draft',
    ]);

    $sameDateCsv = "client_number,branch_code,loan_application_date,loan_amount,loan_code,installments\n1001-C900014,1001,2026-02-01,750000,WEEKLY20,6\n";
    $sameDateBatch = importService()->createBatch($user, csvUpload('clients.csv', $sameDateCsv), 'clients');
    importService()->commit($sameDateBatch, $user);

    expect(Account::where('client_id', $client->id)->whereHas('accountType', fn ($query) => $query->where('category', 'savings'))->count())->toBe(1)
        ->and(Loan::where('client_id', $client->id)->count())->toBe(1);

    $differentDateCsv = "client_number,branch_code,loan_application_date,loan_amount,loan_code,installments\n1001-C900014,1001,2026-03-01,750000,WEEKLY20,6\n";
    $differentDateBatch = importService()->createBatch($user, csvUpload('clients.csv', $differentDateCsv), 'clients');
    importService()->commit($differentDateBatch, $user);

    expect(Account::where('client_id', $client->id)->whereHas('accountType', fn ($query) => $query->where('category', 'savings'))->count())->toBe(1)
        ->and(Loan::where('client_id', $client->id)->count())->toBe(2)
        ->and(Loan::where('client_id', $client->id)->whereDate('application_date', '2026-03-01')->where('status', 'draft')->exists())->toBeTrue();
});

it('generates missing client numbers during client import', function () {
    $user = importUser();
    importLoanProduct();
    Client::create([
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => '1001-C000001',
        'client_type' => 'individual',
        'first_name' => 'Existing',
        'last_name' => 'Sequence',
        'phone' => '+256701111225',
        'status' => 'active',
    ]);
    $csv = "first_name,last_name,phone,branch_code,loan_amount,loan_code,installments\nGenerated,Client,+256701111226,1001,250000,WEEKLY20,4\n";

    $batch = importService()->createBatch($user, csvUpload('clients.csv', $csv), 'clients');
    $generatedNumber = $batch->rows()->firstOrFail()->normalized_data['client_number'];

    expect($generatedNumber)->toBe('1001-C000002');

    importService()->commit($batch, $user);

    expect(Client::where('client_number', '1001-C000002')->exists())->toBeTrue();
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

    $batch = importService()->createBatch($user, csvUpload('opening-balances.csv', $csv), 'opening_balances');

    importService()->commit($batch, $user);
    expect((float) $account->fresh()->current_balance)->toBe(250000.0)
        ->and(SavingsTransaction::where('type', 'opening_balance')->where('status', 'successful')->exists())->toBeTrue();

    importService()->rollback($batch->fresh(), $user);

    expect((float) $account->fresh()->current_balance)->toBe(0.0)
        ->and(SavingsTransaction::where('type', 'opening_balance')->where('status', 'reversed')->exists())->toBeTrue()
        ->and($batch->fresh()->status)->toBe('rolled_back');
});
