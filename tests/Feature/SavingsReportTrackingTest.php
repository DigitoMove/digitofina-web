<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanSavingsDeductionSchedule;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\ReportCatalogService;
use App\Services\SavingsAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::create(['name' => 'Savings Reports', 'slug' => 'savings-reports', 'status' => 'active']);
    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($this->organization);
    Module::query()->pluck('id')->each(fn (int $moduleId): OrganizationModule => OrganizationModule::updateOrCreate(
        ['organization_id' => $this->organization->id, 'module_id' => $moduleId],
        ['is_enabled' => true, 'enabled_at' => now()],
    ));
    app(ModuleService::class)->clearCache($this->organization);
    $this->branch = Branch::create(['organization_id' => $this->organization->id, 'code' => 'SAVREP', 'name' => 'Savings Branch', 'status' => 'active', 'is_active' => true]);
    $role = Role::firstOrCreate(['slug' => 'organization-admin'], ['name' => 'Organization Admin']);
    foreach (['manage-accounts', 'view-reports'] as $slug) {
        $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => str($slug)->headline()]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => null, 'role_id' => $role->id, 'status' => 'active', 'is_active' => true]);
    $this->client = Client::create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'client_number' => 'SAVREP-CLIENT',
        'client_type' => 'individual',
        'first_name' => 'Savings',
        'last_name' => 'Client',
        'phone' => '+256700000011',
        'status' => 'active',
    ]);
    $this->product = SavingsProduct::create([
        'organization_id' => $this->organization->id,
        'name' => 'Regular Savings',
        'code' => 'SAVREP',
        'minimum_opening_balance' => 0,
        'minimum_balance' => 5_000,
        'interest_rate' => 12,
        'interest_posting_frequency' => 'monthly',
        'account_number_prefix' => 'SAV',
        'status' => 'active',
    ]);
    $this->account = SavingsAccount::create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'client_id' => $this->client->id,
        'savings_product_id' => $this->product->id,
        'account_number' => 'SAVREP-001',
        'opening_date' => today(),
        'current_balance' => 20_000,
        'available_balance' => 20_000,
        'status' => 'active',
    ]);
});

it('tracks dormant reactivation and reports the event once', function () {
    $this->actingAs($this->admin)->post(route('savings-accounts.dormant', $this->account))->assertRedirect();
    $this->post(route('savings-accounts.reactivate', $this->account))->assertRedirect();

    $report = app(ReportCatalogService::class)->generate('reactivated-savings-accounts', [], $this->admin);

    expect($this->account->fresh()->status)->toBe('active')
        ->and($report['rows']->sole()['Account'])->toBe('SAVREP-001')
        ->and($report['summary']['Reactivations'])->toBe('1')
        ->and(AuditLog::query()->where('action', 'savings_account.reactivated')->count())->toBe(1);
});

it('tracks account minimum adjustments and excludes resets from the current report', function () {
    $this->actingAs($this->admin)->get(route('savings-accounts.show', $this->account))
        ->assertOk()->assertSee('Adjust minimum balance');

    $this->actingAs($this->admin)
        ->post(route('savings-accounts.minimum-balance', $this->account), ['minimum_balance' => 1_000, 'reason' => 'Approved exception'])->assertRedirect();

    $report = app(ReportCatalogService::class)->generate('accounts-with-adjusted-minimum-balance', [], $this->admin);

    expect($this->account->fresh()->minimumBalance())->toBe(1_000.0)
        ->and($report['rows']->sole()['Account Minimum'])->toBe('UGX 1,000.00')
        ->and($report['summary']['Adjusted accounts'])->toBe('1');

    $this->post(route('savings-accounts.minimum-balance', $this->account), ['minimum_balance' => '', 'reason' => 'Return to product rule'])->assertRedirect();

    expect($this->account->fresh()->minimumBalance())->toBe(5_000.0)
        ->and(app(ReportCatalogService::class)->generate('accounts-with-adjusted-minimum-balance', [], $this->admin)['rows'])->toBeEmpty()
        ->and(AuditLog::query()->where('action', 'savings_account.minimum_balance_adjusted')->count())->toBe(2);
});

it('requires account management permission for status and minimum changes', function () {
    $role = Role::firstOrCreate(['slug' => 'branch-manager'], ['name' => 'Branch Manager']);
    $staff = User::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);

    $accounts = app(SavingsAccountService::class);

    expect(fn () => $accounts->markDormant($this->account, $staff))->toThrow(HttpException::class)
        ->and(fn () => $accounts->adjustMinimumBalance($this->account, 1_000, 'Unauthorized', $staff))->toThrow(HttpException::class);

    expect($this->account->fresh()->status)->toBe('active')
        ->and($this->account->fresh()->minimum_balance_override)->toBeNull();
});

it('sums only successful posted savings interest for the selected period', function () {
    foreach ([['INT-1', 200, 'successful'], ['INT-2', 300, 'successful'], ['INT-3', 500, 'reversed']] as [$reference, $amount, $status]) {
        SavingsTransaction::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'client_id' => $this->client->id,
            'savings_account_id' => $this->account->id,
            'reference' => $reference,
            'type' => 'interest_posting',
            'amount' => $amount,
            'balance_before' => 20_000,
            'balance_after' => 20_000 + $amount,
            'transaction_date' => today()->toDateString(),
            'value_date' => today()->toDateString(),
            'status' => $status,
        ]);
    }

    $report = app(ReportCatalogService::class)->generate('accounts-with-accrued-interest', [], $this->admin);

    expect($report['summary']['Accounts'])->toBe('1')
        ->and($report['rows']->count())->toBe(1)
        ->and($report['rows']->sole()['Interest Posted'])->toBe('UGX 500.00')
        ->and($report['rows']->sole()['Postings'])->toBe('2')
        ->and($report['summary']['Interest posted'])->toBe('UGX 500.00');
});

it('reports created, active, closed, and cancelled savings-to-loan orders', function () {
    $loanProduct = LoanProduct::create([
        'organization_id' => $this->organization->id,
        'name' => 'Loan',
        'code' => 'SAVREP-LOAN',
        'min_amount' => 1_000,
        'max_amount' => 1_000_000,
        'annual_interest_rate' => 12,
        'interest_method' => 'flat',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 12,
        'status' => 'active',
    ]);
    $loan = Loan::create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'client_id' => $this->client->id,
        'loan_product_id' => $loanProduct->id,
        'officer_id' => $this->admin->id,
        'created_by' => $this->admin->id,
        'loan_number' => 'SAVREP-LOAN-001',
        'requested_amount' => 100_000,
        'approved_amount' => 100_000,
        'interest_rate' => 12,
        'term_periods' => 12,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
    ]);
    foreach (['draft', 'active', 'closed', 'cancelled'] as $status) {
        LoanSavingsDeductionSchedule::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'client_id' => $this->client->id,
            'loan_id' => $loan->id,
            'savings_account_id' => $this->account->id,
            'created_by' => $this->admin->id,
            'amount' => 10_000,
            'frequency' => 'monthly',
            'next_run_at' => now()->addMonth(),
            'status' => $status,
            'disabled_at' => in_array($status, ['closed', 'cancelled'], true) ? now() : null,
        ]);
    }

    $reports = app(ReportCatalogService::class);
    $created = $reports->generate('created-savings-standing-orders', [], $this->admin);

    expect($created['summary']['Orders'])->toBe('4');

    foreach (['active', 'closed', 'cancelled'] as $status) {
        $report = $reports->generate("{$status}-savings-standing-orders", [], $this->admin);
        expect($report['rows']->sole()['Status'])->toBe(str($status)->headline()->toString())
            ->and($report['summary']['Orders'])->toBe('1')
            ->and($report['summary']['Scheduled amount'])->toBe('UGX 10,000.00');
    }
});
