<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\DrawerMovement;
use App\Models\InvestmentAccount;
use App\Models\InvestmentPlan;
use App\Models\InvestorAccount;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\LoanSchedule;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\FinancialRelationshipService;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function financialRelationshipUser(): User
{
    $branch = Branch::create(['code' => '1001', 'name' => 'Luweero', 'is_active' => true, 'status' => 'active']);
    $role = Role::create(['name' => 'Relationship Officer', 'slug' => 'relationship-officer']);
    $permissions = collect(['post-transactions', 'post-repayments', 'manage-loans', 'manage-accounts'])
        ->map(fn (string $slug) => Permission::create(['name' => str($slug)->headline(), 'slug' => $slug]));
    $role->permissions()->sync($permissions->pluck('id')->all());

    foreach ([
        ['1000', 'Cash on Hand', 'asset', 'debit'],
        ['1200', 'Loans Receivable', 'asset', 'debit'],
        ['2100', 'Client Savings Liability', 'liability', 'credit'],
        ['2200', 'Investment Liability', 'liability', 'credit'],
        ['4100', 'Loan Interest Income', 'income', 'credit'],
        ['4200', 'Fees and Penalties Income', 'income', 'credit'],
        ['5000', 'Investment Return Expense', 'expense', 'debit'],
    ] as [$code, $name, $type, $normalBalance]) {
        ChartAccount::create(['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normalBalance, 'is_active' => true]);
    }

    return User::factory()->create(['branch_id' => $branch->id, 'role_id' => $role->id, 'status' => 'active', 'is_active' => true]);
}

function financialRelationshipClient(User $user): Client
{
    return Client::create([
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->e164PhoneNumber(),
        'kyc_status' => 'verified',
        'status' => 'active',
    ]);
}

function financialRelationshipSavingsAccount(User $user, Client $client, float $balance = 200_000): SavingsAccount
{
    $product = SavingsProduct::create([
        'name' => 'Ordinary Savings',
        'code' => fake()->unique()->bothify('SAV-??'),
        'minimum_opening_balance' => 10_000,
        'minimum_balance' => 5_000,
        'interest_rate' => 2,
        'interest_posting_frequency' => 'monthly',
        'withdrawal_charge' => 0,
        'deposit_charge' => 0,
        'monthly_maintenance_fee' => 0,
        'account_number_prefix' => 'SAV',
        'allows_overdraft' => false,
        'status' => 'active',
    ]);

    return SavingsAccount::create([
        'branch_id' => $user->branch_id,
        'client_id' => $client->id,
        'savings_product_id' => $product->id,
        'account_number' => fake()->unique()->numerify('1001-SAV-#######'),
        'opening_date' => today(),
        'current_balance' => $balance,
        'available_balance' => $balance,
        'status' => 'active',
    ]);
}

function financialRelationshipInvestmentAccount(User $user, Client $client, float $balance = 50_000): InvestmentAccount
{
    $plan = InvestmentPlan::create([
        'organization_id' => $user->organization_id,
        'created_by' => $user->id,
        'name' => 'Fixed Income',
        'code' => fake()->unique()->bothify('INV-??'),
        'type' => 'fixed',
        'minimum_amount' => 10_000,
        'maximum_amount' => 10_000_000,
        'duration' => 6,
        'duration_unit' => 'months',
        'expected_return_rate' => 10,
        'return_calculation_method' => 'fixed_percentage',
        'return_frequency' => 'monthly',
        'allow_top_ups' => true,
        'allow_early_withdrawal' => true,
        'early_withdrawal_penalty' => 0,
        'auto_reinvest_returns' => false,
        'risk_level' => 'low',
        'status' => 'active',
    ]);

    $investor = InvestorAccount::create([
        'organization_id' => $user->organization_id,
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'assigned_officer_id' => $user->id,
        'created_by' => $user->id,
        'account_number' => fake()->unique()->numerify('1001-INVESTOR-#######'),
        'total_invested' => $balance,
        'current_investment_balance' => $balance,
        'status' => 'active',
    ]);

    return InvestmentAccount::create([
        'organization_id' => $user->organization_id,
        'investor_account_id' => $investor->id,
        'client_id' => $client->id,
        'investment_plan_id' => $plan->id,
        'branch_id' => $user->branch_id,
        'created_by' => $user->id,
        'investment_number' => fake()->unique()->numerify('1001-INV-#######'),
        'principal_amount' => $balance,
        'start_date' => today(),
        'maturity_date' => today()->addMonths(6),
        'expected_return_amount' => $balance * 0.1,
        'actual_return_amount' => 0,
        'current_balance' => $balance,
        'return_rate' => 10,
        'return_method' => 'fixed_percentage',
        'status' => 'active',
    ]);
}

function financialRelationshipLoan(User $user, Client $client, float $principal = 100_000): Loan
{
    $product = LoanProduct::create([
        'name' => 'Business Loan',
        'code' => fake()->unique()->bothify('LN-??'),
        'min_amount' => 10_000,
        'max_amount' => 10_000_000,
        'annual_interest_rate' => 12,
        'interest_method' => 'flat',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 1,
        'is_active' => true,
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => fake()->unique()->numerify('1001-LN-#######'),
        'requested_amount' => $principal,
        'approved_amount' => $principal,
        'principal_balance' => $principal,
        'interest_balance' => 0,
        'fees_balance' => 0,
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
        'principal_due' => $principal,
        'interest_due' => 0,
        'total_due' => $principal,
        'outstanding_amount' => $principal,
        'balance_due' => $principal,
    ]);

    return $loan;
}

it('locks savings and investments as loan security', function () {
    $user = financialRelationshipUser();
    $client = financialRelationshipClient($user);
    $savings = financialRelationshipSavingsAccount($user, $client);
    $investment = financialRelationshipInvestmentAccount($user, $client);
    $loan = financialRelationshipLoan($user, $client);
    $service = app(FinancialRelationshipService::class);

    $service->lockSavingsForLoan($loan, $savings, 60_000, $user);
    $service->lockInvestmentForLoan($loan, $investment, 25_000, $user);

    expect($savings->fresh()->locked_amount)->toBe('60000.00')
        ->and($savings->fresh()->available_balance)->toBe('140000.00')
        ->and($investment->fresh()->locked_amount)->toBe('25000.00')
        ->and($loan->fresh()->locked_savings_amount)->toBe('60000.00')
        ->and($loan->fresh()->locked_investment_amount)->toBe('25000.00');
});

it('transfers savings into an investment account with paired transactions', function () {
    $user = financialRelationshipUser();
    $client = financialRelationshipClient($user);
    $savings = financialRelationshipSavingsAccount($user, $client);
    $investment = financialRelationshipInvestmentAccount($user, $client);

    $result = app(FinancialRelationshipService::class)->transferSavingsToInvestment($savings, $investment, 40_000, $user);

    expect($savings->fresh()->current_balance)->toBe('160000.00')
        ->and($investment->fresh()->current_balance)->toBe('90000.00')
        ->and($result['savings_transaction']->type)->toBe('withdrawal')
        ->and($result['investment_transaction']->type)->toBe('top_up');
});

it('repays loans from savings and updates the client overview', function () {
    $user = financialRelationshipUser();
    $client = financialRelationshipClient($user);
    $savings = financialRelationshipSavingsAccount($user, $client);
    $investment = financialRelationshipInvestmentAccount($user, $client);
    $loan = financialRelationshipLoan($user, $client);
    $service = app(FinancialRelationshipService::class);

    $repayment = $service->repayLoanFromSavings($loan, $savings, 40_000, $user);
    $overview = $service->clientOverview($client->fresh());

    expect($repayment->principal_paid)->toBe('40000.00')
        ->and($loan->fresh()->principal_balance)->toBe('60000.00')
        ->and($savings->fresh()->current_balance)->toBe('160000.00')
        ->and($overview['total_savings'])->toBe(160000.0)
        ->and($overview['total_investments'])->toBe((float) $investment->current_balance)
        ->and($overview['outstanding_loan_balance'])->toBe(60000.0);
});

it('posts a manual savings loan repayment through the non-cash route without moving drawer cash', function () {
    $user = financialRelationshipUser();
    $user->role->update(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $client = financialRelationshipClient($user);
    $savings = financialRelationshipSavingsAccount($user, $client);
    $loan = financialRelationshipLoan($user, $client);

    $this->actingAs($user)
        ->post(route('non-cash.repay-loan-from-savings'), [
            'savings_account_id' => $savings->id,
            'loan_id' => $loan->id,
            'amount' => 40_000,
        ])
        ->assertRedirect(route('non-cash.index'))
        ->assertSessionHas('status', 'Savings funds transferred to the selected loan as a non-cash repayment.');

    expect((float) $savings->fresh()->current_balance)->toBe(160000.0)
        ->and((float) $loan->fresh()->principal_balance)->toBe(60000.0)
        ->and(LoanRepayment::where('loan_id', $loan->id)->value('payment_method'))->toBe('savings_deduction')
        ->and(DrawerMovement::count())->toBe(0);
});

it('validates savings loan repayment ownership, available balance, and denomination', function () {
    $user = financialRelationshipUser();
    $user->role->update(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $client = financialRelationshipClient($user);
    $otherClient = financialRelationshipClient($user);
    $savings = financialRelationshipSavingsAccount($user, $client, 10_000);
    $otherSavings = financialRelationshipSavingsAccount($user, $otherClient);
    $loan = financialRelationshipLoan($user, $client);
    $otherLoan = financialRelationshipLoan($user, $otherClient);

    $this->actingAs($user);

    $this->post(route('non-cash.repay-loan-from-savings'), [
        'savings_account_id' => $savings->id,
        'loan_id' => $otherLoan->id,
        'amount' => 1_000,
    ])->assertSessionHasErrors('amount');

    $this->post(route('non-cash.repay-loan-from-savings'), [
        'savings_account_id' => $savings->id,
        'loan_id' => $loan->id,
        'amount' => 10_100,
    ])->assertSessionHasErrors('amount');

    $this->post(route('non-cash.repay-loan-from-savings'), [
        'savings_account_id' => $otherSavings->id,
        'loan_id' => $otherLoan->id,
        'amount' => 5050,
    ])->assertSessionHasErrors('amount');
});

it('shows the manual savings loan repayment workflow in the non-cash area for repayment staff', function () {
    $user = financialRelationshipUser();
    $user->role->update(['name' => 'Super Admin', 'slug' => 'super-admin']);

    $this->actingAs($user)
        ->get(route('non-cash.index'))
        ->assertSuccessful()
        ->assertSee('Repay a Loan from Client Savings')
        ->assertSee('does not move teller drawer cash');
});

it('includes basic savings account balances in the client overview for the client organization', function () {
    $organization = Organization::create([
        'name' => 'Loan Only Organization',
        'slug' => fake()->unique()->slug(),
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => fake()->unique()->numerify('####'),
        'name' => 'Main Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'organization_id' => $organization->id,
        'name' => 'Relationship Officer',
        'slug' => fake()->unique()->slug(),
    ]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->e164PhoneNumber(),
        'kyc_status' => 'verified',
        'status' => 'active',
    ]);
    $accountType = AccountType::create([
        'organization_id' => $organization->id,
        'name' => 'Savings Account',
        'code' => fake()->unique()->bothify('SAV-??'),
        'category' => 'savings',
        'is_active' => true,
    ]);
    Account::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'account_type_id' => $accountType->id,
        'account_number' => fake()->unique()->numerify('1001-SAV-####'),
        'account_name' => $client->full_name.' Savings',
        'status' => 'active',
        'balance' => 75_000,
        'opened_at' => today(),
    ]);

    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($organization);

    $overview = app(FinancialRelationshipService::class)->clientOverview($client->fresh());

    expect($overview['total_savings'])->toBe(75000.0)
        ->and($overview['available_balance'])->toBe(75000.0)
        ->and($overview['module_flags']['investments'])->toBeFalse();
});
