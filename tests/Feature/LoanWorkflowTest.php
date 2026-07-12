<?php

use App\Jobs\GenerateReportExport;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\Guarantor;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanPenalty;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\LoanSchedule;
use App\Models\LoanWriteOff;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\ReportExport;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TellerDrawer;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\BusinessCalendarService;
use App\Services\LoanApplicationService;
use App\Services\LoanService;
use App\Services\ModuleService;
use App\Services\ReportCatalogService;
use App\Services\TransactionPostingService;
use App\Support\CurrencyDenomination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function loanWorkflowUser(): User
{
    $branch = Branch::create(['code' => '1001', 'name' => 'Luweero', 'is_active' => true, 'status' => 'active']);
    $permissions = collect(['manage-loans', 'approve-loans', 'disburse-loans', 'post-repayments', 'view-reports'])
        ->map(fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => str($slug)->headline()]));
    $role = Role::create(['name' => 'Branch Manager', 'slug' => 'branch-manager']);
    $role->permissions()->sync($permissions->pluck('id')->all());

    foreach ([
        ['1000', 'Cash on Hand', 'asset', 'debit'],
        ['1200', 'Loans Receivable', 'asset', 'debit'],
        ['2100', 'Client Savings Liability', 'liability', 'credit'],
        ['4100', 'Interest Income', 'income', 'credit'],
        ['4200', 'Fees and Penalties Income', 'income', 'credit'],
    ] as [$code, $name, $type, $normalBalance]) {
        ChartAccount::create(['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normalBalance, 'is_active' => true]);
    }

    TransactionType::create(['name' => 'Loan Disbursement', 'code' => 'loan_disbursement', 'direction' => 'credit']);
    TransactionType::create(['name' => 'Loan Repayment', 'code' => 'loan_repayment', 'direction' => 'credit']);
    AccountType::create(['name' => 'Savings Account', 'code' => 'SAV', 'category' => 'savings']);
    AccountType::create(['name' => 'Loan Account', 'code' => 'LON', 'category' => 'loan']);

    return User::factory()->create(['branch_id' => $branch->id, 'role_id' => $role->id, 'status' => 'active', 'is_active' => true]);
}

function loanWorkflowClient(User $user, string $phone = '+256700000001'): Client
{
    return Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'gender' => 'female',
        'date_of_birth' => today()->subYears(30),
        'national_id' => fake()->unique()->bothify('CM##########??'),
        'phone' => $phone,
        'kyc_status' => 'verified',
        'status' => 'active',
    ]);
}

function loanWorkflowProduct(array $overrides = []): LoanProduct
{
    return LoanProduct::create($overrides + [
        'name' => 'Micro Business Loan',
        'code' => fake()->unique()->bothify('MICRO-??'),
        'min_amount' => 100_000,
        'max_amount' => 5_000_000,
        'min_term' => 1,
        'max_term' => 24,
        'term_unit' => 'months',
        'annual_interest_rate' => 24,
        'interest_method' => 'declining_balance',
        'interest_frequency' => 'monthly',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 12,
        'grace_period_days' => 0,
        'penalty_type' => 'percentage',
        'penalty_rate' => 2,
        'processing_fee' => 25_000,
        'insurance_fee' => 0,
        'application_fee' => 0,
        'required_savings_percentage' => 10,
        'require_guarantors' => true,
        'minimum_guarantors' => 1,
        'require_collateral' => false,
        'auto_deduct_repayment_from_savings' => false,
        'loan_account_number_prefix' => 'LN',
        'status' => 'active',
        'is_active' => true,
    ]);
}

function loanWorkflowSavingsAccount(User $user, Client $client): Account
{
    return Account::create([
        'organization_id' => $user->organization_id,
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'account_type_id' => AccountType::where('category', 'savings')->value('id'),
        'account_number' => '1001-SAV-'.fake()->unique()->numerify('#######'),
        'account_name' => $client->full_name.' Savings',
        'status' => 'active',
        'balance' => 250_000,
        'opened_at' => today(),
    ]);
}

function loanWorkflowOrganizationWithModules(User $user, array $moduleSlugs): Organization
{
    $organization = Organization::create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
        'status' => 'active',
    ]);

    Branch::whereKey($user->branch_id)->update(['organization_id' => $organization->id]);
    ChartAccount::whereNull('organization_id')->update(['organization_id' => $organization->id]);
    $user->update(['organization_id' => $organization->id]);

    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($organization);

    foreach ($moduleSlugs as $moduleSlug) {
        $modules->enable($organization, Module::where('slug', $moduleSlug)->firstOrFail(), $user);
    }

    return $organization;
}

function loanWorkflowTrackedLoan(User $officer, Client $client, LoanProduct $product, string $loanNumber, string $status): Loan
{
    return Loan::create([
        'organization_id' => $officer->organization_id,
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $officer->id,
        'created_by' => $officer->id,
        'loan_number' => $loanNumber,
        'requested_amount' => 300000,
        'approved_amount' => 300000,
        'principal_balance' => 300000,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 20,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'application_date' => today()->subMonth(),
        'status' => $status,
    ]);
}

function loanWorkflowXlsxPart(string $content, string $part): string
{
    $path = tempnam(sys_get_temp_dir(), 'loan-export-xlsx');
    file_put_contents($path, $content);

    $zip = new ZipArchive;
    $zip->open($path);

    try {
        return $zip->getFromName($part) ?: '';
    } finally {
        $zip->close();
        @unlink($path);
    }
}

function loanWorkflowSchedule(Loan $loan, mixed $dueDate, string $status, float $outstandingAmount = 300000): LoanSchedule
{
    return LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => $dueDate,
        'principal_due' => 300000,
        'interest_due' => 0,
        'total_due' => 300000,
        'outstanding_amount' => $outstandingAmount,
        'balance_due' => $outstandingAmount,
        'status' => $status,
        'days_overdue' => method_exists($dueDate, 'lt') && $dueDate->lt(today()) ? 2 : 0,
    ]);
}

it('approves and disburses a loan with guarantor checks and a repayment schedule', function () {
    $user = loanWorkflowUser();
    $organization = loanWorkflowOrganizationWithModules($user, ['clients', 'loans', 'savings']);
    $client = loanWorkflowClient($user);
    $guarantorClient = loanWorkflowClient($user, '+256700000002');
    $product = loanWorkflowProduct();
    $product->update(['organization_id' => $organization->id]);
    loanWorkflowSavingsAccount($user, $client);

    $response = $this->actingAs($user)->post(route('loans.store'), [
        'client_id' => $client->id,
        'loan_product_id' => $product->id,
        'requested_amount' => 1_000_000,
        'term_periods' => 6,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Stock purchase',
        'source_of_repayment' => 'Business sales',
    ]);

    $loan = Loan::first();
    $response->assertRedirect(route('loans.show', $loan));
    expect($loan->status)->toBe('draft');

    $this->actingAs($user)
        ->post(route('loans.generate-loan-account', $loan))
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('loans.submit-appraisal', $loan))
        ->assertRedirect();

    $this->actingAs($user)->post(route('loans.guarantors.store', $loan), [
        'type' => 'existing_client',
        'guarantor_client_id' => $guarantorClient->id,
        'name' => $guarantorClient->full_name,
        'phone' => '+256700000002',
        'address' => 'Kampala Road',
        'guaranteed_amount' => 1_000_000,
        'status' => 'accepted',
    ])->assertRedirect();

    $this->actingAs($user)
        ->post(route('loans.submit-approval', $loan))
        ->assertRedirect();

    expect($loan->fresh()->status)->toBe('pending_guarantor_approval');

    $this->actingAs($user)->post(route('loans.approve', $loan), [
        'approved_amount' => 1_000_000,
        'comments' => 'Credit committee approved.',
    ])->assertRedirect();

    $this->actingAs($user)->post(route('loans.disburse', $loan), [
        'payment_method' => 'transfer_to_savings',
    ])->assertRedirect();

    $loan->refresh();
    $expectedFirstDueDate = app(BusinessCalendarService::class)->nextBusinessDay(today()->addMonthsNoOverflow())->toDateString();

    expect($loan->status)->toBe('active')
        ->and($loan->schedules)->toHaveCount(6)
        ->and($loan->schedules->first()->due_date?->toDateString())->toBe($expectedFirstDueDate)
        ->and($loan->schedules->first()->due_date?->toDateString())->not->toBe(today()->toDateString())
        ->and($loan->disbursements)->toHaveCount(1)
        ->and((float) $loan->interest_balance)->toBeGreaterThan(0.0);
});

it('copies guarantors from the client most recent loan with guarantors', function () {
    $user = loanWorkflowUser();
    $client = loanWorkflowClient($user);
    $oldGuarantorClient = loanWorkflowClient($user, '+256700000010');
    $recentGuarantorClient = loanWorkflowClient($user, '+256700000011');
    $product = loanWorkflowProduct();
    loanWorkflowSavingsAccount($user, $client);

    $olderLoan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-OLD-GUA',
        'requested_amount' => 400_000,
        'principal_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 3,
        'repayment_frequency' => 'monthly',
        'application_date' => today()->subMonths(3),
        'status' => 'closed',
    ]);
    Guarantor::create([
        'loan_id' => $olderLoan->id,
        'guarantor_client_id' => $oldGuarantorClient->id,
        'type' => 'existing_client',
        'name' => $oldGuarantorClient->full_name,
        'phone' => '+256700000010',
        'address' => 'Old market lane',
        'guaranteed_amount' => 400_000,
        'status' => 'accepted',
    ]);

    $recentLoan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-NEW-GUA',
        'requested_amount' => 600_000,
        'principal_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 4,
        'repayment_frequency' => 'monthly',
        'application_date' => today()->subMonth(),
        'status' => 'active',
    ]);
    Guarantor::create([
        'loan_id' => $recentLoan->id,
        'guarantor_client_id' => $recentGuarantorClient->id,
        'type' => 'existing_client',
        'name' => $recentGuarantorClient->full_name,
        'phone' => '+256700000011',
        'address' => 'Recent guarantor village',
        'relationship' => 'Business partner',
        'guaranteed_amount' => 600_000,
        'status' => 'accepted',
    ]);

    $loan = app(LoanApplicationService::class)->submit([
        'client_id' => $client->id,
        'loan_product_id' => $product->id,
        'requested_amount' => 800_000,
        'term_periods' => 6,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Restocking',
        'source_of_repayment' => 'Business sales',
    ], $user);

    $guarantor = $loan->loanGuarantors()->firstOrFail();

    expect($loan->loanGuarantors()->count())->toBe(1)
        ->and($guarantor->guarantor_client_id)->toBe($recentGuarantorClient->id)
        ->and($guarantor->name)->toBe($recentGuarantorClient->full_name)
        ->and($guarantor->phone)->toBe('+256700000011')
        ->and($guarantor->address)->toBe('Recent guarantor village')
        ->and($guarantor->relationship)->toBe('Business partner')
        ->and((float) $guarantor->guaranteed_amount)->toBe(800_000.0)
        ->and($guarantor->status)->toBe('accepted');
});

it('uses the loan amount for manually attached external guarantors and approved guarantor coverage', function () {
    $user = loanWorkflowUser();
    $user->role->update(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct();
    loanWorkflowSavingsAccount($user, $client);

    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-GUA-AMT',
        'requested_amount' => 1_000_000,
        'principal_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 6,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Stock purchase',
        'status' => 'submitted',
    ]);

    $this->actingAs($user)->post(route('loans.guarantors.store', $loan), [
        'type' => 'external',
        'name' => 'Sarah External',
        'phone' => '+256700000012',
        'address' => 'Kasangati trading centre',
        'guaranteed_amount' => 50_000,
        'status' => 'accepted',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $guarantor = $loan->loanGuarantors()->firstOrFail();

    expect($guarantor->guarantor_client_id)->toBeNull()
        ->and($guarantor->name)->toBe('Sarah External')
        ->and($guarantor->phone)->toBe('+256700000012')
        ->and($guarantor->address)->toBe('Kasangati trading centre')
        ->and((float) $guarantor->guaranteed_amount)->toBe(1_000_000.0);

    app(LoanService::class)->approve($loan, $user, 850_000, 'Approved lower than requested.');

    expect((float) $guarantor->fresh()->guaranteed_amount)->toBe(850_000.0);
});

it('leaves a new loan without guarantors when the client has no prior loan guarantors', function () {
    $user = loanWorkflowUser();
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct();
    loanWorkflowSavingsAccount($user, $client);

    Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-NO-GUA',
        'requested_amount' => 400_000,
        'principal_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 3,
        'repayment_frequency' => 'monthly',
        'application_date' => today()->subMonth(),
        'status' => 'closed',
    ]);

    $loan = app(LoanApplicationService::class)->submit([
        'client_id' => $client->id,
        'loan_product_id' => $product->id,
        'requested_amount' => 800_000,
        'term_periods' => 6,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Restocking',
        'source_of_repayment' => 'Business sales',
    ], $user);

    expect($loan->loanGuarantors()->exists())->toBeFalse();
});

it('creates monthly loan schedules one calendar month after the application date', function () {
    $user = loanWorkflowUser();
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    loanWorkflowSavingsAccount($user, $client);
    $applicationDate = today();

    $loan = app(LoanApplicationService::class)->submit([
        'client_id' => $client->id,
        'loan_product_id' => $product->id,
        'requested_amount' => 500_000,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'application_date' => $applicationDate->toDateString(),
        'purpose' => 'Monthly schedule check',
    ], $user);

    $expectedDueDate = app(BusinessCalendarService::class)
        ->nextBusinessDay($applicationDate->copy()->addMonthsNoOverflow())
        ->toDateString();

    expect($loan->schedules()->firstOrFail()->due_date?->toDateString())->toBe($expectedDueDate)
        ->and($loan->schedules()->firstOrFail()->due_date?->toDateString())->not->toBe($applicationDate->toDateString());
});

it('lets an admin appraise approve and disburse a loan in one shortcut', function () {
    $user = loanWorkflowUser();
    $user->role->update(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);

    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-FAST001',
        'requested_amount' => 700_000,
        'principal_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 4,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Fast stock purchase',
        'status' => 'draft',
    ]);
    Guarantor::create([
        'loan_id' => $loan->id,
        'type' => 'external',
        'name' => 'Fast Track Guarantor',
        'phone' => '+256700000003',
        'guaranteed_amount' => 700_000,
        'status' => 'accepted',
    ]);

    $this->actingAs($user)
        ->get(route('loans.show', $loan))
        ->assertSuccessful()
        ->assertSee('Appraise, Approve & Disburse', false);

    $this->actingAs($user)
        ->from(route('loans.show', $loan))
        ->post(route('loans.fast-track-disbursement', $loan), [
            'approved_amount' => 700_000,
            'payment_method' => 'transfer_to_savings',
            'comments' => 'Approved through the admin shortcut.',
        ])
        ->assertRedirect(route('loans.show', $loan))
        ->assertSessionHas('status', 'Loan appraised, approved, and disbursed.');

    $loan->refresh();

    expect($loan->status)->toBe('active')
        ->and($loan->savings_account_id)->not->toBeNull()
        ->and($loan->loan_account_id)->not->toBeNull()
        ->and((float) $loan->approved_amount)->toBe(700000.0)
        ->and($loan->schedules)->toHaveCount(4)
        ->and($loan->disbursements)->toHaveCount(1);
});

it('charges the disbursement application fee from savings when savings can cover it', function () {
    $user = loanWorkflowUser();
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $savings = loanWorkflowSavingsAccount($user, $client);

    SystemSetting::withoutGlobalScope('tenant')->create([
        'key' => 'loan_application_fee_ranges',
        'label' => 'Loan application fee ranges',
        'value' => json_encode([['min' => 1, 'max' => null, 'fee' => 15_000]]),
        'type' => 'json',
        'group' => 'loans',
    ]);

    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'savings_account_id' => $savings->id,
        'loan_number' => '1001-LN-FEE-SAV',
        'requested_amount' => 500_000,
        'approved_amount' => 500_000,
        'principal_balance' => 500_000,
        'interest_rate' => 24,
        'term_periods' => 2,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Stock purchase',
        'status' => 'approved',
        'approved_by' => $user->id,
        'approved_at' => today(),
    ]);

    app(LoanService::class)->disburseToSavings($loan, $user);

    $disbursement = LoanDisbursement::firstOrFail();
    $feeTransaction = Transaction::where('description', 'like', '%paid from savings%')->firstOrFail();
    $report = app(ReportCatalogService::class)->generate('loan-disbursements', [
        'from' => today()->toDateString(),
        'to' => today()->toDateString(),
        'branch_id' => $user->branch_id,
    ], $user);

    expect((float) $savings->fresh()->balance)->toBe(735_000.0)
        ->and((float) $disbursement->disbursed_amount)->toBe(500_000.0)
        ->and((float) $disbursement->fees_deducted)->toBe(15_000.0)
        ->and((float) $disbursement->net_amount)->toBe(500_000.0)
        ->and((float) $feeTransaction->amount)->toBe(15_000.0)
        ->and((float) $feeTransaction->balance_after)->toBe(235_000.0)
        ->and($report['summary']['Gross principal disbursed'])->toBe('UGX 500,000.00')
        ->and($report['summary']['Application fees charged'])->toBe('UGX 15,000.00')
        ->and($report['summary']['Net credited to savings'])->toBe('UGX 500,000.00');
});

it('deducts the disbursement application fee from proceeds when savings cannot cover it', function () {
    $user = loanWorkflowUser();
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $savings = loanWorkflowSavingsAccount($user, $client);
    $savings->update(['balance' => 10_000]);

    SystemSetting::withoutGlobalScope('tenant')->create([
        'key' => 'loan_application_fee_ranges',
        'label' => 'Loan application fee ranges',
        'value' => json_encode([['min' => 1, 'max' => null, 'fee' => 15_000]]),
        'type' => 'json',
        'group' => 'loans',
    ]);

    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'savings_account_id' => $savings->id,
        'loan_number' => '1001-LN-FEE-PRO',
        'requested_amount' => 500_000,
        'approved_amount' => 500_000,
        'principal_balance' => 500_000,
        'interest_rate' => 24,
        'term_periods' => 2,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Stock purchase',
        'status' => 'approved',
        'approved_by' => $user->id,
        'approved_at' => today(),
    ]);

    app(LoanService::class)->disburseToSavings($loan, $user);

    $disbursement = LoanDisbursement::firstOrFail();
    $report = app(ReportCatalogService::class)->generate('loan-disbursements', [
        'from' => today()->toDateString(),
        'to' => today()->toDateString(),
        'branch_id' => $user->branch_id,
    ], $user);

    expect((float) $savings->fresh()->balance)->toBe(495_000.0)
        ->and((float) $disbursement->disbursed_amount)->toBe(500_000.0)
        ->and((float) $disbursement->fees_deducted)->toBe(15_000.0)
        ->and((float) $disbursement->net_amount)->toBe(485_000.0)
        ->and(Transaction::where('description', 'like', '%paid from savings%')->exists())->toBeFalse()
        ->and($report['summary']['Gross principal disbursed'])->toBe('UGX 500,000.00')
        ->and($report['summary']['Application fees charged'])->toBe('UGX 15,000.00')
        ->and($report['summary']['Net credited to savings'])->toBe('UGX 485,000.00');
});

it('allocates repayments and releases loan guarantees when fully paid', function () {
    $user = loanWorkflowUser();
    $tellerRole = Role::create(['name' => 'Teller Cashier', 'slug' => 'teller-cashier']);
    $tellerRole->permissions()->sync(Permission::where('slug', 'post-repayments')->pluck('id')->all());
    $teller = User::factory()->create([
        'branch_id' => $user->branch_id,
        'role_id' => $tellerRole->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $client = loanWorkflowClient($user);
    $guarantorClient = loanWorkflowClient($user, '+256700000003');
    $product = loanWorkflowProduct();
    loanWorkflowSavingsAccount($user, $client);

    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-0000001',
        'requested_amount' => 600_000,
        'approved_amount' => 600_000,
        'principal_balance' => 600_000,
        'interest_rate' => 0,
        'term_periods' => 3,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Working capital',
        'status' => 'approved',
        'approved_by' => $user->id,
        'approved_at' => today(),
    ]);
    Guarantor::create(['loan_id' => $loan->id, 'guarantor_client_id' => $guarantorClient->id, 'name' => $guarantorClient->full_name, 'guaranteed_amount' => 600_000, 'status' => 'accepted']);

    app(LoanService::class)->disburseToSavings($loan, $user);
    $loan->refresh();
    $amountDue = (float) $loan->principal_balance + (float) $loan->interest_balance;
    expect($loan->loanAccount->accountType->category)->toBe('loan');
    $openingBalance = 100_000.0;
    $drawer = TellerDrawer::create([
        'branch_id' => $user->branch_id,
        'assigned_user_id' => $teller->id,
        'name' => 'Main Cash Drawer',
        'code' => 'TDR-001',
        'current_balance' => $openingBalance,
        'status' => 'open',
    ]);
    DrawerSession::create([
        'teller_drawer_id' => $drawer->id,
        'user_id' => $teller->id,
        'opening_balance' => $openingBalance,
        'status' => 'open',
    ]);

    app(TransactionPostingService::class)->postCash(
        $loan->loanAccount,
        DrawerSession::where('user_id', $teller->id)->firstOrFail(),
        TransactionType::where('code', 'loan_repayment')->firstOrFail(),
        $amountDue,
        $teller,
        'Full settlement',
        null,
        today(),
    );

    $loan->refresh();
    $repayment = LoanRepayment::firstOrFail();
    $transaction = Transaction::findOrFail($repayment->transaction_id);

    expect($loan->status)->toBe('closed')
        ->and((float) $loan->principal_balance)->toBe(0.0)
        ->and((float) $loan->interest_balance)->toBe(0.0)
        ->and($loan->loanAccount()->value('status'))->toBe('closed')
        ->and($loan->loanGuarantors()->first()->status)->toBe('released')
        ->and($repayment->transaction_id)->toBe($transaction->id)
        ->and($transaction->reference)->toBe($repayment->reference)
        ->and(DrawerMovement::where('reference', $transaction->reference)->value('type'))->toBe('cash_in')
        ->and((float) $drawer->fresh()->current_balance)->toBe($openingBalance + $amountDue);
});

it('hides the direct repayment workflow from loan officers', function () {
    $user = loanWorkflowUser();
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    loanWorkflowSavingsAccount($user, $client);

    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-0000002',
        'requested_amount' => 400_000,
        'approved_amount' => 400_000,
        'principal_balance' => 400_000,
        'interest_rate' => 24,
        'term_periods' => 2,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Working capital',
        'status' => 'approved',
        'approved_by' => $user->id,
        'approved_at' => today(),
    ]);

    app(LoanService::class)->disburseToSavings($loan, $user);

    $this->actingAs($user);

    $html = view('loans.partials.workflow-actions', [
        'loan' => $loan->fresh()->load('loanAccount'),
        'canAssignOfficer' => false,
        'canCancel' => false,
        'assignableOfficers' => collect(),
        'statusTone' => 'bg-emerald-50 text-emerald-700',
        'statusLabel' => 'Active',
        'errors' => new ViewErrorBag,
    ])->render();

    expect($html)
        ->not->toContain(route('loans.repayments.store', $loan))
        ->not->toContain('Post repayment')
        ->not->toContain('Open Teller Drawer');
});

it('lets an admin close a fully paid loan that remained active', function () {
    $user = loanWorkflowUser();
    $superAdminRole = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
    $user->update(['role_id' => $superAdminRole->id]);
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $loanAccount = Account::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'account_type_id' => AccountType::where('category', 'loan')->firstOrFail()->id,
        'account_number' => '1001-LN-PAID',
        'account_name' => $client->full_name.' Loan',
        'status' => 'active',
        'balance' => 0,
        'opened_at' => today(),
    ]);
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'loan_account_id' => $loanAccount->id,
        'loan_number' => '1001-LN-PAID',
        'requested_amount' => 100000,
        'approved_amount' => 100000,
        'principal_balance' => 0,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 0,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
    ]);
    LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today(),
        'principal_due' => 100000,
        'interest_due' => 0,
        'fees_due' => 0,
        'penalty_due' => 0,
        'total_due' => 100000,
        'total_paid' => 100000,
        'outstanding_amount' => 0,
        'balance_due' => 0,
        'status' => 'paid',
    ]);

    $response = $this->actingAs($user)
        ->post(route('loans.close-paid', $loan), [
            'reason' => 'Loan was fully paid but remained active.',
        ]);

    $response
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Fully paid loan closed.');

    expect($loan->fresh()->status)->toBe('closed')
        ->and($loanAccount->fresh()->status)->toBe('closed')
        ->and((float) $loanAccount->fresh()->balance)->toBe(0.0);
});

it('lets an admin close a loan with remaining balance coverage recorded', function () {
    $user = loanWorkflowUser();
    $superAdminRole = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
    $user->update(['role_id' => $superAdminRole->id]);
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $loanAccount = Account::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'account_type_id' => AccountType::where('category', 'loan')->firstOrFail()->id,
        'account_number' => '1001-LN-COVER',
        'account_name' => $client->full_name.' Loan',
        'status' => 'active',
        'balance' => 115000,
        'opened_at' => today(),
    ]);
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'loan_account_id' => $loanAccount->id,
        'loan_number' => '1001-LN-COVER',
        'requested_amount' => 100000,
        'approved_amount' => 100000,
        'principal_balance' => 100000,
        'interest_balance' => 10000,
        'fees_balance' => 5000,
        'penalty_balance' => 0,
        'interest_rate' => 12,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
    ]);
    LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today(),
        'principal_due' => 100000,
        'interest_due' => 10000,
        'fees_due' => 5000,
        'penalty_due' => 0,
        'total_due' => 115000,
        'total_paid' => 0,
        'outstanding_amount' => 115000,
        'balance_due' => 115000,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->post(route('loans.close-with-coverage', $loan), [
            'coverage_method' => 'organization_covered',
            'coverage_reference' => 'BOARD-001',
            'reason' => 'Board approved covering the remaining balance.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Loan closed with balance coverage recorded.');

    $freshLoan = $loan->fresh();
    $writeOff = LoanWriteOff::where('loan_id', $loan->id)->firstOrFail();

    expect($freshLoan->status)->toBe('closed')
        ->and((float) $freshLoan->principal_balance)->toBe(0.0)
        ->and((float) $freshLoan->interest_balance)->toBe(0.0)
        ->and((float) $freshLoan->fees_balance)->toBe(0.0)
        ->and($loanAccount->fresh()->status)->toBe('closed')
        ->and((float) $loanAccount->fresh()->balance)->toBe(0.0)
        ->and((float) $writeOff->principal_amount)->toBe(100000.0)
        ->and((float) $writeOff->interest_amount)->toBe(10000.0)
        ->and($writeOff->reason)->toContain('BOARD-001');
});

it('requires write off permission before a loan can be written off from coverage actions', function () {
    $user = loanWorkflowUser();
    $role = Role::create(['name' => 'Repair Operator', 'slug' => 'repair-operator']);
    $role->permissions()->sync(Permission::whereIn('slug', ['manage-loans', 'approve-reversals'])->pluck('id')->all());
    $user->update(['role_id' => $role->id]);
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-NO-WRITEOFF',
        'requested_amount' => 100000,
        'approved_amount' => 100000,
        'principal_balance' => 100000,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 12,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
    ]);

    expect($user->fresh()->canWriteOffLoans())->toBeFalse();

    $response = $this->actingAs($user)
        ->post(route('loans.close-with-coverage', $loan), [
            'coverage_method' => 'write_off',
            'reason' => 'Trying to write off without permission.',
        ]);

    expect($response->isForbidden() || $response->isRedirect())->toBeTrue()
        ->and($loan->fresh()->status)->toBe('active')
        ->and(LoanWriteOff::where('loan_id', $loan->id)->exists())->toBeFalse();
});

it('shows a loan when the legacy guarantors payload is empty', function () {
    $user = loanWorkflowUser();
    $organization = loanWorkflowOrganizationWithModules($user, ['clients', 'loans']);
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $product->update(['organization_id' => $organization->id]);

    $loan = Loan::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-0000003',
        'requested_amount' => 500_000,
        'principal_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 2,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Input purchase',
        'status' => 'submitted',
        'guarantors' => null,
    ]);

    $this->actingAs($user)
        ->get(route('loans.show', $loan))
        ->assertSuccessful()
        ->assertSee($loan->loan_number)
        ->assertSee('No guarantors recorded.');
});

it('returns a form error when approving a loan without required accepted guarantors', function () {
    $user = loanWorkflowUser();
    $organization = loanWorkflowOrganizationWithModules($user, ['clients', 'loans']);
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct();
    $product->update(['organization_id' => $organization->id]);

    $loan = Loan::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-0000004',
        'requested_amount' => 500_000,
        'principal_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 2,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Input purchase',
        'status' => 'submitted',
    ]);

    $this->actingAs($user)
        ->from(route('loans.show', $loan))
        ->post(route('loans.approve', $loan), [
            'approved_amount' => 500_000,
            'comments' => 'Ready for approval.',
        ])
        ->assertRedirect(route('loans.show', $loan))
        ->assertSessionHasErrors(['approved_amount' => 'This product requires accepted guarantors before approval.']);

    expect($loan->fresh()->status)->toBe('submitted');
});

it('requires complete individual client biodata before creating a loan', function () {
    $user = loanWorkflowUser();
    $client = loanWorkflowClient($user);
    $client->update([
        'national_id' => null,
        'date_of_birth' => null,
        'gender' => null,
    ]);
    $product = loanWorkflowProduct();

    expect(fn () => app(LoanApplicationService::class)->submit([
        'client_id' => $client->id,
        'loan_product_id' => $product->id,
        'requested_amount' => 500_000,
        'term_periods' => 2,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Input purchase',
    ], $user))
        ->toThrow(ValidationException::class);

    expect(Loan::query()->count())->toBe(0);
});

it('requires at least one guarantor before submitting a loan for approval', function () {
    $user = loanWorkflowUser();
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $savings = loanWorkflowSavingsAccount($user, $client);
    $loanAccount = Account::create([
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'account_type_id' => AccountType::where('category', 'loan')->value('id'),
        'account_number' => '1001-LN-'.fake()->unique()->numerify('#######'),
        'account_name' => $client->full_name.' Loan',
        'status' => 'active',
        'balance' => 0,
        'opened_at' => today(),
    ]);
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'savings_account_id' => $savings->id,
        'loan_account_id' => $loanAccount->id,
        'loan_number' => '1001-LN-NOGUAR',
        'requested_amount' => 500_000,
        'principal_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 2,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Input purchase',
        'status' => 'under_review',
    ]);

    expect(fn () => app(LoanService::class)->submitForApproval($loan, $user))
        ->toThrow(InvalidArgumentException::class, 'Attach at least one guarantor before sending this loan for approval.');

    expect($loan->fresh()->status)->toBe('under_review');
});

it('deducts the requested amount application fee from savings when rejecting a loan', function () {
    $user = loanWorkflowUser();
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $savings = loanWorkflowSavingsAccount($user, $client);

    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'savings_account_id' => $savings->id,
        'loan_number' => '1001-LN-0000005',
        'requested_amount' => 500_000,
        'principal_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 2,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Input purchase',
        'status' => 'submitted',
    ]);

    app(LoanService::class)->reject($loan, $user, 'Weak repayment capacity.');

    expect($loan->fresh()->status)->toBe('rejected')
        ->and((float) $savings->fresh()->balance)->toBe(235_000.0);

    $transaction = Transaction::where('account_id', $savings->id)->latest('id')->firstOrFail();

    expect($transaction->type->code)->toBe('loan_application_fee')
        ->and((float) $transaction->amount)->toBe(15_000.0)
        ->and((float) $transaction->balance_after)->toBe(235_000.0);

    $this->assertDatabaseHas('journal_entries', [
        'transaction_id' => $transaction->id,
        'entry_type' => 'credit',
        'amount' => 15_000,
    ]);
});

it('blocks loan rejection when savings cannot cover the requested amount application fee', function () {
    $user = loanWorkflowUser();
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $savings = loanWorkflowSavingsAccount($user, $client);
    $savings->update(['balance' => 10_000]);

    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'savings_account_id' => $savings->id,
        'loan_number' => '1001-LN-0000006',
        'requested_amount' => 500_000,
        'principal_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 2,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Input purchase',
        'status' => 'submitted',
    ]);

    expect(fn () => app(LoanService::class)->reject($loan, $user, 'Weak repayment capacity.'))
        ->toThrow(InvalidArgumentException::class, 'Client savings account does not have enough money to deduct the application fee before rejection.');

    expect($loan->fresh()->status)->toBe('submitted')
        ->and((float) $savings->fresh()->balance)->toBe(10_000.0)
        ->and(Transaction::where('account_id', $savings->id)->exists())->toBeFalse();
});

it('marks overdue schedules and applies configured penalties', function () {
    $user = loanWorkflowUser();
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    loanWorkflowSavingsAccount($user, $client);

    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-0000002',
        'requested_amount' => 500_000,
        'approved_amount' => 500_000,
        'principal_balance' => 500_000,
        'interest_rate' => 24,
        'term_periods' => 2,
        'repayment_frequency' => 'monthly',
        'purpose' => 'Input purchase',
        'status' => 'approved',
        'approved_by' => $user->id,
        'approved_at' => today(),
    ]);

    app(LoanService::class)->disburseToSavings($loan, $user);
    $schedule = $loan->schedules()->first();
    $schedule->update(['due_date' => today()->subDays(10)]);

    $count = app(LoanService::class)->applyOverduePenalties($loan, today());

    expect($count)->toBe(1)
        ->and($schedule->fresh()->status)->toBe('overdue')
        ->and((float) $schedule->fresh()->penalty_due)->toBeGreaterThan(0.0)
        ->and($loan->fresh()->status)->toBe('in_arrears');
});

it('repairs unpaid monthly schedules to start one business month after application', function () {
    $user = loanWorkflowUser();
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    loanWorkflowSavingsAccount($user, $client);
    $applicationDate = today();
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-MONTHLY-REPAIR',
        'requested_amount' => 500000,
        'approved_amount' => 500000,
        'principal_balance' => 500000,
        'penalty_balance' => 10000,
        'interest_rate' => 24,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'application_date' => $applicationDate,
        'status' => 'in_arrears',
    ]);
    $schedule = LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => $applicationDate,
        'principal_due' => 500000,
        'interest_due' => 0,
        'penalty_due' => 10000,
        'total_due' => 510000,
        'outstanding_amount' => 510000,
        'balance_due' => 510000,
        'status' => 'overdue',
        'days_overdue' => 1,
    ]);
    LoanPenalty::create([
        'loan_id' => $loan->id,
        'loan_schedule_id' => $schedule->id,
        'amount' => 10000,
        'days_overdue' => 1,
        'calculated_for_date' => $applicationDate,
        'status' => 'pending',
    ]);

    $totals = app(LoanService::class)->repairMonthlyScheduleDates();

    $expectedDueDate = app(BusinessCalendarService::class)
        ->dueDate($applicationDate, 1, 'monthly')
        ->toDateString();

    expect($totals['loans'])->toBe(1)
        ->and($totals['schedules'])->toBe(1)
        ->and($schedule->fresh()->due_date?->toDateString())->toBe($expectedDueDate)
        ->and($schedule->fresh()->status)->toBe('pending')
        ->and((float) $schedule->fresh()->penalty_due)->toBe(0.0)
        ->and((float) $schedule->fresh()->outstanding_amount)->toBe(500000.0)
        ->and((float) $loan->fresh()->penalty_balance)->toBe(0.0)
        ->and($loan->fresh()->status)->toBe('active')
        ->and(LoanPenalty::where('loan_schedule_id', $schedule->id)->exists())->toBeFalse();
});

it('repairs monthly schedule dates with partial repayments without reversing payments', function () {
    $user = loanWorkflowUser();
    $user->role->update(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $applicationDate = today();
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-PARTIAL-REPAIR',
        'requested_amount' => 500000,
        'approved_amount' => 500000,
        'principal_balance' => 400000,
        'interest_balance' => 0,
        'penalty_balance' => 10000,
        'interest_rate' => 24,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'application_date' => $applicationDate,
        'status' => 'in_arrears',
    ]);
    $schedule = LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => $applicationDate,
        'principal_due' => 500000,
        'principal_paid' => 100000,
        'penalty_due' => 10000,
        'total_due' => 510000,
        'total_paid' => 100000,
        'amount_paid' => 100000,
        'outstanding_amount' => 410000,
        'balance_due' => 410000,
        'status' => 'overdue',
        'days_overdue' => 1,
    ]);
    $repayment = LoanRepayment::create([
        'loan_id' => $loan->id,
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'amount' => 100000,
        'principal_paid' => 100000,
        'paid_at' => today(),
        'status' => 'successful',
    ]);
    LoanPenalty::create([
        'loan_id' => $loan->id,
        'loan_schedule_id' => $schedule->id,
        'amount' => 10000,
        'days_overdue' => 1,
        'calculated_for_date' => $applicationDate,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($user)
        ->post(route('loans.repair-terms', $loan), [
            'loan_product_id' => $product->id,
            'approved_amount' => 500000,
            'term_periods' => 1,
            'repayment_frequency' => 'monthly',
            'schedule_start_date' => $applicationDate->toDateString(),
            'reason' => 'Correct monthly due date that was generated on the issue date.',
        ]);

    $expectedDueDate = app(BusinessCalendarService::class)
        ->dueDate($applicationDate, 1, 'monthly')
        ->toDateString();

    $response
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Loan terms repaired and repayment schedule recalculated.');

    expect($schedule->fresh()->due_date?->toDateString())->toBe($expectedDueDate)
        ->and($schedule->fresh()->status)->toBe('partially_paid')
        ->and((float) $schedule->fresh()->principal_paid)->toBe(100000.0)
        ->and((float) $schedule->fresh()->total_paid)->toBe(100000.0)
        ->and((float) $schedule->fresh()->penalty_due)->toBe(0.0)
        ->and((float) $schedule->fresh()->outstanding_amount)->toBe(400000.0)
        ->and((float) $loan->fresh()->penalty_balance)->toBe(0.0)
        ->and($loan->fresh()->status)->toBe('active')
        ->and($repayment->fresh()->status)->toBe('successful');
});

it('excludes cancelled loans from arrears listings and penalty calculations', function () {
    $branch = Branch::create(['code' => '1009', 'name' => 'Arrears Branch', 'is_active' => true, 'status' => 'active']);
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $product = loanWorkflowProduct(['code' => 'ARR-'.fake()->unique()->bothify('??')]);
    $client = Client::create([
        'branch_id' => $branch->id,
        'officer_id' => $user->id,
        'client_number' => '1009-C000001',
        'client_type' => 'individual',
        'first_name' => 'Arrears',
        'last_name' => 'Client',
        'phone' => '+256700009001',
        'status' => 'active',
        'kyc_status' => 'verified',
    ]);

    $activeLoan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'created_by' => $user->id,
        'loan_number' => '1009-LN-ACTIVE',
        'requested_amount' => 100000,
        'principal_balance' => 100000,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 20,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'application_date' => today()->subMonth(),
        'status' => 'active',
    ]);
    $cancelledLoan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'created_by' => $user->id,
        'loan_number' => '1009-LN-CANCELLED',
        'requested_amount' => 200000,
        'principal_balance' => 200000,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 20,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'application_date' => today()->subMonth(),
        'status' => 'cancelled',
    ]);
    $rejectedLoan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'created_by' => $user->id,
        'loan_number' => '1009-LN-REJECTED',
        'requested_amount' => 200000,
        'principal_balance' => 200000,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 20,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'application_date' => today()->subMonth(),
        'status' => 'rejected',
    ]);
    $draftLoan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'created_by' => $user->id,
        'loan_number' => '1009-LN-DRAFT',
        'requested_amount' => 200000,
        'principal_balance' => 200000,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 20,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'application_date' => today()->subMonth(),
        'status' => 'draft',
    ]);

    LoanSchedule::create([
        'loan_id' => $activeLoan->id,
        'installment_number' => 1,
        'due_date' => today()->subDays(10),
        'principal_due' => 100000,
        'interest_due' => 0,
        'total_due' => 100000,
        'outstanding_amount' => 100000,
        'balance_due' => 100000,
        'status' => 'overdue',
        'days_overdue' => 10,
    ]);
    $cancelledSchedule = LoanSchedule::create([
        'loan_id' => $cancelledLoan->id,
        'installment_number' => 1,
        'due_date' => today()->subDays(10),
        'principal_due' => 200000,
        'interest_due' => 0,
        'total_due' => 200000,
        'outstanding_amount' => 200000,
        'balance_due' => 200000,
        'status' => 'overdue',
        'days_overdue' => 10,
    ]);
    $rejectedSchedule = LoanSchedule::create([
        'loan_id' => $rejectedLoan->id,
        'installment_number' => 1,
        'due_date' => today()->subDays(10),
        'principal_due' => 200000,
        'interest_due' => 0,
        'total_due' => 200000,
        'outstanding_amount' => 200000,
        'balance_due' => 200000,
        'status' => 'overdue',
        'days_overdue' => 10,
    ]);
    $draftSchedule = LoanSchedule::create([
        'loan_id' => $draftLoan->id,
        'installment_number' => 1,
        'due_date' => today()->subDays(10),
        'principal_due' => 200000,
        'interest_due' => 0,
        'total_due' => 200000,
        'outstanding_amount' => 200000,
        'balance_due' => 200000,
        'status' => 'overdue',
        'days_overdue' => 10,
    ]);

    $this->actingAs($user)
        ->get(route('arrears.index'))
        ->assertSuccessful()
        ->assertSee('1009-LN-ACTIVE')
        ->assertDontSee('1009-LN-CANCELLED')
        ->assertDontSee('1009-LN-REJECTED')
        ->assertDontSee('1009-LN-DRAFT')
        ->assertDontSee('700,000.00');

    expect(app(LoanService::class)->applyOverduePenalties($cancelledLoan, today()))->toBe(0)
        ->and(app(LoanService::class)->applyOverduePenalties($rejectedLoan, today()))->toBe(0)
        ->and(app(LoanService::class)->applyOverduePenalties($draftLoan, today()))->toBe(0)
        ->and($cancelledSchedule->fresh()->outstanding_amount)->toBe('200000.00')
        ->and($rejectedSchedule->fresh()->outstanding_amount)->toBe('200000.00')
        ->and($draftSchedule->fresh()->outstanding_amount)->toBe('200000.00');
});

it('applies scheduled penalties only after a loan becomes operationally active', function () {
    $user = loanWorkflowUser();
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $client = loanWorkflowClient($user, '+256700220006');
    $disbursedLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-DISBURSED-ARREARS', 'disbursed');
    $restructuredLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-RESTRUCTURED-ARREARS', 'restructured');
    $disbursedSchedule = loanWorkflowSchedule($disbursedLoan, today()->subDays(10), 'overdue');
    $restructuredSchedule = loanWorkflowSchedule($restructuredLoan, today()->subDays(10), 'overdue');

    $this->artisan('loans:apply-penalties')
        ->expectsOutputToContain('applied UGX')
        ->assertSuccessful();

    expect((float) $disbursedSchedule->fresh()->penalty_due)->toBe(0.0)
        ->and((float) $restructuredSchedule->fresh()->penalty_due)->toBe(0.0)
        ->and($disbursedLoan->fresh()->status)->toBe('disbursed')
        ->and($restructuredLoan->fresh()->status)->toBe('restructured');

    $disbursedLoan->update(['status' => 'active']);

    expect(app(LoanService::class)->applyOverduePenalties($disbursedLoan, today()))->toBe(1)
        ->and((float) $disbursedSchedule->fresh()->penalty_due)->toBeGreaterThan(0.0)
        ->and($disbursedLoan->fresh()->status)->toBe('in_arrears');
});

it('combines multiple overdue schedules into one arrears listing row per loan', function () {
    $user = loanWorkflowUser();
    $organization = loanWorkflowOrganizationWithModules($user, ['loans']);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $product->update(['organization_id' => $organization->id]);
    $client = loanWorkflowClient($user, '+256700220001');
    $client->update(['organization_id' => $organization->id]);
    $loan = Loan::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'created_by' => $user->id,
        'loan_number' => '1001-LN-MULTI-ARREARS',
        'requested_amount' => 300000,
        'principal_balance' => 300000,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 20,
        'term_periods' => 2,
        'repayment_frequency' => 'monthly',
        'application_date' => today()->subMonths(2),
        'status' => 'active',
    ]);

    LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today()->subDays(15),
        'principal_due' => 100000,
        'interest_due' => 0,
        'total_due' => 100000,
        'outstanding_amount' => 100000,
        'balance_due' => 100000,
        'status' => 'overdue',
        'days_overdue' => 15,
    ]);
    LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'due_date' => today()->subDays(5),
        'principal_due' => 200000,
        'interest_due' => 0,
        'total_due' => 200000,
        'outstanding_amount' => 200000,
        'balance_due' => 200000,
        'status' => 'overdue',
        'days_overdue' => 5,
    ]);

    $response = $this->actingAs($user)
        ->get(route('arrears.index'))
        ->assertSuccessful()
        ->assertSee('installments due')
        ->assertSee('UGX 300,000.00')
        ->assertDontSee('#1')
        ->assertDontSee('#2');

    expect(substr_count($response->getContent(), '1001-LN-MULTI-ARREARS'))->toBe(1);
});

it('summarizes loan arrears reports by loan instead of installment rows', function () {
    $user = loanWorkflowUser();
    $organization = loanWorkflowOrganizationWithModules($user, ['loans', 'reports']);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $product->update(['organization_id' => $organization->id]);
    $client = loanWorkflowClient($user, '+256700220010');
    $client->update(['organization_id' => $organization->id]);
    $loan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-REPORT-ARREARS', 'active');

    LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today()->subDays(15),
        'principal_due' => 100000,
        'interest_due' => 0,
        'total_due' => 100000,
        'outstanding_amount' => 100000,
        'balance_due' => 100000,
        'status' => 'overdue',
        'days_overdue' => 15,
    ]);
    LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'due_date' => today()->subDays(5),
        'principal_due' => 200000,
        'interest_due' => 0,
        'total_due' => 200000,
        'outstanding_amount' => 200000,
        'balance_due' => 200000,
        'status' => 'overdue',
        'days_overdue' => 5,
    ]);

    $report = app(ReportCatalogService::class)->generate('loan-accounts-in-arrears', [
        'from' => today()->subMonth()->toDateString(),
        'to' => today()->toDateString(),
    ], $user);
    $row = $report['rows']->first();

    expect($report['columns'])->toBe(['Due Date', 'Loan', 'Client', 'Product', 'Installments Due', 'Total Due', 'Days Due', 'Status'])
        ->and($report['rows'])->toHaveCount(1)
        ->and($row['Loan'])->toBe('1001-LN-REPORT-ARREARS')
        ->and($row['Installments Due'])->toBe('2')
        ->and($row['Total Due'])->toBe('UGX 300,000.00')
        ->and($row['Days Due'])->toBe('15')
        ->and($report['summary']['Installments due'])->toBe('2')
        ->and($report['summary']['Total due'])->toBe('UGX 300,000.00');

    $this->actingAs($user)
        ->get(route('reports.show', 'loan-accounts-in-arrears'))
        ->assertSuccessful()
        ->assertSee('Installments Due')
        ->assertSee('UGX 300,000.00')
        ->assertDontSee('#1')
        ->assertDontSee('#2');
});

it('limits the loan book to active loans with positive loan balances', function () {
    $user = loanWorkflowUser();
    $organization = loanWorkflowOrganizationWithModules($user, ['loans', 'reports']);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $product->update(['organization_id' => $organization->id]);
    $client = loanWorkflowClient($user, '+256700220011');
    $client->update(['organization_id' => $organization->id]);

    $activeLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-BOOK-ACTIVE', 'active');
    $arrearsLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-BOOK-ARREARS', 'in_arrears');
    $closedLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-BOOK-CLOSED', 'closed');
    $draftLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-BOOK-DRAFT', 'draft');
    $rejectedLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-BOOK-REJECTED', 'rejected');
    $zeroBalanceLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-BOOK-ZERO', 'active');
    $zeroBalanceLoan->update([
        'principal_balance' => 0,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
    ]);

    $report = app(ReportCatalogService::class)->generate('loan-book', [], $user);
    $accounts = $report['rows']->pluck('Account')->all();

    expect($accounts)->toContain($activeLoan->loan_number, $arrearsLoan->loan_number)
        ->and($accounts)->not->toContain($closedLoan->loan_number, $draftLoan->loan_number, $rejectedLoan->loan_number, $zeroBalanceLoan->loan_number)
        ->and($report['summary']['Loans'])->toBe('2')
        ->and($report['summary']['Balance total'])->toBe('UGX 600,000.00');

    $this->actingAs($user)
        ->get(route('reports.show', 'loan-book'))
        ->assertSuccessful()
        ->assertSee('1001-LN-BOOK-ACTIVE')
        ->assertSee('1001-LN-BOOK-ARREARS')
        ->assertDontSee('1001-LN-BOOK-CLOSED')
        ->assertDontSee('1001-LN-BOOK-DRAFT')
        ->assertDontSee('1001-LN-BOOK-REJECTED')
        ->assertDontSee('1001-LN-BOOK-ZERO');
});

it('exports active loan balances and grouped arrears rows', function () {
    Storage::fake('local');

    $user = loanWorkflowUser();
    $organization = loanWorkflowOrganizationWithModules($user, ['loans', 'reports']);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $product->update(['organization_id' => $organization->id]);
    $client = loanWorkflowClient($user, '+256700220012');
    $client->update(['organization_id' => $organization->id]);

    $activeLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-EXPORT-ACTIVE', 'active');
    $arrearsLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-EXPORT-ARREARS', 'in_arrears');
    $closedLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-EXPORT-CLOSED', 'closed');
    LoanSchedule::create([
        'loan_id' => $arrearsLoan->id,
        'installment_number' => 1,
        'due_date' => today()->subDays(11),
        'principal_due' => 100000,
        'total_due' => 100000,
        'outstanding_amount' => 100000,
        'balance_due' => 100000,
        'status' => 'overdue',
        'days_overdue' => 11,
    ]);
    LoanSchedule::create([
        'loan_id' => $arrearsLoan->id,
        'installment_number' => 2,
        'due_date' => today()->subDays(3),
        'principal_due' => 200000,
        'total_due' => 200000,
        'outstanding_amount' => 200000,
        'balance_due' => 200000,
        'status' => 'overdue',
        'days_overdue' => 3,
    ]);

    $loanExport = ReportExport::create([
        'organization_id' => $organization->id,
        'branch_id' => $user->branch_id,
        'requested_by' => $user->id,
        'report_type' => 'loan_report',
        'format' => 'csv',
        'status' => 'queued',
    ]);
    (new GenerateReportExport($loanExport))->handle();
    $loanContent = Storage::disk('local')->get($loanExport->fresh()->path);

    expect($loanContent)->toContain($activeLoan->loan_number, $arrearsLoan->loan_number, 'balance_total')
        ->not->toContain($closedLoan->loan_number);

    $arrearsExport = ReportExport::create([
        'organization_id' => $organization->id,
        'branch_id' => $user->branch_id,
        'requested_by' => $user->id,
        'report_type' => 'arrears_report',
        'format' => 'csv',
        'status' => 'queued',
    ]);
    (new GenerateReportExport($arrearsExport))->handle();
    $arrearsContent = Storage::disk('local')->get($arrearsExport->fresh()->path);

    expect($arrearsContent)->toContain($arrearsLoan->loan_number, 'installments_due', 'total_due', 'days_due')
        ->toContain('300000', '11')
        ->not->toContain('installment_number');

    $xlsxExport = ReportExport::create([
        'organization_id' => $organization->id,
        'branch_id' => $user->branch_id,
        'requested_by' => $user->id,
        'report_type' => 'loan_report',
        'format' => 'xlsx',
        'status' => 'queued',
    ]);
    (new GenerateReportExport($xlsxExport))->handle();
    $xlsxContent = Storage::disk('local')->get($xlsxExport->fresh()->path);
    $worksheet = loanWorkflowXlsxPart($xlsxContent, 'xl/worksheets/sheet1.xml');

    expect(substr($xlsxContent, 0, 2))->toBe('PK')
        ->and($worksheet)->toContain('Loan Book', 'Report Name:', $organization->name, 'Balance Total', $activeLoan->loan_number, $arrearsLoan->loan_number)
        ->not->toContain($closedLoan->loan_number);

    $pdfExport = ReportExport::create([
        'organization_id' => $organization->id,
        'branch_id' => $user->branch_id,
        'requested_by' => $user->id,
        'report_type' => 'arrears_report',
        'format' => 'pdf',
        'status' => 'queued',
    ]);
    (new GenerateReportExport($pdfExport))->handle();
    $pdfContent = Storage::disk('local')->get($pdfExport->fresh()->path);

    expect(substr($pdfContent, 0, 5))->toBe('%PDF-')
        ->and($pdfContent)->toContain('Loan Arrears Report', 'Report Name:', $organization->name, $arrearsLoan->loan_number)
        ->not->toContain($closedLoan->loan_number);
});

it('shows missed amount penalty and total to pay on arrears listing', function () {
    $user = loanWorkflowUser();
    $user->role->update(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $client = loanWorkflowClient($user, '+256700220002');
    $loan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-ARREARS-BREAKDOWN', 'in_arrears');
    $loan->update(['penalty_balance' => 10000]);

    LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today()->subDays(10),
        'principal_due' => 300000,
        'interest_due' => 0,
        'penalty_due' => 10000,
        'total_due' => 310000,
        'outstanding_amount' => 310000,
        'balance_due' => 310000,
        'status' => 'overdue',
        'days_overdue' => 10,
    ]);

    $this->actingAs($user)
        ->get(route('arrears.index'))
        ->assertSuccessful()
        ->assertSee('Missed')
        ->assertSee('Penalty')
        ->assertSee('Total')
        ->assertSee('UGX 300,000.00')
        ->assertSee('UGX 10,000.00')
        ->assertSee('UGX 310,000.00');
});

it('lets an admin clear penalties for one loan and updates balances', function () {
    $user = loanWorkflowUser();
    $user->role->update(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $client = loanWorkflowClient($user, '+256700220003');
    $loanAccount = Account::create([
        'client_id' => $client->id,
        'branch_id' => $client->branch_id,
        'account_type_id' => AccountType::where('category', 'loan')->value('id'),
        'account_number' => '1001-LON-CLEAR-001',
        'account_name' => $client->full_name.' Loan',
        'status' => 'active',
        'balance' => 310000,
        'opened_at' => today(),
    ]);
    $loan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-CLEAR-PENALTIES', 'in_arrears');
    $loan->update([
        'loan_account_id' => $loanAccount->id,
        'penalty_balance' => 10000,
    ]);
    $schedule = LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today()->subDays(10),
        'principal_due' => 300000,
        'penalty_due' => 10000,
        'total_due' => 310000,
        'outstanding_amount' => 310000,
        'balance_due' => 310000,
        'status' => 'overdue',
        'days_overdue' => 10,
    ]);
    $penalty = LoanPenalty::create([
        'loan_id' => $loan->id,
        'loan_schedule_id' => $schedule->id,
        'amount' => 10000,
        'days_overdue' => 9,
        'calculated_for_date' => today(),
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->post(route('arrears.clear-penalties'), ['loan_id' => $loan->id])
        ->assertRedirect()
        ->assertSessionHas('status', 'Cleared UGX 10,000.00 in penalties from 1 schedule(s).');

    expect((float) $schedule->fresh()->penalty_due)->toBe(0.0)
        ->and((float) $schedule->fresh()->outstanding_amount)->toBe(300000.0)
        ->and((float) $loan->fresh()->penalty_balance)->toBe(0.0)
        ->and((float) $loanAccount->fresh()->balance)->toBe(300000.0)
        ->and($penalty->fresh()->status)->toBe('waived');
});

it('recalculates penalties after cleared penalties are waived', function () {
    $user = loanWorkflowUser();
    $user->role->update(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $organization = loanWorkflowOrganizationWithModules($user, ['loans']);
    SystemSetting::withoutGlobalScope('tenant')->updateOrCreate(
        ['organization_id' => null, 'key' => 'loan_arrears_penalty_rate'],
        ['label' => 'Loan arrears penalty rate', 'value' => '5', 'type' => 'number', 'group' => 'loans']
    );
    SystemSetting::withoutGlobalScope('tenant')->updateOrCreate(
        ['organization_id' => $organization->id, 'key' => 'loan_arrears_penalty_rate'],
        ['label' => 'Loan arrears penalty rate', 'value' => '1', 'type' => 'number', 'group' => 'loans']
    );
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $product->update(['organization_id' => $organization->id]);
    $client = loanWorkflowClient($user, '+256700220005');
    $client->update(['organization_id' => $organization->id]);
    $loan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-REAPPLY-PENALTIES', 'in_arrears');
    $loan->update(['penalty_balance' => 10000]);
    $schedule = LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today()->subDays(10),
        'principal_due' => 300000,
        'penalty_due' => 10000,
        'total_due' => 310000,
        'outstanding_amount' => 310000,
        'balance_due' => 310000,
        'status' => 'overdue',
        'days_overdue' => 9,
    ]);
    LoanPenalty::create([
        'loan_id' => $loan->id,
        'loan_schedule_id' => $schedule->id,
        'amount' => 10000,
        'days_overdue' => 9,
        'calculated_for_date' => today(),
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->post(route('arrears.clear-penalties'), ['loan_id' => $loan->id])
        ->assertRedirect()
        ->assertSessionHas('status', 'Cleared UGX 10,000.00 in penalties from 1 schedule(s).');

    $response = $this->actingAs($user)
        ->post(route('arrears.apply-penalties'));

    $newPenalty = LoanPenalty::query()
        ->where('loan_schedule_id', $schedule->id)
        ->where('status', 'pending')
        ->latest('id')
        ->firstOrFail();
    $chargeableDays = max(0, app(BusinessCalendarService::class)->businessDaysBetween($schedule->due_date, today(), $organization->id) - 1);
    $expectedPenalty = (float) (round(((300000 * 0.01 * $chargeableDays) / 50)) * 50);

    $response
        ->assertRedirect()
        ->assertSessionHas('status', sprintf(
            'Checked 1 overdue installment(s). Applied UGX %s in penalties to 1 schedule(s).',
            number_format((float) $newPenalty->amount, 2),
        ));

    expect((float) $newPenalty->amount)->toBe($expectedPenalty)
        ->and((float) $schedule->fresh()->penalty_due)->toBe((float) $newPenalty->amount)
        ->and((float) $loan->fresh()->penalty_balance)->toBe((float) $newPenalty->amount);
});

it('lets an admin clear penalties for one schedule only', function () {
    $user = loanWorkflowUser();
    $user->role->update(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $client = loanWorkflowClient($user, '+256700220004');
    $loan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-CLEAR-SCHEDULE', 'in_arrears');
    $loan->update(['penalty_balance' => 13000]);
    $firstSchedule = LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today()->subDays(10),
        'principal_due' => 100000,
        'penalty_due' => 5000,
        'total_due' => 105000,
        'outstanding_amount' => 105000,
        'balance_due' => 105000,
        'status' => 'overdue',
        'days_overdue' => 10,
    ]);
    $secondSchedule = LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'due_date' => today()->subDays(5),
        'principal_due' => 200000,
        'penalty_due' => 8000,
        'total_due' => 208000,
        'outstanding_amount' => 208000,
        'balance_due' => 208000,
        'status' => 'overdue',
        'days_overdue' => 5,
    ]);

    $this->actingAs($user)
        ->post(route('arrears.clear-penalties'), ['loan_schedule_id' => $firstSchedule->id])
        ->assertRedirect()
        ->assertSessionHas('status', 'Cleared UGX 5,000.00 in penalties from 1 schedule(s).');

    expect((float) $firstSchedule->fresh()->penalty_due)->toBe(0.0)
        ->and((float) $firstSchedule->fresh()->outstanding_amount)->toBe(100000.0)
        ->and((float) $secondSchedule->fresh()->penalty_due)->toBe(8000.0)
        ->and((float) $loan->fresh()->penalty_balance)->toBe(8000.0);
});

it('waits one extra day before treating an unpaid schedule as arrears', function () {
    $user = loanWorkflowUser();
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $client = loanWorkflowClient($user);
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'created_by' => $user->id,
        'loan_number' => '1001-LN-GRACE',
        'requested_amount' => 100000,
        'principal_balance' => 100000,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 20,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'application_date' => today()->subMonth(),
        'status' => 'active',
    ]);
    $schedule = LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today()->subDay(),
        'principal_due' => 100000,
        'interest_due' => 0,
        'total_due' => 100000,
        'outstanding_amount' => 100000,
        'balance_due' => 100000,
        'status' => 'pending',
        'days_overdue' => 0,
    ]);

    expect(LoanSchedule::query()->eligibleForArrears(today())->whereKey($schedule->id)->exists())->toBeFalse()
        ->and(app(LoanService::class)->applyOverduePenalties($loan, today()))->toBe(0)
        ->and($schedule->fresh()->status)->toBe('pending');

    $schedule->update(['due_date' => today()->subDays(2)]);

    expect(LoanSchedule::query()->eligibleForArrears(today())->whereKey($schedule->id)->exists())->toBeTrue()
        ->and(app(LoanService::class)->applyOverduePenalties($loan, today()))->toBe(1)
        ->and($schedule->fresh()->status)->toBe('overdue')
        ->and($loan->fresh()->status)->toBe('in_arrears');
});

it('applies rounded penalties to the missed schedule only', function () {
    $user = loanWorkflowUser();
    SystemSetting::withoutGlobalScope('tenant')->updateOrCreate(
        ['organization_id' => null, 'key' => 'loan_arrears_penalty_rate'],
        ['label' => 'Loan arrears penalty rate', 'value' => '1', 'type' => 'number', 'group' => 'loans']
    );
    $product = loanWorkflowProduct([
        'penalty_rate' => 99,
        'require_guarantors' => false,
        'minimum_guarantors' => 0,
    ]);
    $client = loanWorkflowClient($user);
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'created_by' => $user->id,
        'loan_number' => '1001-LN-PENALTY',
        'requested_amount' => 200000,
        'principal_balance' => 200000,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 20,
        'term_periods' => 2,
        'repayment_frequency' => 'monthly',
        'application_date' => today()->subMonths(2),
        'status' => 'active',
    ]);
    $missedSchedule = LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today()->subDays(3),
        'principal_due' => 17850,
        'interest_due' => 0,
        'total_due' => 17850,
        'outstanding_amount' => 17850,
        'balance_due' => 17850,
        'status' => 'pending',
        'days_overdue' => 0,
    ]);
    $futureSchedule = LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'due_date' => today()->addMonth(),
        'principal_due' => 100000,
        'interest_due' => 0,
        'total_due' => 100000,
        'outstanding_amount' => 100000,
        'balance_due' => 100000,
        'status' => 'pending',
        'days_overdue' => 0,
    ]);
    $chargeableDays = max(0, app(BusinessCalendarService::class)->businessDaysBetween($missedSchedule->due_date, today(), $loan->organization_id ? (int) $loan->organization_id : null) - 1);
    $expectedPenalty = (float) (ceil(((float) $missedSchedule->outstanding_amount * 0.01 * $chargeableDays) / CurrencyDenomination::MINIMUM_UNIT) * CurrencyDenomination::MINIMUM_UNIT);

    expect(app(LoanService::class)->applyOverduePenalties($loan, today()))->toBe(1)
        ->and((float) $missedSchedule->fresh()->penalty_due)->toBe($expectedPenalty)
        ->and((float) $missedSchedule->fresh()->outstanding_amount)->toBe(17850.0 + $expectedPenalty)
        ->and((float) $futureSchedule->fresh()->penalty_due)->toBe(0.0)
        ->and((float) $loan->fresh()->penalty_balance)->toBe($expectedPenalty);

    expect((float) LoanPenalty::where('loan_schedule_id', $missedSchedule->id)->value('amount'))->toBe($expectedPenalty);
});

it('shows arrears only for active branch loans', function () {
    $user = loanWorkflowUser();
    $organization = Organization::create(['name' => 'Branch Scope SACCO', 'slug' => 'branch-scope-sacco', 'status' => 'active']);
    Branch::whereKey($user->branch_id)->update(['organization_id' => $organization->id]);
    $user->update(['organization_id' => $organization->id]);
    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->enable($organization, Module::where('slug', 'loans')->firstOrFail(), $user);
    $otherBranch = Branch::create(['organization_id' => $organization->id, 'code' => '2002', 'name' => 'Other Branch', 'is_active' => true, 'status' => 'active']);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $product->update(['organization_id' => $organization->id]);
    $branchClient = loanWorkflowClient($user);
    $branchClient->update(['organization_id' => $organization->id]);
    $otherClient = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $otherBranch->id,
        'officer_id' => $user->id,
        'client_number' => '2002-C000001',
        'client_type' => 'individual',
        'first_name' => 'Other',
        'last_name' => 'Branch',
        'phone' => '+256700200001',
        'status' => 'active',
        'kyc_status' => 'verified',
    ]);

    $visibleLoan = Loan::create([
        'organization_id' => $organization->id,
        'client_id' => $branchClient->id,
        'branch_id' => $user->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-ACTIVE-BRANCH',
        'requested_amount' => 300000,
        'principal_balance' => 300000,
        'interest_rate' => 20,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
    ]);
    $approvedLoan = Loan::create([
        'organization_id' => $organization->id,
        'client_id' => $branchClient->id,
        'branch_id' => $user->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '1001-LN-APPROVED-ONLY',
        'requested_amount' => 300000,
        'principal_balance' => 300000,
        'interest_rate' => 20,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'status' => 'approved',
    ]);
    $otherBranchLoan = Loan::create([
        'organization_id' => $organization->id,
        'client_id' => $otherClient->id,
        'branch_id' => $otherBranch->id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => '2002-LN-ACTIVE-OTHER',
        'requested_amount' => 300000,
        'principal_balance' => 300000,
        'interest_rate' => 20,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
    ]);

    foreach ([$visibleLoan, $approvedLoan, $otherBranchLoan] as $loan) {
        LoanSchedule::create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => today()->subDays(7),
            'principal_due' => 300000,
            'interest_due' => 0,
            'total_due' => 300000,
            'outstanding_amount' => 300000,
            'balance_due' => 300000,
            'status' => 'overdue',
            'days_overdue' => 7,
        ]);
    }

    $response = $this->actingAs($user)
        ->get(route('arrears.index'));

    $response
        ->assertSuccessful()
        ->assertSee('1001-LN-ACTIVE-BRANCH')
        ->assertDontSee('1001-LN-APPROVED-ONLY')
        ->assertDontSee('2002-LN-ACTIVE-OTHER');
});

it('scopes arrears and due repayment views to the assigned loan officer', function () {
    $admin = loanWorkflowUser();
    $organization = loanWorkflowOrganizationWithModules($admin, ['clients', 'loans', 'reports']);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $product->update(['organization_id' => $organization->id]);
    $officerRole = Role::firstOrCreate(['slug' => 'loan-officer'], ['name' => 'Loan Officer']);
    collect(['manage-clients', 'manage-loans', 'view-reports'])
        ->map(fn (string $slug) => Permission::firstOrCreate(
            ['slug' => $slug],
            ['organization_id' => $organization->id, 'name' => str($slug)->headline()],
        ))
        ->pluck('id')
        ->each(fn (int $permissionId) => $officerRole->permissions()->syncWithoutDetaching([$permissionId]));
    $officerRole->permissions()->syncWithoutDetaching([
        Permission::firstOrCreate(
            ['slug' => 'manage-clients'],
            ['organization_id' => $organization->id, 'name' => 'Manage Clients'],
        )->id,
    ]);
    $adminRole = Role::firstOrCreate(['slug' => 'organization-admin'], ['name' => 'Organization Admin']);
    Permission::whereIn('slug', ['manage-loans', 'view-reports'])->pluck('id')->each(fn (int $permissionId) => $adminRole->permissions()->syncWithoutDetaching([$permissionId]));
    $admin->update(['role_id' => $adminRole->id]);
    $officer = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $admin->branch_id, 'role_id' => $officerRole->id, 'status' => 'active', 'is_active' => true]);
    $otherOfficer = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $admin->branch_id, 'role_id' => $officerRole->id, 'status' => 'active', 'is_active' => true]);
    $officerClient = loanWorkflowClient($officer, '+256700110001');
    $otherClient = loanWorkflowClient($otherOfficer, '+256700110002');
    $officerClient->update(['organization_id' => $organization->id]);
    $otherClient->update(['organization_id' => $organization->id]);

    $ownArrears = loanWorkflowTrackedLoan($officer, $officerClient, $product, '1001-LN-OFFICER-ARREARS', 'active');
    $otherArrears = loanWorkflowTrackedLoan($otherOfficer, $otherClient, $product, '1001-LN-OTHER-ARREARS', 'active');
    $ownDueToday = loanWorkflowTrackedLoan($officer, $officerClient, $product, '1001-LN-OFFICER-DUE', 'active');
    $otherDueToday = loanWorkflowTrackedLoan($otherOfficer, $otherClient, $product, '1001-LN-OTHER-DUE', 'active');
    $ownFuture = loanWorkflowTrackedLoan($officer, $officerClient, $product, '1001-LN-OFFICER-FUTURE', 'active');

    loanWorkflowSchedule($ownArrears, today()->subDays(2), 'overdue');
    loanWorkflowSchedule($otherArrears, today()->subDays(2), 'overdue');
    loanWorkflowSchedule($ownDueToday, today(), 'pending');
    loanWorkflowSchedule($otherDueToday, today(), 'pending');
    loanWorkflowSchedule($ownFuture, today()->addDay(), 'pending');
    LoanRepayment::create([
        'organization_id' => $organization->id,
        'loan_id' => $ownArrears->id,
        'client_id' => $officerClient->id,
        'branch_id' => $officer->branch_id,
        'received_by' => $admin->id,
        'amount' => 125000,
        'principal_paid' => 125000,
        'paid_at' => today(),
    ]);
    LoanRepayment::create([
        'organization_id' => $organization->id,
        'loan_id' => $otherArrears->id,
        'client_id' => $otherClient->id,
        'branch_id' => $otherOfficer->branch_id,
        'received_by' => $admin->id,
        'amount' => 95000,
        'principal_paid' => 95000,
        'paid_at' => today(),
    ]);

    $this->actingAs($officer)
        ->get(route('arrears.index'))
        ->assertSuccessful()
        ->assertSee('1001-LN-OFFICER-ARREARS')
        ->assertDontSee('1001-LN-OTHER-ARREARS')
        ->assertDontSee('Run Penalties');

    $this->actingAs($officer)
        ->get(route('loans.index'))
        ->assertSuccessful()
        ->assertSee('Search for a client or loan to view loan details.')
        ->assertDontSee('1001-LN-OFFICER-ARREARS')
        ->assertDontSee('1001-LN-OTHER-ARREARS');

    $this->actingAs($officer)
        ->get(route('loans.index', ['status' => 'active']))
        ->assertSuccessful()
        ->assertDontSee('1001-LN-OFFICER-ARREARS')
        ->assertDontSee('1001-LN-OTHER-ARREARS');

    $this->actingAs($officer)
        ->get(route('loans.index', ['search' => '1001-LN']))
        ->assertSuccessful()
        ->assertSee('1001-LN-OFFICER-ARREARS')
        ->assertSee('1001-LN-OTHER-ARREARS');

    $this->actingAs($officer)
        ->get(route('clients.index'))
        ->assertSuccessful()
        ->assertSee('Search for a client by name, client number, phone, or national ID.')
        ->assertDontSee($officerClient->client_number)
        ->assertDontSee($otherClient->client_number);

    $this->actingAs($officer)
        ->get(route('clients.index', ['status' => 'active']))
        ->assertSuccessful()
        ->assertDontSee($officerClient->client_number)
        ->assertDontSee($otherClient->client_number);

    $this->actingAs($officer)
        ->get(route('clients.index', ['search' => '256700110']))
        ->assertSuccessful()
        ->assertSee($officerClient->client_number)
        ->assertSee($otherClient->client_number);

    $this->actingAs($officer)
        ->get(route('loan-products.index'))
        ->assertSuccessful()
        ->assertSee($product->name)
        ->assertDontSee('New Product')
        ->assertDontSee('Edit');

    $officerCreateProductResponse = $this->actingAs($officer)->get(route('loan-products.create'));
    $officerEditProductResponse = $this->actingAs($officer)->get(route('loan-products.edit', $product));

    expect($officerCreateProductResponse->isForbidden() || $officerCreateProductResponse->isRedirect())->toBeTrue()
        ->and($officerEditProductResponse->isForbidden() || $officerEditProductResponse->isRedirect())->toBeTrue();

    $this->actingAs($officer)
        ->get(route('loans.show', $otherArrears))
        ->assertSuccessful()
        ->assertSee('1001-LN-OTHER-ARREARS');

    $this->actingAs($officer)
        ->get(route('clients.show', $otherClient))
        ->assertSuccessful()
        ->assertSee($otherClient->client_number);

    $manualPenaltyResponse = $this->actingAs($officer)
        ->post(route('arrears.apply-penalties'));
    expect($manualPenaltyResponse->isForbidden() || $manualPenaltyResponse->isRedirect())->toBeTrue();

    expect($this->actingAs($officer)->get(route('reports.show', 'loan-accounts-in-arrears'))->isSuccessful())->toBeFalse();

    $this->actingAs($officer)
        ->get(route('due-repayments.index'))
        ->assertSuccessful()
        ->assertSee('1001-LN-OFFICER-DUE')
        ->assertDontSee('1001-LN-OTHER-DUE')
        ->assertDontSee('1001-LN-OFFICER-FUTURE');

    $this->actingAs($officer)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Repayment Calendar')
        ->assertSee('Due today')
        ->assertSee('1001-LN-OFFICER-DUE')
        ->assertDontSee('Recent Loan Payments')
        ->assertDontSee('>Operations<', false)
        ->assertDontSee('Reports')
        ->assertDontSee('1001-LN-OTHER-DUE')
        ->assertDontSee('1001-LN-OFFICER-FUTURE');

    $recoveryRole = Role::firstOrCreate(['slug' => 'loan-recovery-officer'], ['name' => 'Loan Recovery Officer']);
    $recoveryRole->permissions()->syncWithoutDetaching([
        Permission::firstOrCreate(
            ['slug' => 'view-arrears'],
            ['organization_id' => $organization->id, 'name' => 'View Arrears'],
        )->id,
        Permission::firstOrCreate(
            ['slug' => 'manage-clients'],
            ['organization_id' => $organization->id, 'name' => 'Manage Clients'],
        )->id,
    ]);
    $recoveryOfficer = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $admin->branch_id,
        'role_id' => $recoveryRole->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->actingAs($recoveryOfficer)
        ->get(route('arrears.index'))
        ->assertSuccessful()
        ->assertSee('1001-LN-OFFICER-ARREARS')
        ->assertSee('1001-LN-OTHER-ARREARS');

    $this->actingAs($recoveryOfficer)
        ->get(route('loans.index'))
        ->assertSuccessful()
        ->assertSee('Search for a client or loan to view loan details.')
        ->assertDontSee('1001-LN-OFFICER-ARREARS')
        ->assertDontSee('1001-LN-OTHER-ARREARS');

    $this->actingAs($recoveryOfficer)
        ->get(route('loans.index', ['search' => '1001-LN']))
        ->assertSuccessful()
        ->assertSee('1001-LN-OFFICER-ARREARS')
        ->assertSee('1001-LN-OTHER-ARREARS');

    $this->actingAs($recoveryOfficer)
        ->get(route('clients.index'))
        ->assertSuccessful()
        ->assertSee('Search for a client by name, client number, phone, or national ID.')
        ->assertDontSee($officerClient->client_number)
        ->assertDontSee($otherClient->client_number);

    $this->actingAs($recoveryOfficer)
        ->get(route('clients.index', ['search' => '256700110']))
        ->assertSuccessful()
        ->assertSee($officerClient->client_number)
        ->assertSee($otherClient->client_number);

    $this->actingAs($recoveryOfficer)
        ->get(route('loan-products.index'))
        ->assertSuccessful()
        ->assertSee($product->name)
        ->assertDontSee('New Product')
        ->assertDontSee('Edit');

    $recoveryCreateProductResponse = $this->actingAs($recoveryOfficer)->get(route('loan-products.create'));
    $recoveryEditProductResponse = $this->actingAs($recoveryOfficer)->get(route('loan-products.edit', $product));

    expect($recoveryCreateProductResponse->isForbidden() || $recoveryCreateProductResponse->isRedirect())->toBeTrue()
        ->and($recoveryEditProductResponse->isForbidden() || $recoveryEditProductResponse->isRedirect())->toBeTrue();

    $this->actingAs($recoveryOfficer)
        ->get(route('loans.show', $ownArrears))
        ->assertSuccessful()
        ->assertSee('1001-LN-OFFICER-ARREARS')
        ->assertSee('Loan Schedule')
        ->assertSee('Repayments')
        ->assertSee('Guarantors')
        ->assertSee('Collateral')
        ->assertDontSee('Workflow Actions')
        ->assertDontSee('Administrative Actions')
        ->assertDontSee('Add guarantor')
        ->assertDontSee('Add collateral')
        ->assertDontSee('Loan Account Reports')
        ->assertDontSee('Transaction Authorisation Details');

    $this->actingAs($recoveryOfficer)
        ->get(route('loans.show', $ownDueToday))
        ->assertSuccessful()
        ->assertSee('1001-LN-OFFICER-DUE')
        ->assertSee('Loan Schedule')
        ->assertDontSee('Workflow Actions');

    $this->actingAs($recoveryOfficer)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Loans in arrears')
        ->assertDontSee('Loan portfolio')
        ->assertDontSee('>Operations<', false)
        ->assertDontSee('Reports');

    $this->actingAs($admin)
        ->get(route('arrears.index'))
        ->assertSuccessful()
        ->assertSee('Run Penalties')
        ->assertSee('1001-LN-OFFICER-ARREARS')
        ->assertSee('1001-LN-OTHER-ARREARS');

    $this->actingAs($admin)
        ->get(route('clients.index'))
        ->assertSuccessful()
        ->assertSee($officerClient->client_number)
        ->assertSee($otherClient->client_number);
});

it('limits cashier repayment visibility to unpaid installments due today at their branch', function () {
    $admin = loanWorkflowUser();
    $organization = loanWorkflowOrganizationWithModules($admin, ['loans', 'reports', 'teller']);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $product->update(['organization_id' => $organization->id]);
    $cashierRole = Role::firstOrCreate(['slug' => 'teller-cashier'], ['name' => 'Teller Cashier']);
    Permission::whereIn('slug', ['post-repayments', 'view-reports'])->pluck('id')->each(fn (int $permissionId) => $cashierRole->permissions()->syncWithoutDetaching([$permissionId]));
    $cashier = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $admin->branch_id, 'role_id' => $cashierRole->id, 'status' => 'active', 'is_active' => true]);
    $client = loanWorkflowClient($admin, '+256700120001');
    $client->update(['organization_id' => $organization->id]);
    $todayLoan = loanWorkflowTrackedLoan($admin, $client, $product, '1001-LN-CASHIER-TODAY', 'active');
    $futureLoan = loanWorkflowTrackedLoan($admin, $client, $product, '1001-LN-CASHIER-FUTURE', 'active');
    $paidLoan = loanWorkflowTrackedLoan($admin, $client, $product, '1001-LN-CASHIER-PAID', 'active');
    $approvedLoan = loanWorkflowTrackedLoan($admin, $client, $product, '1001-LN-CASHIER-APPROVED', 'approved');

    loanWorkflowSchedule($todayLoan, today(), 'pending');
    loanWorkflowSchedule($todayLoan, today()->subDays(3), 'overdue');
    loanWorkflowSchedule($todayLoan, today()->subDay(), 'overdue');
    loanWorkflowSchedule($futureLoan, today()->addDay(), 'pending');
    loanWorkflowSchedule($paidLoan, today(), 'paid', 0);
    loanWorkflowSchedule($approvedLoan, today(), 'pending');

    $otherBranch = Branch::create([
        'organization_id' => $organization->id,
        'code' => '1002',
        'name' => 'Other Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $otherClient = loanWorkflowClient($admin, '+256700120002');
    $otherClient->update(['organization_id' => $organization->id, 'branch_id' => $otherBranch->id]);
    $otherBranchLoan = loanWorkflowTrackedLoan($admin, $otherClient, $product, '1002-LN-CASHIER-OTHER', 'active');
    loanWorkflowSchedule($otherBranchLoan, today(), 'pending');

    $this->actingAs($cashier)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee("Today's Posted Transactions", false)
        ->assertSee('Due today')
        ->assertSee('Repayment Calendar')
        ->assertSee('1001-LN-CASHIER-TODAY')
        ->assertSee('UGX 600,000')
        ->assertSee('1 missed included')
        ->assertDontSee('Reports')
        ->assertDontSee('1001-LN-CASHIER-FUTURE')
        ->assertDontSee('1002-LN-CASHIER-OTHER');

    $this->actingAs($cashier)
        ->get(route('due-repayments.index', [
            'date' => today()->addDay()->toDateString(),
            'branch_id' => $otherBranch->id,
        ]))
        ->assertSuccessful()
        ->assertSee("Today's Collection Calls")
        ->assertSee('1001-LN-CASHIER-TODAY')
        ->assertDontSee('1001-LN-CASHIER-FUTURE')
        ->assertDontSee('1002-LN-CASHIER-OTHER')
        ->assertDontSee('Open total')
        ->assertSee('Amount to pay')
        ->assertSee('Missed UGX 300,000.00')
        ->assertSee('1 missed installment')
        ->assertSee('2 open installments')
        ->assertSee('UGX 600,000.00');

    expect($this->actingAs($cashier)->get(route('arrears.index'))->isSuccessful())->toBeFalse();

    expect($this->actingAs($cashier)->get(route('reports.show', 'loan-accounts-in-arrears'))->isSuccessful())->toBeFalse();
});

it('shows one due repayment demand per client with missed arrears included', function () {
    $user = loanWorkflowUser();
    $organization = loanWorkflowOrganizationWithModules($user, ['loans', 'reports']);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $product->update(['organization_id' => $organization->id]);
    $client = loanWorkflowClient($user, '+256700130001');
    $client->update([
        'organization_id' => $organization->id,
        'first_name' => 'Grace',
        'last_name' => 'Demand',
    ]);
    $arrearsOnlyClient = loanWorkflowClient($user, '+256700130002');
    $arrearsOnlyClient->update([
        'organization_id' => $organization->id,
        'first_name' => 'Arrears',
        'last_name' => 'Only',
    ]);

    $dueLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-DEMAND-DUE', 'active');
    $firstArrearsLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-DEMAND-ARR1', 'active');
    $secondArrearsLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-DEMAND-ARR2', 'active');
    $futureLoan = loanWorkflowTrackedLoan($user, $client, $product, '1001-LN-DEMAND-FUTURE', 'active');
    $arrearsOnlyLoan = loanWorkflowTrackedLoan($user, $arrearsOnlyClient, $product, '1001-LN-ARREARS-ONLY', 'active');

    loanWorkflowSchedule($dueLoan, today(), 'pending');
    loanWorkflowSchedule($firstArrearsLoan, today()->subDays(2), 'overdue');
    loanWorkflowSchedule($secondArrearsLoan, today()->subDays(5), 'overdue');
    loanWorkflowSchedule($futureLoan, today()->addDay(), 'pending');
    loanWorkflowSchedule($arrearsOnlyLoan, today()->subDays(3), 'overdue');

    $response = $this->actingAs($user)->get(route('due-repayments.index'));

    $response
        ->assertSuccessful()
        ->assertSee('Grace Demand')
        ->assertSee('1001-LN-DEMAND-DUE')
        ->assertSee('1001-LN-DEMAND-ARR1')
        ->assertSee('1001-LN-DEMAND-ARR2')
        ->assertSee('UGX 900,000.00')
        ->assertSee('2 missed installments')
        ->assertDontSee('1001-LN-DEMAND-FUTURE')
        ->assertDontSee('1001-LN-ARREARS-ONLY')
        ->assertDontSee('Arrears Only');

    expect(substr_count($response->getContent(), 'Grace Demand'))->toBe(1);
});

it('backfills and falls back to recent loan officers for legacy client ownership', function () {
    $admin = loanWorkflowUser();
    $organization = loanWorkflowOrganizationWithModules($admin, ['clients', 'loans', 'reports']);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);
    $product->update(['organization_id' => $organization->id]);
    $officerRole = Role::firstOrCreate(['slug' => 'loan-officer'], ['name' => 'Loan Officer']);
    Permission::whereIn('slug', ['manage-clients', 'manage-loans', 'view-reports'])->pluck('id')->each(fn (int $permissionId) => $officerRole->permissions()->syncWithoutDetaching([$permissionId]));
    $firstOfficer = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $admin->branch_id, 'role_id' => $officerRole->id, 'status' => 'active', 'is_active' => true]);
    $secondOfficer = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $admin->branch_id, 'role_id' => $officerRole->id, 'status' => 'active', 'is_active' => true]);
    $assignedClient = loanWorkflowClient($firstOfficer, '+256700140001');
    $fallbackClient = loanWorkflowClient($firstOfficer, '+256700140002');
    $otherRecentClient = loanWorkflowClient($firstOfficer, '+256700140003');
    $unownedClient = loanWorkflowClient($firstOfficer, '+256700140004');

    $assignedClient->update(['organization_id' => $organization->id, 'officer_id' => $firstOfficer->id, 'first_name' => 'Assigned', 'last_name' => 'Client']);
    $fallbackClient->update(['organization_id' => $organization->id, 'officer_id' => null, 'first_name' => 'Fallback', 'last_name' => 'Client']);
    $otherRecentClient->update(['organization_id' => $organization->id, 'officer_id' => null, 'first_name' => 'Other', 'last_name' => 'Recent']);
    $unownedClient->update(['organization_id' => $organization->id, 'officer_id' => null, 'first_name' => 'Unowned', 'last_name' => 'Client']);

    loanWorkflowTrackedLoan($firstOfficer, $fallbackClient, $product, '1001-LN-FALLBACK-RECENT', 'active');
    loanWorkflowTrackedLoan($firstOfficer, $otherRecentClient, $product, '1001-LN-OLDER-OFFICER', 'active');
    loanWorkflowTrackedLoan($secondOfficer, $otherRecentClient, $product, '1001-LN-NEWER-OFFICER', 'active');

    $this->actingAs($firstOfficer)
        ->get(route('loans.create'))
        ->assertSuccessful()
        ->assertSee('Assigned Client')
        ->assertSee('Fallback Client')
        ->assertSee('Other Recent')
        ->assertDontSee('Unowned Client');

    $clientPolicy = app(ClientPolicy::class);

    expect($clientPolicy->view($firstOfficer, $otherRecentClient))->toBeTrue()
        ->and($clientPolicy->view($firstOfficer, $unownedClient))->toBeTrue();

    $this->actingAs($firstOfficer)
        ->post(route('loans.store'), [
            'client_id' => $unownedClient->id,
            'loan_product_id' => $product->id,
            'requested_amount' => 300000,
            'term_periods' => 1,
            'repayment_frequency' => 'monthly',
            'purpose' => 'Unauthorized client check',
        ])
        ->assertSessionHasErrors('client_id');

    $migration = include database_path('migrations/2026_06_24_113711_backfill_client_officers_from_recent_loans.php');
    $migration->up();

    expect($fallbackClient->fresh()->officer_id)->toBe($firstOfficer->id)
        ->and($otherRecentClient->fresh()->officer_id)->toBe($secondOfficer->id)
        ->and($unownedClient->fresh()->officer_id)->toBeNull();
});
