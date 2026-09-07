<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
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

it('previews and posts a ledger transaction with a session idempotency key', function () {
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

    $this->postJson('/api/v1/cashier/preview', $payload)->assertOk()->assertJsonPath('data.amount', 1000);
    $response = $this->postJson('/api/v1/cashier/transactions', $payload)->assertCreated();
    $response->assertJsonPath('data.amount', '1000.00');
    $this->postJson('/api/v1/cashier/transactions', $payload)
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');
    $this->assertDatabaseHas('transactions', ['drawer_session_id' => $session->id, 'description' => 'Mobile ledger receipt']);
    expect(Transaction::where('description', 'Mobile ledger receipt')->count())->toBe(1);
});
