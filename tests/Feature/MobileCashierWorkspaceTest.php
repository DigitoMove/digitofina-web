<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TellerDrawer;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\BranchGeneralLedgerService;
use App\Services\ModuleService;
use App\Support\ApiSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function mobileCashierContext(): array
{
    $organization = Organization::create(['name' => 'Mobile cashier tenant', 'slug' => 'mobile-cashier-tenant', 'status' => 'active']);
    $branch = Branch::create(['organization_id' => $organization->id, 'code' => 'MOB01', 'name' => 'Mobile branch', 'is_active' => true, 'status' => 'active']);
    $role = Role::create(['name' => 'Teller Cashier', 'slug' => 'teller-cashier']);
    $permission = Permission::firstOrCreate(['slug' => 'post-transactions'], ['name' => 'Post Transactions']);
    $role->permissions()->sync([$permission->id]);
    app(ModuleService::class)->syncCatalog();
    app(ModuleService::class)->syncDefaults($organization);
    Module::whereIn('slug', ['savings', 'loans', 'teller'])->get()->each(function (Module $module) use ($organization): void {
        OrganizationModule::updateOrCreate(
            ['organization_id' => $organization->id, 'module_id' => $module->id],
            ['is_enabled' => true, 'enabled_at' => now()],
        );
    });
    app(ModuleService::class)->clearCache($organization);
    $cashier = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $drawer = TellerDrawer::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'assigned_user_id' => $cashier->id,
        'name' => 'Mobile Till',
        'code' => 'MOB-TILL',
        'currency' => 'UGX',
        'current_balance' => 105_000,
        'status' => 'open',
    ]);
    $session = DrawerSession::create([
        'organization_id' => $organization->id,
        'teller_drawer_id' => $drawer->id,
        'user_id' => $cashier->id,
        'opening_balance' => 100_000,
        'status' => 'open',
        'opened_at' => now()->subHours(2),
    ]);

    return [$organization, $branch, $cashier, $drawer, $session];
}

it('serves a cashier dashboard and keeps complete history inside the drawer session', function () {
    [$organization, $branch, $cashier, $drawer, $session] = mobileCashierContext();
    DrawerMovement::create([
        'organization_id' => $organization->id,
        'drawer_session_id' => $session->id,
        'user_id' => $cashier->id,
        'type' => 'cash_in',
        'amount' => 5_000,
        'balance_after' => 105_000,
        'reference' => 'MOB-MOV-1',
    ]);
    $type = TransactionType::create(['name' => 'Cash deposit', 'code' => 'mobile_deposit', 'direction' => 'credit', 'is_active' => true]);
    Transaction::create([
        'organization_id' => $organization->id,
        'transaction_type_id' => $type->id,
        'drawer_session_id' => $session->id,
        'branch_id' => $branch->id,
        'user_id' => $cashier->id,
        'reference' => 'MOB-OLD-DATE',
        'amount' => 5_000,
        'balance_after' => 105_000,
        'posted_at' => now()->subDay(),
    ]);
    Sanctum::actingAs($cashier, ['*']);

    $this->getJson('/api/v1/cashier/dashboard')->assertOk()
        ->assertJsonPath('data.session.id', $session->id)
        ->assertJsonPath('data.session.cash_in', 5000)
        ->assertJsonPath('data.can_close', false);
    $this->getJson('/api/v1/cashier/sessions/'.$session->id.'/transactions')->assertOk()
        ->assertJsonPath('data.0.reference', 'MOB-OLD-DATE');
    $this->getJson('/api/v1/cashier/sessions/'.$session->id.'/movements')->assertOk()
        ->assertJsonPath('data.0.reference', 'MOB-MOV-1');
});

it('does not allow staff without the cashier role to use the cashier workspace', function () {
    $organization = Organization::create(['name' => 'Other tenant', 'slug' => 'other-cashier-tenant', 'status' => 'active']);
    $role = Role::firstOrCreate(['slug' => 'loan-officer'], ['name' => 'Loan Officer']);
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => $role->id, 'status' => 'active', 'is_active' => true]);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cashier/dashboard')->assertForbidden();
});

it('previews and posts a ledger transaction with a session idempotency key', function (bool $impersonating) {
    [$organization, $branch, $cashier, $drawer, $session] = mobileCashierContext();
    ChartAccount::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'code' => 'MOB01-1000',
        'name' => 'Cash on Hand',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_cash_account' => true,
        'is_active' => true,
    ]);
    app(BranchGeneralLedgerService::class)->ensureDefaults($branch);
    $ledger = ChartAccount::where('branch_id', $branch->id)->where('code', 'MOB01-4300')->firstOrFail();
    Sanctum::actingAs($cashier, ['*']);
    expect(auth()->user()->canPerform('post-transactions'))->toBeTrue()
        ->and(ApiSecurity::canAccessTenant($cashier, $ledger))->toBeTrue();
    $payload = [
        'drawer_session_id' => $session->id,
        'account_scope' => 'general_ledger',
        'chart_account_id' => $ledger->id,
        'gl_entry_type' => 'credit',
        'amount' => 1_000,
        'charges' => 0,
        'charge_mode' => 'add_to_amount',
        'transaction_date' => today()->toDateString(),
        'description' => 'Mobile ledger receipt',
        'idempotency_key' => (string) Str::uuid(),
    ];

    if ($impersonating) {
        $admin = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin'])->id,
            'status' => 'active', 'is_active' => true,
        ]);
        Auth::forgetGuards();
        $this->withToken($admin->createToken('mobile')->plainTextToken)->withHeader('X-Impersonate-User', (string) $cashier->id);
    }

    $this->postJson('/api/v1/cashier/preview', $payload)->assertOk()->assertJsonPath('data.amount', 1000);
    if ($impersonating) {
        Auth::forgetGuards();
    }
    $response = $this->postJson('/api/v1/cashier/transactions', $payload)->assertCreated();
    $response->assertJsonPath('data.amount', '1000.00');
    if ($impersonating) {
        Auth::forgetGuards();
    }
    $this->postJson('/api/v1/cashier/transactions', $payload)
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');
    $this->assertDatabaseHas('transactions', ['drawer_session_id' => $session->id, 'description' => 'Mobile ledger receipt']);
    expect(Transaction::where('description', 'Mobile ledger receipt')->count())->toBe(1);
    if ($impersonating) {
        expect(AuditLog::query()
            ->where('user_id', $cashier->id)
            ->where('endpoint', 'api/v1/cashier/transactions')
            ->where('new_values->_impersonation->impersonator_user_id', $admin->id)
            ->exists())->toBeTrue();
    }
})->with([false, true]);

it('opens the actual cashier drawer workspace for an impersonating super admin', function () {
    [$organization, $branch, $cashier, $drawer, $session] = mobileCashierContext();
    $admin = User::factory()->create([
        'role_id' => Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin'])->id,
        'status' => 'active', 'is_active' => true,
    ]);
    $this->withToken($admin->createToken('mobile')->plainTextToken)
        ->withHeader('X-Impersonate-User', (string) $cashier->id)
        ->getJson('/api/v1/cashier/dashboard')
        ->assertOk()
        ->assertSee('Mobile Till');
});

function mobileCashierAccount(Organization $organization, Branch $branch, string $category = 'savings', string $number = 'MOB-SAV-001'): Account
{
    $client = Client::create([
        'organization_id' => $organization->id, 'branch_id' => $branch->id,
        'client_number' => 'CLIENT-'.$number, 'first_name' => 'Jane', 'last_name' => 'Namusoke',
        'phone' => '+256700123456', 'status' => 'active',
    ]);
    $type = AccountType::firstOrCreate(['code' => $category], ['name' => ucfirst($category), 'category' => $category]);

    return Account::create([
        'organization_id' => $organization->id, 'branch_id' => $branch->id, 'client_id' => $client->id,
        'account_type_id' => $type->id, 'account_number' => $number, 'account_name' => 'Everyday account',
        'status' => 'active', 'balance' => 10_000, 'opened_at' => today(),
    ]);
}

it('filters client lookup by category before limiting and returns client identity', function () {
    [$organization, $branch, $cashier, $drawer, $session] = mobileCashierContext();
    for ($index = 0; $index < 31; $index++) {
        mobileCashierAccount($organization, $branch, 'savings', 'AAA-'.$index);
    }
    $loan = mobileCashierAccount($organization, $branch, 'loan', 'ZZZ-LOAN');
    Sanctum::actingAs($cashier, ['*']);

    $this->getJson('/api/v1/cashier/lookup?'.http_build_query([
        'drawer_session_id' => $session->id, 'search' => 'Jane', 'scope' => 'client_account', 'category' => 'loan',
    ]))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $loan->id)
        ->assertJsonPath('data.0.client_name', 'Jane Namusoke')
        ->assertJsonPath('data.0.client_number', 'CLIENT-ZZZ-LOAN')
        ->assertJsonPath('data.0.client_phone', '+256700123456')
        ->assertJsonPath('data.0.currency', 'UGX');
});

it('restricts lookup to tenant and permitted branches including cross branch savings search', function (bool $crossBranch) {
    [$organization, $branch, $cashier, $drawer, $session] = mobileCashierContext();
    $local = mobileCashierAccount($organization, $branch);
    $otherBranch = Branch::create(['organization_id' => $organization->id, 'code' => 'MOB02', 'name' => 'Other branch', 'is_active' => true, 'status' => 'active']);
    $savings = mobileCashierAccount($organization, $otherBranch, 'savings', 'MOB-SAV-OTHER');
    mobileCashierAccount($organization, $otherBranch, 'loan', 'MOB-LOAN-OTHER');
    $otherOrganization = Organization::create(['name' => 'Other organization', 'slug' => 'cashier-isolation', 'status' => 'active']);
    $foreignBranch = Branch::create(['organization_id' => $otherOrganization->id, 'code' => 'FOREIGN', 'name' => 'Foreign branch', 'is_active' => true, 'status' => 'active']);
    mobileCashierAccount($otherOrganization, $foreignBranch, 'savings', 'MOB-FOREIGN');
    if ($crossBranch) {
        $permission = Permission::firstOrCreate(['slug' => 'transact-savings-across-branches'], ['name' => 'Cross branch savings']);
        $cashier->role->permissions()->attach($permission);
    }
    Sanctum::actingAs($cashier->fresh(), ['*']);

    $response = $this->getJson('/api/v1/cashier/lookup?'.http_build_query([
        'drawer_session_id' => $session->id, 'search' => '700123', 'scope' => 'client_account',
    ]))->assertOk();
    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect($crossBranch ? [$local->id, $savings->id] : [$local->id])->sort()->values()->all());
})->with([false, true]);

it('previews and posts a client deposit or repayment with drawer and account balances', function (string $category) {
    [$organization, $branch, $cashier, $drawer, $session] = mobileCashierContext();
    $account = mobileCashierAccount($organization, $branch, $category);
    $repayment = $category === 'loan';
    $type = TransactionType::create(['name' => $repayment ? 'Loan repayment' : 'Cash deposit', 'code' => $repayment ? 'loan_repayment' : 'cash_deposit', 'direction' => 'credit', 'is_active' => true]);
    if ($repayment) {
        $permission = Permission::firstOrCreate(['slug' => 'post-repayments'], ['name' => 'Post Repayments']);
        $cashier->role->permissions()->attach($permission);
        $product = LoanProduct::create(['name' => 'Mobile loan', 'code' => 'MOB-LOAN', 'annual_interest_rate' => 0]);
        $loan = Loan::create([
            'organization_id' => $organization->id, 'branch_id' => $branch->id, 'client_id' => $account->client_id,
            'loan_product_id' => $product->id, 'loan_account_id' => $account->id, 'loan_number' => 'MOB-LOAN',
            'requested_amount' => 10_000, 'principal_balance' => 10_000, 'interest_rate' => 0,
            'term_periods' => 1, 'repayment_frequency' => 'monthly', 'status' => 'active',
        ]);
        LoanSchedule::create([
            'loan_id' => $loan->id, 'installment_number' => 1, 'due_date' => today(),
            'principal_due' => 10_000, 'total_due' => 10_000, 'outstanding_amount' => 10_000, 'status' => 'pending',
        ]);
    }
    Sanctum::actingAs($cashier->fresh(), ['*']);
    $payload = [
        'drawer_session_id' => $session->id, 'account_scope' => 'client_account',
        'account_number' => $account->account_number, 'transaction_type_id' => $type->id,
        'amount' => 1_000, 'transaction_date' => today()->toDateString(), 'idempotency_key' => (string) Str::uuid(),
    ];
    $this->postJson('/api/v1/cashier/preview', $payload)->assertOk();
    expect((float) $account->fresh()->balance)->toBe(10_000.0);
    $response = $this->postJson('/api/v1/cashier/transactions', $payload)->assertCreated();
    $this->postJson('/api/v1/cashier/transactions', $payload)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect((float) $account->fresh()->balance)->toBe($repayment ? 9_000.0 : 11_000.0)
        ->and((float) $drawer->fresh()->current_balance)->toBe(106_000.0)
        ->and($session->movements()->where('reference', $response->json('data.reference'))->count())->toBe(1);
    if ($repayment) {
        expect((float) $loan->fresh()->principal_balance)->toBe(9_000.0);
    }
})->with(['savings', 'loan']);
