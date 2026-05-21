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
    LoanRepaymentAllocation::create([
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
        ->and($schedule->fresh()->total_paid)->toBe('0.00')
        ->and($schedule->fresh()->outstanding_amount)->toBe('100000.00')
        ->and($repayment->fresh()->status)->toBe('reversed')
        ->and($transaction->fresh()->status)->toBe('reversed');
});
