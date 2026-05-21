<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\Guarantor;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TellerDrawer;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\LoanService;
use App\Services\TransactionPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;

uses(RefreshDatabase::class);

function loanWorkflowUser(): User
{
    $branch = Branch::create(['code' => '1001', 'name' => 'Luweero', 'is_active' => true, 'status' => 'active']);
    $permissions = collect(['manage-loans', 'approve-loans', 'disburse-loans', 'post-repayments', 'view-reports'])
        ->map(fn (string $slug) => Permission::create(['name' => str($slug)->headline(), 'slug' => $slug]));
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
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
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

it('approves and disburses a loan with guarantor checks and a repayment schedule', function () {
    $user = loanWorkflowUser();
    $client = loanWorkflowClient($user);
    $guarantorClient = loanWorkflowClient($user, '+256700000002');
    $product = loanWorkflowProduct();
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

    expect($loan->status)->toBe('active')
        ->and($loan->schedules)->toHaveCount(6)
        ->and($loan->disbursements)->toHaveCount(1)
        ->and((float) $loan->interest_balance)->toBeGreaterThan(0.0);
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

it('shows a loan when the legacy guarantors payload is empty', function () {
    $user = loanWorkflowUser();
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct(['require_guarantors' => false, 'minimum_guarantors' => 0]);

    $loan = Loan::create([
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
    $client = loanWorkflowClient($user);
    $product = loanWorkflowProduct();

    $loan = Loan::create([
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
