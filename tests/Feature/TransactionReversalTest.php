<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Client;
use App\Models\FinancialDay;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\LoanRepaymentAllocation;
use App\Models\LoanSchedule;
use App\Models\MakerCheckerRequest;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\TransactionReversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows admins to request reversal for a posted transaction from a locked backdated business day', function () {
    $branch = Branch::create([
        'code' => '1001',
        'name' => 'Main Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'name' => 'Super Admin',
        'slug' => 'super-admin',
    ]);
    $admin = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $type = TransactionType::create([
        'name' => 'Deposit',
        'code' => 'deposit',
        'direction' => 'credit',
        'affects_cash_drawer' => true,
        'is_active' => true,
    ]);
    $postedAt = today()->subDays(5);
    $transaction = Transaction::create([
        'transaction_type_id' => $type->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'reference' => 'TX-BACKDATED-001',
        'amount' => 50000,
        'balance_after' => 50000,
        'currency' => 'UGX',
        'description' => 'Backdated posting',
        'status' => 'posted',
        'posted_at' => $postedAt,
        'created_at' => $postedAt,
    ]);

    FinancialDay::create([
        'branch_id' => $branch->id,
        'business_date' => $postedAt->toDateString(),
        'status' => 'locked',
        'opened_by' => $admin->id,
        'closed_by' => $admin->id,
        'opened_at' => $postedAt,
        'closed_at' => $postedAt,
    ]);

    $this->actingAs($admin)
        ->from(route('receipts.show', $transaction))
        ->post(route('transactions.reversal-requests.store', $transaction), [
            'reason' => 'Correcting a backdated transaction',
        ])
        ->assertRedirect(route('receipts.show', $transaction))
        ->assertSessionHas('status', 'Reversal request submitted for admin or supervisor approval.');

    $request = MakerCheckerRequest::firstOrFail();

    expect($request->action)->toBe('transaction_reversal')
        ->and($request->subject_id)->toBe($transaction->id)
        ->and($request->status)->toBe('pending')
        ->and($request->reason)->toBe('Correcting a backdated transaction');
});

it('links listed posted transactions to their receipt details', function () {
    $branch = Branch::create([
        'code' => '1002',
        'name' => 'Second Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'name' => 'Super Admin',
        'slug' => 'super-admin',
    ]);
    $admin = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $client = Client::create([
        'branch_id' => $branch->id,
        'client_number' => '1002-C000001',
        'first_name' => 'Sarah',
        'last_name' => 'Nabukeera',
        'phone' => '+256700000002',
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $accountType = AccountType::create([
        'name' => 'Savings',
        'code' => 'SAV',
        'category' => 'savings',
        'minimum_balance' => 0,
        'is_active' => true,
    ]);
    $account = Account::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'account_type_id' => $accountType->id,
        'account_number' => '1002-SAV-000001',
        'account_name' => 'Sarah Nabukeera Savings',
        'status' => 'active',
        'balance' => 50000,
        'opened_at' => today(),
    ]);
    $type = TransactionType::create([
        'name' => 'Deposit',
        'code' => 'deposit',
        'direction' => 'credit',
        'affects_cash_drawer' => true,
        'is_active' => true,
    ]);
    $transaction = Transaction::create([
        'account_id' => $account->id,
        'transaction_type_id' => $type->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'reference' => 'TX-LINK-001',
        'amount' => 50000,
        'balance_after' => 50000,
        'currency' => 'UGX',
        'description' => 'Linked posting',
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('reports.show', 'user-posted-transactions'))
        ->assertSuccessful()
        ->assertSee('TX-LINK-001')
        ->assertSee('href="'.route('receipts.show', $transaction).'"', false);
});

it('filters posted transaction reports by description', function () {
    $branch = Branch::create([
        'code' => '1004',
        'name' => 'Report Search Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'name' => 'Super Admin',
        'slug' => 'super-admin',
    ]);
    $admin = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $client = Client::create([
        'branch_id' => $branch->id,
        'client_number' => '1004-C000001',
        'first_name' => 'Grace',
        'last_name' => 'Akello',
        'phone' => '+256700000004',
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $accountType = AccountType::create([
        'name' => 'Savings',
        'code' => 'SAV',
        'category' => 'savings',
        'minimum_balance' => 0,
        'is_active' => true,
    ]);
    $account = Account::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'account_type_id' => $accountType->id,
        'account_number' => '1004-SAV-000001',
        'account_name' => 'Grace Akello Savings',
        'status' => 'active',
        'balance' => 150000,
        'opened_at' => today(),
    ]);
    $type = TransactionType::create([
        'name' => 'Deposit',
        'code' => 'deposit',
        'direction' => 'credit',
        'affects_cash_drawer' => true,
        'is_active' => true,
    ]);

    Transaction::create([
        'account_id' => $account->id,
        'transaction_type_id' => $type->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'reference' => 'TX-DESC-MATCH',
        'amount' => 100000,
        'balance_after' => 100000,
        'currency' => 'UGX',
        'description' => 'School fees savings deposit',
        'status' => 'posted',
        'posted_at' => now(),
    ]);
    Transaction::create([
        'account_id' => $account->id,
        'transaction_type_id' => $type->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'reference' => 'TX-DESC-HIDDEN',
        'amount' => 50000,
        'balance_after' => 150000,
        'currency' => 'UGX',
        'description' => 'Market collection deposit',
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('reports.show', ['report' => 'user-posted-transactions', 'search' => 'School fees']))
        ->assertSuccessful()
        ->assertSee('TX-DESC-MATCH')
        ->assertSee('School fees savings deposit')
        ->assertDontSee('TX-DESC-HIDDEN')
        ->assertDontSee('Market collection deposit');
});

it('reopens a closed loan account when its payoff repayment is reversed', function () {
    $branch = Branch::create([
        'code' => '1003',
        'name' => 'Loan Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'name' => 'Super Admin',
        'slug' => 'super-admin',
    ]);
    $admin = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $client = Client::create([
        'branch_id' => $branch->id,
        'client_number' => '1003-C000001',
        'first_name' => 'Moses',
        'last_name' => 'Kato',
        'phone' => '+256700000003',
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $accountType = AccountType::create([
        'name' => 'Loan',
        'code' => 'LN',
        'category' => 'loan',
        'minimum_balance' => 0,
        'is_active' => true,
    ]);
    $loanAccount = Account::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'account_type_id' => $accountType->id,
        'account_number' => '1003-LN-000001',
        'account_name' => 'Moses Kato Loan',
        'status' => 'closed',
        'balance' => 0,
        'opened_at' => today(),
    ]);
    $loanProduct = LoanProduct::create([
        'name' => 'Market Loan',
        'code' => 'ML-REV',
        'annual_interest_rate' => 24,
        'interest_method' => 'declining_balance',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 1,
        'penalty_type' => 'percentage',
        'penalty_rate' => 0,
        'status' => 'active',
        'is_active' => true,
    ]);
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $loanProduct->id,
        'loan_account_id' => $loanAccount->id,
        'loan_number' => 'LN-REVERSE-001',
        'requested_amount' => 100000,
        'approved_amount' => 100000,
        'principal_balance' => 0,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'status' => 'closed',
    ]);
    $schedule = LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today()->addMonth(),
        'principal_due' => 90000,
        'interest_due' => 10000,
        'fees_due' => 0,
        'penalty_due' => 0,
        'total_due' => 100000,
        'principal_paid' => 90000,
        'interest_paid' => 10000,
        'fees_paid' => 0,
        'penalty_paid' => 0,
        'total_paid' => 100000,
        'amount_paid' => 100000,
        'outstanding_amount' => 0,
        'balance_due' => 0,
        'status' => 'paid',
    ]);
    $type = TransactionType::create([
        'name' => 'Loan Repayment',
        'code' => 'loan_repayment',
        'direction' => 'credit',
        'affects_cash_drawer' => true,
        'is_active' => true,
    ]);
    $transaction = Transaction::create([
        'account_id' => $loanAccount->id,
        'transaction_type_id' => $type->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'reference' => 'TX-LOAN-PAYOFF-001',
        'amount' => 100000,
        'balance_after' => 0,
        'currency' => 'UGX',
        'description' => 'Mistaken payoff',
        'status' => 'posted',
        'posted_at' => now(),
    ]);
    $repayment = LoanRepayment::create([
        'loan_id' => $loan->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'transaction_id' => $transaction->id,
        'received_by' => $admin->id,
        'amount' => 100000,
        'payment_method' => 'cash',
        'reference' => $transaction->reference,
        'status' => 'successful',
        'principal_paid' => 90000,
        'interest_paid' => 10000,
        'fees_paid' => 0,
        'penalty_paid' => 0,
        'paid_at' => today(),
    ]);
    $allocation = LoanRepaymentAllocation::create([
        'loan_repayment_id' => $repayment->id,
        'loan_schedule_id' => $schedule->id,
        'principal_paid' => 90000,
        'interest_paid' => 10000,
        'fees_paid' => 0,
        'penalty_paid' => 0,
        'total_paid' => 100000,
    ]);
    $request = MakerCheckerRequest::create([
        'branch_id' => $branch->id,
        'subject_type' => $transaction->getMorphClass(),
        'subject_id' => $transaction->id,
        'requested_by' => $admin->id,
        'action' => 'transaction_reversal',
        'required_permission' => 'approve-reversals',
        'amount' => 100000,
        'status' => 'pending',
        'reason' => 'Mistaken repayment amount',
    ]);

    app(TransactionReversalService::class)->approve($request, $admin);

    expect($loan->fresh()->status)->toBe('active')
        ->and($loan->fresh()->principal_balance)->toBe('90000.00')
        ->and($loan->fresh()->interest_balance)->toBe('10000.00')
        ->and($loanAccount->fresh()->status)->toBe('active')
        ->and($loanAccount->fresh()->balance)->toBe('100000.00')
        ->and($schedule->fresh()->status)->toBe('pending')
        ->and($schedule->fresh()->last_reversal_by)->toBe($admin->id)
        ->and($schedule->fresh()->total_paid)->toBe('0.00')
        ->and($schedule->fresh()->outstanding_amount)->toBe('100000.00')
        ->and($repayment->fresh()->status)->toBe('reversed')
        ->and($repayment->fresh()->reversed_by)->toBe($admin->id)
        ->and($repayment->fresh()->reversal_reason)->toBe('Mistaken repayment amount')
        ->and($repayment->fresh()->reversal_transaction_id)->not->toBeNull()
        ->and($allocation->fresh()->status)->toBe('reversed')
        ->and($allocation->fresh()->reversed_by)->toBe($admin->id)
        ->and($transaction->fresh()->status)->toBe('reversed');
});

it('allows an admin to reopen a closed loan and clear all paid installments from the loan page action', function () {
    $branch = Branch::create([
        'code' => '1004',
        'name' => 'Reopen Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'name' => 'Super Admin',
        'slug' => 'super-admin',
    ]);
    $admin = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $client = Client::create([
        'branch_id' => $branch->id,
        'client_number' => '1004-C000001',
        'first_name' => 'Allen',
        'last_name' => 'Atim',
        'phone' => '+256700000004',
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $accountType = AccountType::create([
        'name' => 'Loan',
        'code' => 'LN-ACTION',
        'category' => 'loan',
        'minimum_balance' => 0,
        'is_active' => true,
    ]);
    $loanAccount = Account::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'account_type_id' => $accountType->id,
        'account_number' => '1004-LN-000001',
        'account_name' => 'Allen Atim Loan',
        'status' => 'closed',
        'balance' => 0,
        'opened_at' => today(),
    ]);
    $loanProduct = LoanProduct::create([
        'name' => 'Business Loan',
        'code' => 'BL-REV',
        'annual_interest_rate' => 24,
        'interest_method' => 'declining_balance',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 2,
        'penalty_type' => 'percentage',
        'penalty_rate' => 0,
        'status' => 'active',
        'is_active' => true,
    ]);
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $loanProduct->id,
        'loan_account_id' => $loanAccount->id,
        'loan_number' => 'LN-REOPEN-001',
        'requested_amount' => 200000,
        'approved_amount' => 200000,
        'principal_balance' => 0,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 2,
        'repayment_frequency' => 'monthly',
        'status' => 'closed',
    ]);

    foreach ([1, 2] as $installment) {
        LoanSchedule::create([
            'loan_id' => $loan->id,
            'installment_number' => $installment,
            'due_date' => today()->addMonths($installment),
            'principal_due' => 100000,
            'interest_due' => 10000,
            'fees_due' => 0,
            'penalty_due' => 0,
            'total_due' => 110000,
            'principal_paid' => 100000,
            'interest_paid' => 10000,
            'fees_paid' => 0,
            'penalty_paid' => 0,
            'total_paid' => 110000,
            'amount_paid' => 110000,
            'outstanding_amount' => 0,
            'balance_due' => 0,
            'status' => 'paid',
        ]);
    }

    LoanRepayment::create([
        'loan_id' => $loan->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'received_by' => $admin->id,
        'amount' => 220000,
        'payment_method' => 'cash',
        'reference' => 'REOPEN-PAYOFF-001',
        'status' => 'successful',
        'principal_paid' => 200000,
        'interest_paid' => 20000,
        'fees_paid' => 0,
        'penalty_paid' => 0,
        'paid_at' => today(),
    ]);

    $this->actingAs($admin)
        ->get(route('loans.show', $loan))
        ->assertSuccessful()
        ->assertSee('Reopen loan and clear installments');

    $this->actingAs($admin)
        ->from(route('loans.show', $loan))
        ->post(route('loans.reopen', $loan), [
            'reason' => 'Mistaken payoff needs to be reopened',
        ])
        ->assertRedirect(route('loans.show', $loan))
        ->assertSessionHas('status', 'Loan reopened and paid installments cleared.');

    expect($loan->fresh()->status)->toBe('active')
        ->and($loan->fresh()->principal_balance)->toBe('200000.00')
        ->and($loan->fresh()->interest_balance)->toBe('20000.00')
        ->and($loanAccount->fresh()->status)->toBe('active')
        ->and($loanAccount->fresh()->balance)->toBe('220000.00')
        ->and($loan->schedules()->where('status', 'pending')->count())->toBe(2)
        ->and((float) $loan->schedules()->sum('total_paid'))->toBe(0.0)
        ->and((float) $loan->schedules()->sum('outstanding_amount'))->toBe(220000.0)
        ->and($loan->repayments()->where('status', 'reversed')->count())->toBe(1);
});

it('allows an admin to reactivate an inactive loan account for an in arrears loan', function () {
    $branch = Branch::create([
        'code' => '1005',
        'name' => 'Arrears Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'name' => 'Super Admin',
        'slug' => 'super-admin',
    ]);
    $admin = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $client = Client::create([
        'branch_id' => $branch->id,
        'client_number' => '1005-C000001',
        'first_name' => 'Doreen',
        'last_name' => 'Adoch',
        'phone' => '+256700000005',
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $accountType = AccountType::create([
        'name' => 'Loan',
        'code' => 'LN-ARREARS',
        'category' => 'loan',
        'minimum_balance' => 0,
        'is_active' => true,
    ]);
    $loanAccount = Account::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'account_type_id' => $accountType->id,
        'account_number' => '1005-LN-000001',
        'account_name' => 'Doreen Adoch Loan',
        'status' => 'closed',
        'balance' => 0,
        'opened_at' => today(),
    ]);
    $loanProduct = LoanProduct::create([
        'name' => 'Arrears Loan',
        'code' => 'AL-REV',
        'annual_interest_rate' => 24,
        'interest_method' => 'declining_balance',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 1,
        'penalty_type' => 'percentage',
        'penalty_rate' => 0,
        'status' => 'active',
        'is_active' => true,
    ]);
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $loanProduct->id,
        'loan_account_id' => $loanAccount->id,
        'loan_number' => 'LN-ARREARS-001',
        'requested_amount' => 100000,
        'approved_amount' => 100000,
        'principal_balance' => 90000,
        'interest_balance' => 10000,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'status' => 'in_arrears',
    ]);
    LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today()->subMonth(),
        'principal_due' => 90000,
        'interest_due' => 10000,
        'fees_due' => 0,
        'penalty_due' => 0,
        'total_due' => 100000,
        'principal_paid' => 0,
        'interest_paid' => 0,
        'fees_paid' => 0,
        'penalty_paid' => 0,
        'total_paid' => 0,
        'amount_paid' => 0,
        'outstanding_amount' => 100000,
        'balance_due' => 100000,
        'status' => 'overdue',
    ]);

    $this->actingAs($admin)
        ->get(route('loans.show', $loan))
        ->assertSuccessful()
        ->assertSee('Reactivate loan')
        ->assertSee('data-loan-reopen-modal', false);

    $this->actingAs($admin)
        ->from(route('loans.show', $loan))
        ->post(route('loans.reopen', $loan), [
            'reason' => 'Loan is in arrears but the loan account is closed',
        ])
        ->assertRedirect(route('loans.show', $loan))
        ->assertSessionHas('status', 'Loan account reactivated.');

    expect($loan->fresh()->status)->toBe('in_arrears')
        ->and($loan->fresh()->principal_balance)->toBe('90000.00')
        ->and($loan->fresh()->interest_balance)->toBe('10000.00')
        ->and($loanAccount->fresh()->status)->toBe('active')
        ->and($loanAccount->fresh()->balance)->toBe('100000.00')
        ->and((float) $loan->schedules()->sum('total_paid'))->toBe(0.0);
});

it('allows an admin to reactivate a disbursed loan and its inactive loan account', function () {
    $branch = Branch::create([
        'code' => '1007',
        'name' => 'Disbursed Repair Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'name' => 'Super Admin',
        'slug' => 'super-admin',
    ]);
    $admin = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $client = Client::create([
        'branch_id' => $branch->id,
        'client_number' => '1007-C000001',
        'first_name' => 'Jane',
        'last_name' => 'Achen',
        'phone' => '+256700000007',
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $accountType = AccountType::create([
        'name' => 'Loan',
        'code' => 'LN-DISB',
        'category' => 'loan',
        'minimum_balance' => 0,
        'is_active' => true,
    ]);
    $loanAccount = Account::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'account_type_id' => $accountType->id,
        'account_number' => '1007-LN-000001',
        'account_name' => 'Jane Achen Loan',
        'status' => 'closed',
        'balance' => 0,
        'opened_at' => today(),
    ]);
    $loanProduct = LoanProduct::create([
        'name' => 'Monthly Loan',
        'code' => 'ML-REACT',
        'annual_interest_rate' => 20,
        'interest_method' => 'declining_balance',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 1,
        'penalty_type' => 'percentage',
        'penalty_rate' => 0,
        'status' => 'active',
        'is_active' => true,
    ]);
    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $loanProduct->id,
        'loan_account_id' => $loanAccount->id,
        'loan_number' => 'LN-DISBURSED-001',
        'requested_amount' => 100000,
        'approved_amount' => 100000,
        'principal_balance' => 95000,
        'interest_balance' => 5000,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 20,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'status' => 'disbursed',
        'approved_at' => today(),
        'disbursed_at' => today(),
    ]);

    $this->actingAs($admin)
        ->get(route('loans.show', $loan))
        ->assertSuccessful()
        ->assertSee('Reactivate loan')
        ->assertSee('data-loan-reopen-modal', false);

    $this->actingAs($admin)
        ->from(route('loans.show', $loan))
        ->post(route('loans.reopen', $loan), [
            'reason' => 'Loan was disbursed but the loan account is inactive',
        ])
        ->assertRedirect(route('loans.show', $loan))
        ->assertSessionHas('status', 'Loan account reactivated.');

    $freshLoan = $loan->fresh();
    $reactivatedAccount = $loanAccount->fresh();

    expect($freshLoan->status)->toBe('active')
        ->and($reactivatedAccount->status)->toBe('active')
        ->and((float) $reactivatedAccount->balance)->toBe((float) $freshLoan->principal_balance + (float) $freshLoan->interest_balance + (float) $freshLoan->fees_balance + (float) $freshLoan->penalty_balance);
});

it('allows an organization admin to repair disbursed loan terms and regenerate the schedule', function () {
    $organization = Organization::create([
        'name' => 'Repair SACCO',
        'slug' => 'repair-sacco',
        'status' => 'active',
    ]);
    $loansModule = Module::create([
        'name' => 'Loans',
        'slug' => 'loans',
        'category' => 'operations',
        'is_core' => false,
        'status' => 'active',
    ]);
    OrganizationModule::create([
        'organization_id' => $organization->id,
        'module_id' => $loansModule->id,
        'is_enabled' => true,
        'enabled_at' => now(),
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => '1006',
        'name' => 'Repair Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Organization Admin',
        'slug' => 'organization-admin',
    ]);
    $admin = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => '1006-C000001',
        'first_name' => 'Peter',
        'last_name' => 'Otim',
        'phone' => '+256700000006',
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $accountType = AccountType::create([
        'organization_id' => $organization->id,
        'name' => 'Loan',
        'code' => 'LN-REPAIR',
        'category' => 'loan',
        'minimum_balance' => 0,
        'is_active' => true,
    ]);
    $loanAccount = Account::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'account_type_id' => $accountType->id,
        'account_number' => '1006-LN-000001',
        'account_name' => 'Peter Otim Loan',
        'status' => 'active',
        'balance' => 100000,
        'opened_at' => today(),
    ]);
    $weeklyProduct = LoanProduct::create([
        'organization_id' => $organization->id,
        'name' => 'Weekly 20',
        'code' => 'WEEKLY20',
        'annual_interest_rate' => 20,
        'interest_method' => 'declining_balance',
        'repayment_frequency' => 'weekly',
        'min_amount' => 100,
        'max_amount' => 1000000,
        'min_term' => 1,
        'max_term' => 52,
        'default_term_months' => 20,
        'penalty_type' => 'percentage',
        'penalty_rate' => 0,
        'status' => 'active',
        'is_active' => true,
    ]);
    $monthlyProduct = LoanProduct::create([
        'organization_id' => $organization->id,
        'name' => 'Monthly 20',
        'code' => 'MONTHLY20',
        'annual_interest_rate' => 20,
        'interest_method' => 'declining_balance',
        'repayment_frequency' => 'monthly',
        'min_amount' => 100,
        'max_amount' => 1000000,
        'min_term' => 1,
        'max_term' => 24,
        'default_term_months' => 1,
        'penalty_type' => 'percentage',
        'penalty_rate' => 0,
        'status' => 'active',
        'is_active' => true,
    ]);
    $loan = Loan::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $weeklyProduct->id,
        'loan_account_id' => $loanAccount->id,
        'loan_number' => 'LN-REPAIR-001',
        'requested_amount' => 100000,
        'approved_amount' => 100000,
        'principal_balance' => 100000,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 20,
        'term_periods' => 20,
        'repayment_frequency' => 'weekly',
        'status' => 'disbursed',
        'approved_at' => today(),
        'disbursed_at' => today(),
    ]);

    foreach (range(1, 20) as $installment) {
        LoanSchedule::create([
            'loan_id' => $loan->id,
            'installment_number' => $installment,
            'due_date' => today()->addWeeks($installment),
            'principal_due' => 5000,
            'interest_due' => 1000,
            'total_due' => 6000,
            'outstanding_amount' => 6000,
            'balance_due' => 6000,
            'status' => 'pending',
        ]);
    }

    $this->actingAs($admin)
        ->get(route('loans.show', $loan))
        ->assertSuccessful()
        ->assertSee('Repair loan')
        ->assertSee('data-loan-repair-modal', false);

    $this->actingAs($admin)
        ->from(route('loans.show', $loan))
        ->post(route('loans.repair-terms', $loan), [
            'loan_product_id' => $monthlyProduct->id,
            'approved_amount' => 100000,
            'term_periods' => 1,
            'repayment_frequency' => 'monthly',
            'schedule_start_date' => today()->toDateString(),
            'reason' => 'Correcting WEEKLY20 to MONTHLY20 for one month',
        ])
        ->assertRedirect(route('loans.show', $loan))
        ->assertSessionHas('status', 'Loan terms repaired and repayment schedule recalculated.');

    $loan->refresh();

    expect($loan->loan_product_id)->toBe($monthlyProduct->id)
        ->and($loan->repayment_frequency)->toBe('monthly')
        ->and($loan->term_periods)->toBe(1)
        ->and($loan->schedules()->count())->toBe(1)
        ->and((float) $loan->schedules()->sum('principal_due'))->toBe(100000.0)
        ->and($loanAccount->fresh()->status)->toBe('active')
        ->and((float) $loanAccount->fresh()->balance)->toBe((float) $loan->principal_balance + (float) $loan->interest_balance);
});
