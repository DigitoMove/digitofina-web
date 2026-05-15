<?php

use App\Models\Branch;
use App\Models\BranchDailySummary;
use App\Models\Client;
use App\Models\FinancialDay;
use App\Models\Loan;
use App\Models\LoanPenalty;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\EndOfDayService;
use App\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function endOfDayUser(array $permissions = ['manage-end-of-day', 'view-reports', 'post-repayments']): User
{
    $branch = Branch::create(['code' => '1001', 'name' => 'Luweero', 'is_active' => true, 'status' => 'active']);
    $permissionIds = collect($permissions)
        ->map(fn (string $slug) => Permission::create(['name' => str($slug)->headline(), 'slug' => $slug])->id)
        ->all();
    $role = Role::create(['name' => 'Branch Manager', 'slug' => fake()->unique()->slug(2)]);
    $role->permissions()->sync($permissionIds);

    return User::factory()->create([
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
}

function endOfDayLoan(User $user): Loan
{
    $client = Client::create([
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => 'Grace',
        'last_name' => 'Auma',
        'phone' => '+256700000001',
        'kyc_status' => 'verified',
        'status' => 'active',
    ]);

    $product = LoanProduct::create([
        'name' => 'Market Loan',
        'code' => fake()->unique()->bothify('ML-###'),
        'annual_interest_rate' => 24,
        'interest_method' => 'declining_balance',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 6,
        'penalty_type' => 'percentage',
        'penalty_rate' => 2,
        'status' => 'active',
        'is_active' => true,
    ]);

    return Loan::create([
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => fake()->unique()->numerify('LN######'),
        'requested_amount' => 500000,
        'approved_amount' => 500000,
        'principal_balance' => 500000,
        'interest_balance' => 50000,
        'interest_rate' => 24,
        'term_periods' => 6,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
    ]);
}

it('runs end of day processing with summaries, penalties, and repayment reminders', function () {
    Queue::fake();

    $user = endOfDayUser();
    $loan = endOfDayLoan($user);
    LoanSchedule::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => today()->subDay(),
        'principal_due' => 100000,
        'interest_due' => 10000,
        'total_due' => 110000,
        'outstanding_amount' => 110000,
        'balance_due' => 110000,
        'status' => 'pending',
    ]);

    $summary = app(EndOfDayService::class)->process($user->branch, today(), $user);

    expect($summary)->toBeInstanceOf(BranchDailySummary::class)
        ->and((int) $summary->overdue_installments)->toBe(1)
        ->and((float) $summary->penalties_applied)->toBeGreaterThan(0.0)
        ->and((int) $summary->reminders_queued)->toBe(1);

    expect(LoanPenalty::count())->toBe(1)
        ->and(SmsLog::where('type', 'repayment_reminder')->count())->toBe(1);
});

it('locks a closed business day and rejects later repayments for that date', function () {
    $user = endOfDayUser();
    $loan = endOfDayLoan($user);

    FinancialDay::create([
        'branch_id' => $user->branch_id,
        'business_date' => today(),
        'status' => 'closed',
        'opened_by' => $user->id,
        'closed_by' => $user->id,
        'opened_at' => now(),
        'closed_at' => now(),
    ]);

    app(EndOfDayService::class)->lock($user->branch, $user, today());

    expect(FinancialDay::first()->status)->toBe('locked');

    app(LoanService::class)->repay($loan, 10000, $user, paidAt: today());
})->throws(InvalidArgumentException::class, 'The financial day is closed and locked for this branch.');
