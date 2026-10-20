<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\DrawerMovement;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\LoanSavingsDeductionRun;
use App\Models\LoanSchedule;
use App\Models\Organization;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\ScheduledSavingsLoanDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates pending schedules and requires an administrator to activate them', function () {
    [$organization, $branch, $admin, $savings, $loan] = scheduledDeductionFixture();
    $service = app(ScheduledSavingsLoanDeductionService::class);

    $schedule = $service->create($savings, $loan, 10_000, 'monthly', now()->addDay(), $admin);
    $cashierRole = Role::create(['name' => 'Cashier', 'slug' => 'cashier']);
    $cashier = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $cashierRole->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    expect($schedule->status)->toBe('draft')
        ->and(fn () => $service->activate($schedule, $cashier))->toThrow(InvalidArgumentException::class, 'Only administrators');

    $service->activate($schedule, $admin);

    expect($schedule->fresh()->status)->toBe('active')
        ->and($schedule->fresh()->activated_by)->toBe($admin->id);
});

it('processes a due schedule once without moving teller drawer cash', function () {
    [$organization, , $admin, $savings, $loan] = scheduledDeductionFixture();
    $service = app(ScheduledSavingsLoanDeductionService::class);
    $schedule = $service->create($savings, $loan, 10_000, 'monthly', now()->subMinute(), $admin);
    $service->activate($schedule, $admin);

    $summary = $service->processDueSchedules(now(), $organization->id);
    $secondSummary = $service->processDueSchedules(now(), $organization->id);

    expect($summary)->toMatchArray(['checked' => 1, 'succeeded' => 1, 'skipped' => 0, 'failed' => 0])
        ->and($secondSummary['checked'])->toBe(0)
        ->and((float) $savings->fresh()->current_balance)->toBe(190_000.0)
        ->and((float) $loan->fresh()->principal_balance)->toBe(90_000.0)
        ->and(LoanRepayment::where('loan_id', $loan->id)->count())->toBe(1)
        ->and(DrawerMovement::count())->toBe(0)
        ->and(LoanSavingsDeductionRun::where('schedule_id', $schedule->id)->count())->toBe(1)
        ->and(LoanSavingsDeductionRun::where('schedule_id', $schedule->id)->value('status'))->toBe('succeeded');
});

it('skips insufficient funds without overdrawing savings and advances the schedule', function () {
    [$organization, , $admin, $savings, $loan] = scheduledDeductionFixture(balance: 5_000);
    $service = app(ScheduledSavingsLoanDeductionService::class);
    $schedule = $service->create($savings, $loan, 10_000, 'weekly', now()->subMinute(), $admin);
    $service->activate($schedule, $admin);

    $summary = $service->processDueSchedules(now(), $organization->id);
    $run = LoanSavingsDeductionRun::where('schedule_id', $schedule->id)->firstOrFail();

    expect($summary)->toMatchArray(['checked' => 1, 'succeeded' => 0, 'skipped' => 1, 'failed' => 0])
        ->and($run->status)->toBe('skipped')
        ->and($run->failure_reason)->toContain('unlocked savings balance')
        ->and((float) $savings->fresh()->current_balance)->toBe(5_000.0)
        ->and(LoanRepayment::where('loan_id', $loan->id)->count())->toBe(0)
        ->and($schedule->fresh()->next_run_at->isFuture())->toBeTrue();
});

it('caps the final scheduled deduction at the remaining installment and stops the schedule', function () {
    [$organization, , $admin, $savings, $loan] = scheduledDeductionFixture();
    $service = app(ScheduledSavingsLoanDeductionService::class);
    $schedule = $service->create($savings, $loan, 150_000, 'monthly', now()->subMinute(), $admin);
    $service->activate($schedule, $admin);

    $summary = $service->processDueSchedules(now(), $organization->id);

    expect($summary)->toMatchArray(['checked' => 1, 'succeeded' => 1, 'skipped' => 0, 'failed' => 0])
        ->and((float) $savings->fresh()->current_balance)->toBe(100_000.0)
        ->and((float) $loan->fresh()->principal_balance)->toBe(0.0)
        ->and((float) LoanRepayment::where('loan_id', $loan->id)->value('amount'))->toBe(100_000.0)
        ->and($schedule->fresh()->status)->toBe('disabled')
        ->and($schedule->fresh()->last_message)->toContain('fully cleared');
});

it('rejects a cross-client schedule and a non-denomination amount', function () {
    [, , $admin, $savings, $loan] = scheduledDeductionFixture();
    $otherClient = Client::create([
        'organization_id' => $loan->organization_id,
        'branch_id' => $loan->branch_id,
        'officer_id' => $admin->id,
        'client_number' => fake()->unique()->numerify('CLIENT-#####'),
        'client_type' => 'individual',
        'first_name' => 'Other',
        'last_name' => 'Client',
        'phone' => fake()->unique()->e164PhoneNumber(),
        'status' => 'active',
    ]);
    $otherLoan = $loan->replicate();
    $otherLoan->client_id = $otherClient->id;
    $otherLoan->loan_number = fake()->unique()->numerify('LN-#######');
    $otherLoan->save();
    $service = app(ScheduledSavingsLoanDeductionService::class);

    expect(fn () => $service->create($savings, $otherLoan, 10_000, 'monthly', now()->addDay(), $admin))
        ->toThrow(InvalidArgumentException::class, 'same client')
        ->and(fn () => $service->create($savings, $loan, 5_050, 'monthly', now()->addDay(), $admin))
        ->toThrow(InvalidArgumentException::class, 'multiples');
});

it('does not process a schedule belonging to another organization', function () {
    [$organization, , , $savings, $loan] = scheduledDeductionFixture('Primary');
    [, , $foreignAdmin, $foreignSavings, $foreignLoan] = scheduledDeductionFixture('Foreign');
    $service = app(ScheduledSavingsLoanDeductionService::class);
    $foreignSchedule = $service->create($foreignSavings, $foreignLoan, 10_000, 'daily', now()->subMinute(), $foreignAdmin);
    $service->activate($foreignSchedule, $foreignAdmin);

    $summary = $service->processDueSchedules(now(), $organization->id);

    expect($summary['checked'])->toBe(0)
        ->and($foreignSchedule->fresh()->next_run_at->isPast())->toBeTrue()
        ->and((float) $foreignSavings->fresh()->current_balance)->toBe(200_000.0)
        ->and($loan->organization_id)->toBe($organization->id)
        ->and($savings->organization_id)->toBe($organization->id);
});

it('can disable and cancel a schedule only through administrator controls', function () {
    [, , $admin, $savings, $loan] = scheduledDeductionFixture();
    $service = app(ScheduledSavingsLoanDeductionService::class);
    $schedule = $service->create($savings, $loan, 10_000, 'monthly', now()->addDay(), $admin);
    $service->activate($schedule, $admin);
    $service->disable($schedule, $admin);

    expect($schedule->fresh()->status)->toBe('disabled');

    $service->activate($schedule->fresh(), $admin);
    $service->disable($schedule->fresh(), $admin, 'cancelled');

    expect($schedule->fresh()->status)->toBe('cancelled');
});

/**
 * @return array{0:Organization, 1:Branch, 2:User, 3:SavingsAccount, 4:Loan}
 */
function scheduledDeductionFixture(string $label = 'Scheduled', float $balance = 200_000): array
{
    $organization = Organization::create(['name' => $label.' Organization', 'slug' => str($label)->slug().'-'.fake()->unique()->numerify('###'), 'status' => 'active']);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => fake()->unique()->numerify('####'), 'name' => $label.' Branch', 'is_active' => true, 'status' => 'active']);
    $role = Role::firstOrCreate(['slug' => 'super-admin'], ['organization_id' => $organization->id, 'name' => $label.' Administrator']);
    $admin = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    foreach ([
        ['1000', 'Cash on Hand', 'asset', 'debit'],
        ['1200', 'Loans Receivable', 'asset', 'debit'],
        ['2100', 'Client Savings Liability', 'liability', 'credit'],
        ['4100', 'Loan Interest Income', 'income', 'credit'],
        ['4200', 'Fees and Penalties Income', 'income', 'credit'],
    ] as [$code, $name, $type, $normalBalance]) {
        ChartAccount::firstOrCreate(['code' => $code], ['organization_id' => $organization->id, 'branch_id' => $branch->id, 'name' => $name, 'type' => $type, 'normal_balance' => $normalBalance, 'is_active' => true]);
    }

    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'officer_id' => $admin->id,
        'client_number' => fake()->unique()->numerify('CLIENT-#####'),
        'client_type' => 'individual',
        'first_name' => 'Scheduled',
        'last_name' => 'Client',
        'phone' => fake()->unique()->e164PhoneNumber(),
        'status' => 'active',
    ]);
    $savingsProduct = SavingsProduct::create([
        'organization_id' => $organization->id,
        'name' => 'Ordinary Savings',
        'code' => fake()->unique()->bothify('SAV-??'),
        'minimum_opening_balance' => 5_000,
        'minimum_balance' => 5_000,
        'interest_rate' => 0,
        'interest_posting_frequency' => 'monthly',
        'withdrawal_charge' => 0,
        'deposit_charge' => 0,
        'monthly_maintenance_fee' => 0,
        'account_number_prefix' => 'SAV',
        'allows_overdraft' => false,
        'status' => 'active',
    ]);
    $savings = SavingsAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'savings_product_id' => $savingsProduct->id,
        'created_by' => $admin->id,
        'account_number' => fake()->unique()->numerify('SAV-#######'),
        'opening_date' => today(),
        'current_balance' => $balance,
        'available_balance' => $balance,
        'status' => 'active',
    ]);
    $loanProduct = LoanProduct::create([
        'organization_id' => $organization->id,
        'name' => 'Scheduled Loan',
        'code' => fake()->unique()->bothify('LN-??'),
        'min_amount' => 10_000,
        'max_amount' => 1_000_000,
        'annual_interest_rate' => 0,
        'interest_method' => 'flat',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 12,
        'is_active' => true,
        'status' => 'active',
    ]);
    $loan = Loan::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $loanProduct->id,
        'officer_id' => $admin->id,
        'loan_number' => fake()->unique()->numerify('LN-#######'),
        'requested_amount' => 100_000,
        'approved_amount' => 100_000,
        'principal_balance' => 100_000,
        'interest_balance' => 0,
        'fees_balance' => 0,
        'penalty_balance' => 0,
        'interest_rate' => 0,
        'term_periods' => 12,
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
    ]);

    return [$organization, $branch, $admin, $savings, $loan];
}
