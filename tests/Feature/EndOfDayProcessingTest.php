<?php

use App\Models\Branch;
use App\Models\BranchDailySummary;
use App\Models\Client;
use App\Models\FinancialDay;
use App\Models\Loan;
use App\Models\LoanPenalty;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\EndOfDayService;
use App\Services\LoanService;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function endOfDayUser(array $permissions = ['manage-end-of-day', 'view-reports', 'post-repayments']): User
{
    $branch = Branch::create(['code' => '1001', 'name' => 'Luweero', 'is_active' => true, 'status' => 'active']);
    $permissionIds = collect($permissions)
        ->map(fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => str($slug)->headline()])->id)
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
        'due_date' => today()->subWeek(),
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

it('opens end of day for organization users without a default branch and links reports', function () {
    $organization = Organization::create([
        'name' => 'End Of Day SACCO',
        'slug' => 'end-of-day-sacco',
        'status' => 'active',
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'KAV',
        'name' => 'Kavule Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    Branch::create([
        'organization_id' => $organization->id,
        'code' => 'LUW',
        'name' => 'Luweero Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Organization Admin',
        'slug' => 'organization-admin',
    ]);
    $permissionIds = collect(['manage-end-of-day', 'view-reports'])
        ->map(fn (string $slug): int => Permission::firstOrCreate(
            ['slug' => $slug],
            ['organization_id' => $organization->id, 'name' => str($slug)->headline()]
        )->id)
        ->all();
    $role->permissions()->sync($permissionIds);
    endOfDayEnableModules($organization);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)->get(route('end-of-day.index'));

    $response->assertSuccessful()
        ->assertViewHas('branch', fn (Branch $selectedBranch): bool => (int) $selectedBranch->id === (int) $branch->id)
        ->assertSee('Kavule Branch')
        ->assertSee(route('reports.show', [
            'report' => 'daily-teller-transactions',
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
            'branch_id' => $branch->id,
        ]));

    foreach (['daily-teller-transactions', 'drawer-balances-by-teller', 'officer-entries', 'branch-period-summary'] as $report) {
        $this->actingAs($user)
            ->get(route('reports.show', [
                'report' => $report,
                'from' => today()->toDateString(),
                'to' => today()->toDateString(),
                'branch_id' => $branch->id,
            ]))
            ->assertSuccessful();
    }
});

function endOfDayEnableModules(Organization $organization): void
{
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($organization);
    $organization->modules()->syncWithoutDetaching([
        Module::where('slug', 'teller')->firstOrFail()->id => ['is_enabled' => true, 'enabled_at' => now()],
        Module::where('slug', 'reports')->firstOrFail()->id => ['is_enabled' => true, 'enabled_at' => now()],
        Module::where('slug', 'clients')->firstOrFail()->id => ['is_enabled' => true, 'enabled_at' => now()],
    ]);
    $modules->clearCache($organization);
}
