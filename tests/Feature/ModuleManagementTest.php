<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\DrawerSession;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\TellerDrawer;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\ReportCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('blocks disabled module api routes for an organization', function () {
    $user = moduleManagementUser(['view-loans']);

    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($user->organization);

    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/loans')
        ->assertForbidden()
        ->assertJsonPath('success', false);
});

it('prevents disabled module permissions from granting access', function () {
    $user = moduleManagementUser(['manage-loans']);

    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($user->organization);

    expect($user->fresh()->canPerform('manage-loans'))->toBeFalse();
});

it('filters disabled module reports from the catalog', function () {
    $user = moduleManagementUser(['view-reports']);
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($user->organization);

    $reportSlugs = collect(app(ReportCatalogService::class)->definitions($user))->pluck('slug');

    expect($reportSlugs)
        ->toContain('client-register')
        ->not->toContain('loan-book')
        ->not->toContain('opened-savings-accounts');
});

it('hides disabled module navigation from the application shell', function () {
    $user = moduleManagementUser(['manage-loans', 'post-transactions', 'view-reports']);
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($user->organization);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee('Loan Products')
        ->assertDontSee('Teller Drawer')
        ->assertDontSee('Savings Products');
});

it('renders a loan-only client dashboard without disabled financial modules', function () {
    $user = moduleManagementUser(['manage-clients', 'manage-loans']);
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($user->organization);
    $modules->enable($user->organization, Module::where('slug', 'loans')->firstOrFail(), $user);
    $client = moduleManagementClient($user);
    $product = LoanProduct::create([
        'organization_id' => $user->organization_id,
        'name' => 'Business Loan',
        'code' => fake()->unique()->bothify('LN-??'),
        'min_amount' => 1000,
        'max_amount' => 1000000,
        'annual_interest_rate' => 12,
        'interest_method' => 'flat',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 6,
        'status' => 'active',
        'is_active' => true,
    ]);

    Loan::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'client_id' => $client->id,
        'loan_product_id' => $product->id,
        'officer_id' => $user->id,
        'loan_number' => fake()->unique()->numerify('1001-LN-######'),
        'requested_amount' => 250000,
        'approved_amount' => 250000,
        'principal_balance' => 200000,
        'interest_balance' => 15000,
        'fees_balance' => 5000,
        'penalty_balance' => 0,
        'interest_rate' => 12,
        'term_periods' => 6,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->get(route('clients.show', $client))
        ->assertSuccessful()
        ->assertSee('Outstanding Loans')
        ->assertSee('Loan Accounts')
        ->assertDontSee('Total Savings')
        ->assertDontSee('Total Investments')
        ->assertDontSee('Shares Accounts')
        ->assertDontSee('Term Deposits');
});

it('renders a savings-only client dashboard without loan workflow links', function () {
    $user = moduleManagementUser(['manage-clients']);
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($user->organization);
    $modules->enable($user->organization, Module::where('slug', 'savings')->firstOrFail(), $user);
    $client = moduleManagementClient($user);
    $product = SavingsProduct::create([
        'organization_id' => $user->organization_id,
        'name' => 'Ordinary Savings',
        'code' => fake()->unique()->bothify('SAV-??'),
        'minimum_opening_balance' => 1000,
        'minimum_balance' => 1000,
        'interest_rate' => 0,
        'interest_posting_frequency' => 'monthly',
        'withdrawal_charge' => 0,
        'deposit_charge' => 0,
        'monthly_maintenance_fee' => 0,
        'account_number_prefix' => 'SAV',
        'status' => 'active',
    ]);

    SavingsAccount::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'client_id' => $client->id,
        'savings_product_id' => $product->id,
        'account_number' => fake()->unique()->numerify('1001-SAV-######'),
        'opening_date' => today(),
        'current_balance' => 120000,
        'available_balance' => 120000,
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->get(route('clients.show', $client))
        ->assertSuccessful()
        ->assertSee('Total Savings')
        ->assertSee('Savings Accounts')
        ->assertDontSee('Outstanding Loans')
        ->assertDontSee('Loan Accounts')
        ->assertDontSee('Due Repayments');
});

it('keeps branch-attached managers inside their branch across loan and savings lists', function () {
    $user = moduleManagementUser(['manage-branches', 'manage-loans', 'manage-accounts']);
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($user->organization);
    $modules->enable($user->organization, Module::where('slug', 'loans')->firstOrFail(), $user);
    $modules->enable($user->organization, Module::where('slug', 'savings')->firstOrFail(), $user);

    $otherBranch = Branch::create([
        'organization_id' => $user->organization_id,
        'code' => fake()->unique()->numerify('####'),
        'name' => 'Other Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $product = LoanProduct::create([
        'organization_id' => $user->organization_id,
        'name' => 'Working Capital',
        'code' => fake()->unique()->bothify('LN-??'),
        'min_amount' => 1000,
        'max_amount' => 1000000,
        'annual_interest_rate' => 12,
        'interest_method' => 'flat',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 6,
        'status' => 'active',
        'is_active' => true,
    ]);
    $savingsProduct = SavingsProduct::create([
        'organization_id' => $user->organization_id,
        'name' => 'Ordinary Savings',
        'code' => fake()->unique()->bothify('SAV-??'),
        'minimum_opening_balance' => 1000,
        'minimum_balance' => 1000,
        'interest_rate' => 0,
        'interest_posting_frequency' => 'monthly',
        'withdrawal_charge' => 0,
        'deposit_charge' => 0,
        'monthly_maintenance_fee' => 0,
        'account_number_prefix' => 'SAV',
        'status' => 'active',
    ]);
    $ownClient = moduleManagementClient($user);
    $otherClient = Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $otherBranch->id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->numerify('+2567########'),
        'status' => 'active',
    ]);
    $ownLoanNumber = fake()->unique()->numerify('1001-LN-######');
    $otherLoanNumber = fake()->unique()->numerify('1001-LN-######');

    foreach ([[$ownClient, $user->branch_id, $ownLoanNumber], [$otherClient, $otherBranch->id, $otherLoanNumber]] as [$client, $branchId, $loanNumber]) {
        Loan::create([
            'organization_id' => $user->organization_id,
            'branch_id' => $branchId,
            'client_id' => $client->id,
            'loan_product_id' => $product->id,
            'officer_id' => $user->id,
            'loan_number' => $loanNumber,
            'requested_amount' => 250000,
            'approved_amount' => 250000,
            'principal_balance' => 200000,
            'interest_balance' => 15000,
            'fees_balance' => 0,
            'penalty_balance' => 0,
            'interest_rate' => 12,
            'term_periods' => 6,
            'repayment_frequency' => 'monthly',
            'status' => 'active',
        ]);
    }

    $ownSavingsNumber = fake()->unique()->numerify('1001-SAV-######');
    $otherSavingsNumber = fake()->unique()->numerify('1001-SAV-######');

    foreach ([[$ownClient, $user->branch_id, $ownSavingsNumber], [$otherClient, $otherBranch->id, $otherSavingsNumber]] as [$client, $branchId, $accountNumber]) {
        SavingsAccount::create([
            'organization_id' => $user->organization_id,
            'branch_id' => $branchId,
            'client_id' => $client->id,
            'savings_product_id' => $savingsProduct->id,
            'account_number' => $accountNumber,
            'opening_date' => today(),
            'current_balance' => 120000,
            'available_balance' => 120000,
            'status' => 'active',
        ]);
    }

    $this->actingAs($user)
        ->get(route('loans.index'))
        ->assertSuccessful()
        ->assertSee($ownLoanNumber)
        ->assertDontSee($otherLoanNumber);

    $this->actingAs($user)
        ->get(route('savings-accounts.index'))
        ->assertSuccessful()
        ->assertSee($ownSavingsNumber)
        ->assertDontSee($otherSavingsNumber);
});

it('shows a teller form error instead of not found when posting without an open drawer', function () {
    $user = moduleManagementUser(['post-transactions']);
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($user->organization);
    $user->organization->modules()->syncWithoutDetaching([
        Module::where('slug', 'teller')->firstOrFail()->id => ['is_enabled' => true, 'enabled_at' => now()],
    ]);
    $client = Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->numerify('+2567########'),
        'status' => 'active',
    ]);
    $accountType = AccountType::create(['name' => 'Savings Account', 'code' => fake()->unique()->bothify('SAV-??'), 'category' => 'savings']);
    $account = Account::create([
        'organization_id' => $user->organization_id,
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'account_type_id' => $accountType->id,
        'account_number' => fake()->unique()->numerify('1001-SAV-####'),
        'account_name' => $client->full_name.' Savings',
        'status' => 'active',
        'balance' => 0,
        'opened_at' => today(),
    ]);
    $transactionType = TransactionType::create(['name' => 'Deposit', 'code' => fake()->unique()->bothify('deposit-??'), 'direction' => 'credit']);

    $this->actingAs($user)
        ->from(route('teller.index'))
        ->post(route('teller.post'), [
            'account_scope' => 'client_account',
            'account_number' => $account->account_number,
            'transaction_type_id' => $transactionType->id,
            'amount' => 1000,
            'transaction_date' => today()->toDateString(),
        ])
        ->assertRedirect(route('teller.index'))
        ->assertSessionHasErrors(['amount' => 'Open your teller drawer before posting a transaction.']);
});

it('shows a teller form error when the open drawer is unavailable', function () {
    $user = moduleManagementUser(['post-transactions']);
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($user->organization);
    $user->organization->modules()->syncWithoutDetaching([
        Module::where('slug', 'teller')->firstOrFail()->id => ['is_enabled' => true, 'enabled_at' => now()],
    ]);
    $otherBranch = Branch::create([
        'organization_id' => $user->organization_id,
        'code' => fake()->unique()->numerify('####'),
        'name' => 'Other Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $drawer = TellerDrawer::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $otherBranch->id,
        'assigned_user_id' => $user->id,
        'name' => 'Other Branch Drawer',
        'code' => fake()->unique()->bothify('TDR-??'),
        'current_balance' => 1000,
        'status' => 'open',
    ]);
    DrawerSession::create([
        'organization_id' => $user->organization_id,
        'teller_drawer_id' => $drawer->id,
        'user_id' => $user->id,
        'opening_balance' => 1000,
        'status' => 'open',
    ]);
    $client = Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->numerify('+2567########'),
        'status' => 'active',
    ]);
    $accountType = AccountType::create(['name' => 'Savings Account', 'code' => fake()->unique()->bothify('SAV-??'), 'category' => 'savings']);
    $account = Account::create([
        'organization_id' => $user->organization_id,
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'account_type_id' => $accountType->id,
        'account_number' => fake()->unique()->numerify('1001-SAV-####'),
        'account_name' => $client->full_name.' Savings',
        'status' => 'active',
        'balance' => 0,
        'opened_at' => today(),
    ]);
    $transactionType = TransactionType::create(['name' => 'Deposit', 'code' => fake()->unique()->bothify('deposit-??'), 'direction' => 'credit']);

    $this->actingAs($user)
        ->from(route('teller.index'))
        ->post(route('teller.post'), [
            'account_scope' => 'client_account',
            'account_number' => $account->account_number,
            'transaction_type_id' => $transactionType->id,
            'amount' => 1000,
            'transaction_date' => today()->toDateString(),
        ])
        ->assertRedirect(route('teller.index'))
        ->assertSessionHasErrors(['amount' => 'Your open teller drawer is no longer available. Close and reopen your drawer before posting a transaction.']);
});

it('keeps the selected account after closing a posted drawer receipt', function () {
    $user = moduleManagementUser(['post-transactions']);
    enableTellerModule($user->organization);
    $client = Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->numerify('+2567########'),
        'status' => 'active',
    ]);
    $accountType = AccountType::create([
        'name' => 'Savings Account',
        'code' => fake()->unique()->bothify('SAV-??'),
        'category' => 'savings',
        'is_active' => true,
    ]);
    $account = Account::create([
        'organization_id' => $user->organization_id,
        'client_id' => $client->id,
        'branch_id' => $user->branch_id,
        'account_type_id' => $accountType->id,
        'account_number' => fake()->unique()->numerify('1001-SAV-####'),
        'account_name' => $client->full_name.' Savings',
        'status' => 'active',
        'balance' => 0,
        'opened_at' => today(),
    ]);
    $transactionType = TransactionType::create([
        'name' => 'Deposit',
        'code' => fake()->unique()->bothify('deposit-??'),
        'direction' => 'credit',
        'is_active' => true,
    ]);
    $drawer = TellerDrawer::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'assigned_user_id' => $user->id,
        'name' => 'Main Drawer',
        'code' => fake()->unique()->bothify('TDR-??'),
        'current_balance' => 0,
        'status' => 'open',
    ]);
    DrawerSession::create([
        'organization_id' => $user->organization_id,
        'teller_drawer_id' => $drawer->id,
        'user_id' => $user->id,
        'opening_balance' => 0,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    $redirectUrl = route('teller.index', [
        'lookup_scope' => 'client_account',
        'account_number' => $account->account_number,
        'account_type_id' => $accountType->id,
    ]);

    $response = $this->actingAs($user)
        ->from(route('teller.index', ['account_number' => $account->account_number]))
        ->post(route('teller.post'), [
            'account_scope' => 'client_account',
            'account_number' => $account->account_number,
            'transaction_type_id' => $transactionType->id,
            'amount' => 1000,
            'transaction_date' => today()->toDateString(),
        ])
        ->assertRedirect($redirectUrl)
        ->assertSessionHas('receipt_transaction_id');

    $this->actingAs($user)
        ->withSession(['receipt_transaction_id' => $response->getSession()->get('receipt_transaction_id')])
        ->get($redirectUrl)
        ->assertSuccessful()
        ->assertSee($account->account_number)
        ->assertSee('account_number='.$account->account_number.'&amp;account_type_id='.$accountType->id.'&amp;lookup_scope=client_account', false);
});

it('shows general ledger drawer expenses in cashier entries and allows reversal requests', function () {
    $user = moduleManagementUser(['post-transactions']);
    enableTellerModule($user->organization);
    $expenseAccount = ChartAccount::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'code' => fake()->unique()->bothify('EXP-??'),
        'name' => 'Stationery Expense',
        'type' => 'expense',
        'normal_balance' => 'debit',
        'drawer_direction' => 'cash_out',
        'is_cash_account' => false,
        'is_system' => false,
        'is_active' => true,
    ]);
    $drawer = TellerDrawer::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'assigned_user_id' => $user->id,
        'name' => 'Main Drawer',
        'code' => fake()->unique()->bothify('TDR-??'),
        'current_balance' => 5000,
        'status' => 'open',
    ]);
    DrawerSession::create([
        'organization_id' => $user->organization_id,
        'teller_drawer_id' => $drawer->id,
        'user_id' => $user->id,
        'opening_balance' => 5000,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    $redirectUrl = route('teller.index', [
        'lookup_scope' => 'general_ledger',
        'chart_account_id' => $expenseAccount->id,
    ]);

    $response = $this->actingAs($user)
        ->from($redirectUrl)
        ->post(route('teller.post'), [
            'account_scope' => 'general_ledger',
            'chart_account_id' => $expenseAccount->id,
            'gl_entry_type' => 'debit',
            'amount' => 1000,
            'transaction_date' => today()->toDateString(),
            'description' => 'Bought receipt books',
        ])
        ->assertRedirect($redirectUrl)
        ->assertSessionHas('receipt_transaction_id');

    $this->actingAs($user)
        ->withSession(['receipt_transaction_id' => $response->getSession()->get('receipt_transaction_id')])
        ->get($redirectUrl)
        ->assertSuccessful()
        ->assertSee('Stationery Expense')
        ->assertSee('Request reversal')
        ->assertSee('chart_account_id='.$expenseAccount->id.'&amp;lookup_scope=general_ledger', false);
});

it('allows an organization admin to open an organization drawer for a teller', function () {
    [$organization, $branch, $otherBranch] = drawerAdminOrganization();
    enableTellerModule($organization);
    $admin = drawerAdminUser($organization, null, 'organization-admin', ['manage-organization', 'manage-users', 'manage-drawers']);
    $teller = drawerAdminUser($organization, $otherBranch->id, 'teller-cashier', ['post-transactions', 'manage-drawers']);
    $drawer = TellerDrawer::create([
        'organization_id' => $organization->id,
        'branch_id' => $otherBranch->id,
        'assigned_user_id' => null,
        'name' => 'Other Branch Drawer',
        'code' => fake()->unique()->bothify('ORG-TD-??'),
        'current_balance' => 0,
        'status' => 'closed',
    ]);

    $this->actingAs($admin)
        ->from(route('teller.index'))
        ->post(route('teller.open'), [
            'teller_drawer_id' => $drawer->id,
            'teller_user_id' => $teller->id,
            'opening_balance' => 1000,
        ])
        ->assertRedirect(route('teller.index'))
        ->assertSessionHasNoErrors();

    expect($drawer->fresh())
        ->status->toBe('open')
        ->assigned_user_id->toBe($teller->id);
});

it('shows selected organization drawers and tellers to a super admin', function () {
    [$organization, $branch] = drawerAdminOrganization();
    [$otherOrganization, $otherBranch] = drawerAdminOrganization('Other Tenant');
    $superAdmin = drawerAdminUser(null, null, 'super-admin', []);
    $teller = drawerAdminUser($organization, $branch->id, 'teller-cashier', ['post-transactions', 'manage-drawers']);
    $ownDrawer = TellerDrawer::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'assigned_user_id' => null,
        'name' => 'Selected Org Drawer',
        'code' => fake()->unique()->bothify('SEL-TD-??'),
        'current_balance' => 0,
        'status' => 'closed',
    ]);
    $otherDrawer = TellerDrawer::create([
        'organization_id' => $otherOrganization->id,
        'branch_id' => $otherBranch->id,
        'assigned_user_id' => null,
        'name' => 'Other Org Drawer',
        'code' => fake()->unique()->bothify('OTH-TD-??'),
        'current_balance' => 0,
        'status' => 'closed',
    ]);

    $this->actingAs($superAdmin)
        ->withSession(['super_admin_organization_id' => $organization->id])
        ->get(route('teller.index'))
        ->assertSuccessful()
        ->assertSee($teller->name)
        ->assertSee($ownDrawer->code)
        ->assertDontSee($otherDrawer->code);
});

it('prevents disabling modules required by enabled dependents', function () {
    $user = moduleManagementUser(['manage-organization']);
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($user->organization);

    $modules->enable($user->organization, Module::where('slug', 'savings')->firstOrFail(), $user);

    expect(fn () => $modules->disable($user->organization, Module::where('slug', 'clients')->firstOrFail(), $user))
        ->toThrow(InvalidArgumentException::class);
});

function moduleManagementUser(array $permissions): User
{
    $organization = Organization::create([
        'name' => 'Module Tenant',
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
        'name' => fake()->unique()->jobTitle(),
        'slug' => fake()->unique()->slug(),
    ]);

    foreach ($permissions as $permission) {
        $permissionModel = Permission::firstOrCreate(
            ['slug' => $permission],
            ['organization_id' => $organization->id, 'name' => str($permission)->headline()]
        );
        $role->permissions()->syncWithoutDetaching([$permissionModel->id]);
    }

    return User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'password' => Hash::make('password'),
        'status' => 'active',
        'is_active' => true,
    ]);
}

function moduleManagementClient(User $user): Client
{
    return Client::create([
        'organization_id' => $user->organization_id,
        'branch_id' => $user->branch_id,
        'officer_id' => $user->id,
        'client_number' => fake()->unique()->numerify('1001-C######'),
        'client_type' => 'individual',
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => fake()->unique()->numerify('+2567########'),
        'status' => 'active',
    ]);
}

function drawerAdminOrganization(string $name = 'Drawer Admin Tenant'): array
{
    $organization = Organization::create([
        'name' => $name,
        'slug' => fake()->unique()->slug(),
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => fake()->unique()->numerify('####'),
        'name' => 'Main Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $otherBranch = Branch::create([
        'organization_id' => $organization->id,
        'code' => fake()->unique()->numerify('####'),
        'name' => 'Second Branch',
        'is_active' => true,
        'status' => 'active',
    ]);

    return [$organization, $branch, $otherBranch];
}

function drawerAdminUser(?Organization $organization, ?int $branchId, string $roleSlug, array $permissions): User
{
    $role = Role::create([
        'organization_id' => $organization?->id,
        'name' => str($roleSlug)->headline(),
        'slug' => $roleSlug,
    ]);

    foreach ($permissions as $permission) {
        $permissionModel = Permission::firstOrCreate(
            ['slug' => $permission],
            ['organization_id' => $organization?->id, 'name' => str($permission)->headline()]
        );
        $role->permissions()->syncWithoutDetaching([$permissionModel->id]);
    }

    return User::factory()->create([
        'organization_id' => $organization?->id,
        'branch_id' => $branchId,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
}

function enableTellerModule(Organization $organization): void
{
    $modules = app(ModuleService::class);
    $modules->syncCatalog();
    $modules->syncDefaults($organization);
    $organization->modules()->syncWithoutDetaching([
        Module::where('slug', 'teller')->firstOrFail()->id => ['is_enabled' => true, 'enabled_at' => now()],
    ]);
}
