<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\LedgerAccountStatementService;
use App\Services\LedgerChargeService;
use App\Services\TransactionPostingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deducts non-cash merchant charges from the payment ledger while applying the full loan repayment', function () {
    [$organization, $branch, $user] = nonCashLedgerChargesContext();
    $loanAccountType = AccountType::create([
        'organization_id' => $organization->id,
        'name' => 'Loan',
        'code' => 'LN',
        'category' => 'loan',
        'is_active' => true,
    ]);
    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => 'NCL-001',
        'first_name' => 'Joan',
        'last_name' => 'Nambi',
        'phone' => '0700000100',
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $loanAccount = Account::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'account_type_id' => $loanAccountType->id,
        'account_number' => 'LN-NCL-001',
        'account_name' => 'Joan Nambi Loan',
        'status' => 'active',
        'balance' => 100_000,
        'opened_at' => today(),
    ]);
    $loanProduct = LoanProduct::create([
        'organization_id' => $organization->id,
        'name' => 'Merchant Test Loan',
        'code' => 'MTL',
        'annual_interest_rate' => 0,
        'interest_method' => 'flat',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 1,
        'penalty_type' => 'fixed',
        'penalty_rate' => 0,
        'status' => 'active',
        'is_active' => true,
    ]);
    $loan = Loan::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $loanProduct->id,
        'loan_account_id' => $loanAccount->id,
        'loan_number' => 'LN-NCL-001',
        'requested_amount' => 100_000,
        'approved_amount' => 100_000,
        'principal_balance' => 100_000,
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
        'principal_due' => 100_000,
        'interest_due' => 0,
        'fees_due' => 0,
        'penalty_due' => 0,
        'total_due' => 100_000,
        'outstanding_amount' => 100_000,
        'balance_due' => 100_000,
        'status' => 'pending',
    ]);
    $merchant = ChartAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'code' => 'MTN-NONCASH',
        'name' => 'MTN Non-Cash Clearing',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'drawer_direction' => 'none',
        'is_active' => true,
    ]);
    $type = TransactionType::create([
        'organization_id' => $organization->id,
        'name' => 'Loan Repayment',
        'code' => 'loan_repayment',
        'direction' => 'credit',
        'affects_cash_drawer' => false,
        'is_active' => true,
    ]);

    $transaction = app(TransactionPostingService::class)->postNonCash(
        $loanAccount,
        $type,
        $merchant,
        100_000,
        $user,
        'Merchant-code loan repayment',
        today(),
        1_000,
        LedgerChargeService::MODE_DEDUCT_FROM_AMOUNT,
    );
    $merchantStatement = app(LedgerAccountStatementService::class)->build(
        $merchant,
        CarbonImmutable::parse(today()->toDateString()),
        CarbonImmutable::parse(today()->toDateString()),
    );
    $chargeAccount = ChartAccount::query()->where('code', $branch->code.'-5295')->firstOrFail();

    expect((float) $transaction->amount)->toBe(100000.0)
        ->and((float) $transaction->charges)->toBe(1000.0)
        ->and($transaction->charge_mode)->toBe(LedgerChargeService::MODE_DEDUCT_FROM_AMOUNT)
        ->and((float) $loan->fresh()->principal_balance)->toBe(0.0)
        ->and((float) $loanAccount->fresh()->balance)->toBe(0.0)
        ->and($merchantStatement['period_debits'])->toBe(99000.0)
        ->and($merchantStatement['closing_balance'])->toBe(99000.0)
        ->and((float) $chargeAccount->journalLines()->sum('debit'))->toBe(1000.0);
});

it('keeps non-cash merchant ledger amount whole when charges are added on top', function () {
    [$organization, $branch, $user] = nonCashLedgerChargesContext();
    $accountType = AccountType::create([
        'organization_id' => $organization->id,
        'name' => 'Savings',
        'code' => 'SAV',
        'category' => 'savings',
        'is_active' => true,
    ]);
    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => 'NCS-001',
        'first_name' => 'Paul',
        'last_name' => 'Kato',
        'phone' => '0700000101',
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $account = Account::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'account_type_id' => $accountType->id,
        'account_number' => 'SAV-NCS-001',
        'account_name' => 'Paul Kato Savings',
        'status' => 'active',
        'balance' => 0,
        'opened_at' => today(),
    ]);
    $merchant = ChartAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'code' => 'AIRTEL-NONCASH',
        'name' => 'Airtel Non-Cash Clearing',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'drawer_direction' => 'none',
        'is_active' => true,
    ]);
    $type = TransactionType::create([
        'organization_id' => $organization->id,
        'name' => 'Deposit',
        'code' => 'deposit',
        'direction' => 'credit',
        'affects_cash_drawer' => false,
        'is_active' => true,
    ]);

    $transaction = app(TransactionPostingService::class)->postNonCash(
        $account,
        $type,
        $merchant,
        50_000,
        $user,
        'Merchant-code savings deposit',
        today(),
        1_000,
        LedgerChargeService::MODE_ADD_TO_AMOUNT,
    );
    $merchantStatement = app(LedgerAccountStatementService::class)->build(
        $merchant,
        CarbonImmutable::parse(today()->toDateString()),
        CarbonImmutable::parse(today()->toDateString()),
    );
    $chargeAccount = ChartAccount::query()->where('code', $branch->code.'-5295')->firstOrFail();

    expect((float) $account->fresh()->balance)->toBe(50000.0)
        ->and($transaction->charge_mode)->toBe(LedgerChargeService::MODE_ADD_TO_AMOUNT)
        ->and($merchantStatement['period_debits'])->toBe(50000.0)
        ->and($merchantStatement['closing_balance'])->toBe(50000.0)
        ->and((float) $chargeAccount->journalLines()->sum('debit'))->toBe(1000.0);
});

function nonCashLedgerChargesContext(): array
{
    $organization = Organization::create([
        'name' => 'Non-Cash Charges SACCO',
        'slug' => fake()->unique()->slug(),
        'status' => 'active',
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'NCC',
        'name' => 'Non-Cash Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Organization Admin',
        'slug' => 'organization-admin',
    ]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    return [$organization, $branch, $user];
}
