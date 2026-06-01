<?php

use App\Jobs\PostInterest;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanPenalty;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SystemSetting;
use App\Services\LoanService;
use App\Services\SavingsInterestService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function automaticPostingBranch(): Branch
{
    $branch = Branch::create(['code' => fake()->unique()->numerify('####'), 'name' => 'Posting Branch', 'is_active' => true, 'status' => 'active']);

    foreach ([
        ['1000', 'Cash on Hand', 'asset', 'debit'],
        ['1300', 'Interest Receivable', 'asset', 'debit'],
        ['2100', 'Client Savings Liability', 'liability', 'credit'],
        ['4200', 'Fees and Penalties Income', 'income', 'credit'],
        ['5000', 'Operating Expenses', 'expense', 'debit'],
    ] as [$code, $name, $type, $normalBalance]) {
        ChartAccount::firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'normal_balance' => $normalBalance, 'is_active' => true]);
    }

    return $branch;
}

function automaticPostingClient(Branch $branch): Client
{
    return Client::create([
        'branch_id' => $branch->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => 'Grace',
        'last_name' => 'Auma',
        'phone' => fake()->unique()->e164PhoneNumber(),
        'kyc_status' => 'verified',
        'status' => 'active',
    ]);
}

it('automatically posts savings interest on the configured monthly cycle', function () {
    $branch = automaticPostingBranch();
    $product = SavingsProduct::create([
        'name' => 'Monthly Interest Savings',
        'code' => 'MIS',
        'minimum_opening_balance' => 0,
        'minimum_balance' => 0,
        'interest_rate' => 12,
        'interest_posting_frequency' => 'monthly',
        'status' => 'active',
    ]);
    $account = SavingsAccount::create([
        'branch_id' => $branch->id,
        'client_id' => automaticPostingClient($branch)->id,
        'savings_product_id' => $product->id,
        'account_number' => '1001-SAV-MONTHLY',
        'opening_date' => today(),
        'current_balance' => 1_200_000,
        'available_balance' => 1_200_000,
        'status' => 'active',
    ]);

    $posted = app(SavingsInterestService::class)->postDueInterest(branchId: $branch->id, asOf: CarbonImmutable::parse('2026-05-31'));

    expect($posted)->toBe(1)
        ->and($account->fresh()->current_balance)->toBe('1212000.00')
        ->and($account->transactions()->where('type', 'interest_posting')->count())->toBe(1);

    $secondRun = app(SavingsInterestService::class)->postDueInterest(branchId: $branch->id, asOf: CarbonImmutable::parse('2026-05-31'));

    expect($secondRun)->toBe(0)
        ->and($account->fresh()->current_balance)->toBe('1212000.00');
});

it('queues and posts due savings interest from the scheduled job', function () {
    Log::spy();
    $branch = automaticPostingBranch();
    $product = SavingsProduct::create([
        'name' => 'Daily Interest Savings',
        'code' => 'DIS',
        'minimum_opening_balance' => 0,
        'minimum_balance' => 0,
        'interest_rate' => 36.5,
        'interest_posting_frequency' => 'daily',
        'status' => 'active',
    ]);
    $account = SavingsAccount::create([
        'branch_id' => $branch->id,
        'client_id' => automaticPostingClient($branch)->id,
        'savings_product_id' => $product->id,
        'account_number' => '1001-SAV-DAILY',
        'opening_date' => today(),
        'current_balance' => 100_000,
        'available_balance' => 100_000,
        'status' => 'active',
    ]);

    (new PostInterest(branchId: $branch->id, asOf: '2026-05-15'))->handle(app(SavingsInterestService::class));

    expect($account->fresh()->current_balance)->toBe('100100.00')
        ->and($account->transactions()->where('type', 'interest_posting')->count())->toBe(1);
});

it('applies organization penalty settings before platform defaults once per overdue grace-adjusted day', function () {
    $branch = automaticPostingBranch();
    $client = automaticPostingClient($branch);
    SystemSetting::withoutGlobalScope('tenant')->updateOrCreate(
        ['organization_id' => null, 'key' => 'loan_arrears_penalty_rate'],
        ['label' => 'Loan arrears penalty rate', 'value' => '3', 'type' => 'number', 'group' => 'loans']
    );
    SystemSetting::withoutGlobalScope('tenant')->updateOrCreate(
        ['organization_id' => $branch->organization_id, 'key' => 'loan_arrears_penalty_rate'],
        ['label' => 'Loan arrears penalty rate', 'value' => '1', 'type' => 'number', 'group' => 'loans']
    );
    $product = LoanProduct::create([
        'name' => 'Settings Penalty Loan',
        'code' => 'SPL',
        'annual_interest_rate' => 24,
        'interest_method' => 'declining_balance',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 6,
        'penalty_type' => 'percentage',
        'penalty_rate' => 99,
        'status' => 'active',
        'is_active' => true,
    ]);

    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $product->id,
        'loan_number' => 'LN-SETTING-PENALTY',
        'requested_amount' => 500_000,
        'approved_amount' => 500_000,
        'principal_balance' => 500_000,
        'interest_balance' => 0,
        'interest_rate' => 24,
        'term_periods' => 6,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
    ]);

    $schedule = LoanSchedule::create(['loan_id' => $loan->id, 'installment_number' => 1, 'due_date' => '2026-05-11', 'principal_due' => 100_000, 'total_due' => 100_000, 'outstanding_amount' => 100_000, 'balance_due' => 100_000, 'status' => 'pending']);

    $loanService = app(LoanService::class);
    $loanService->applyOverduePenalties(asOf: CarbonImmutable::parse('2026-05-13'), branchId: $branch->id);
    $loanService->applyOverduePenalties(asOf: CarbonImmutable::parse('2026-05-13'), branchId: $branch->id);

    expect(LoanPenalty::where('loan_id', $loan->id)->count())->toBe(1)
        ->and((float) $loan->fresh()->penalty_balance)->toBe(1000.0)
        ->and((float) $schedule->fresh()->penalty_due)->toBe(1000.0)
        ->and((int) $schedule->fresh()->days_overdue)->toBe(1);

    $loanService->applyOverduePenalties(asOf: CarbonImmutable::parse('2026-05-14'), branchId: $branch->id);

    expect(LoanPenalty::where('loan_id', $loan->id)->count())->toBe(2)
        ->and((float) $loan->fresh()->penalty_balance)->toBe(2000.0)
        ->and((float) $schedule->fresh()->penalty_due)->toBe(2000.0);
});
