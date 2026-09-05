<?php

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\FinancialDay;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ClientDeletionService;
use App\Services\ClientStatementService;
use App\Services\FinancialControlService;
use App\Services\SavingsTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::create(['name' => 'Reversal Test', 'slug' => 'reversal-test', 'status' => 'active']);
    $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Test Branch', 'code' => 'RS01', 'status' => 'active', 'is_active' => true]);
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id, 'status' => 'active', 'is_active' => true]);
    $this->actingAs($this->admin);
    $client = Client::create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'client_number' => 'RS-C001', 'first_name' => 'Test', 'last_name' => 'Client', 'phone' => '0700000000', 'status' => 'active']);
    $product = SavingsProduct::create(['organization_id' => $this->organization->id, 'name' => 'Test Savings', 'code' => 'RSS', 'minimum_balance' => 0, 'deposit_charge' => 100, 'withdrawal_charge' => 200, 'status' => 'active']);
    $this->account = SavingsAccount::create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'client_id' => $client->id, 'savings_product_id' => $product->id, 'account_number' => 'RS-S001', 'opening_date' => today(), 'current_balance' => 0, 'available_balance' => 0, 'status' => 'active']);
    foreach (['1000' => 'asset', '2100' => 'liability', '4200' => 'income'] as $code => $type) {
        ChartAccount::create(['organization_id' => $this->organization->id, 'code' => $code, 'name' => $type, 'type' => $type, 'normal_balance' => $type === 'asset' ? 'debit' : 'credit', 'is_active' => true]);
    }
    $this->service = app(SavingsTransactionService::class);
});

it('reverses a historical deposit without overwriting later transactions and offsets its exact journal', function () {
    $this->travelTo(now()->setTime(9, 0));
    $original = $this->service->deposit($this->account, 10000, $this->admin);
    $this->travel(1)->hours();
    $later = $this->service->deposit($this->account, 20000, $this->admin);
    $this->travel(1)->hours();
    $this->service->reverse($original, $this->admin, 'Duplicate deposit');

    $reversal = SavingsTransaction::where('reversal_of_id', $original->id)->sole();
    expect($this->account->fresh()->current_balance)->toBe('19900.00')
        ->and($later->fresh()->balance_after)->toBe('29800.00')
        ->and($original->fresh()->balance_after)->toBe('9900.00')
        ->and($original->fresh()->status)->toBe('reversed')
        ->and($original->fresh()->reversed_at)->not->toBeNull()
        ->and($reversal->balance_before)->toBe('29800.00')
        ->and($reversal->balance_after)->toBe('19900.00');
    $originalLines = JournalEntry::findOrFail($original->journal_entry_id)->lines->keyBy('chart_account_id');
    foreach (JournalEntry::findOrFail($reversal->journal_entry_id)->lines as $line) {
        expect($line->debit)->toBe($originalLines[$line->chart_account_id]->credit)
            ->and($line->credit)->toBe($originalLines[$line->chart_account_id]->debit);
    }
    $statement = app(ClientStatementService::class)->forAccount($this->account->client, $this->account->fresh(), 'savings:'.$this->account->id);
    expect($statement['count'])->toBe(3)->and($statement['closing'])->toBe(19900.0)
        ->and(collect($statement['rows'])->where('transaction_type', 'Reversal'))->toHaveCount(1);
});

it('uses the safe reversal path for maker checker approvals including withdrawal charges', function () {
    $this->account->update(['current_balance' => 100000, 'available_balance' => 100000]);
    $original = $this->service->withdraw($this->account, 10000, $this->admin);
    $this->service->deposit($this->account, 20000, $this->admin);
    $controls = app(FinancialControlService::class);
    $request = $controls->requestAction($this->admin, 'savings_transaction_reversal', $original, [], (float) $original->amount, 'approve-reversals', 'Incorrect withdrawal');
    $controls->approveMakerChecker($request, $this->admin);
    expect($request->fresh()->status)->toBe('approved')
        ->and($this->account->fresh()->current_balance)->toBe('119900.00')
        ->and(SavingsTransaction::where('reversal_of_id', $original->id)->count())->toBe(1);
});

it('rejects a second reversal using a stale transaction instance without any additional postings', function () {
    $original = $this->service->deposit($this->account, 10000, $this->admin);
    $stale = $original->fresh();
    $this->service->reverse($original, $this->admin, 'First reversal');
    expect(fn () => $this->service->reverse($stale, $this->admin, 'Duplicate reversal'))->toThrow(InvalidArgumentException::class, 'unreversed');
    expect(SavingsTransaction::count())->toBe(2)->and(JournalEntry::count())->toBe(2)
        ->and($this->account->fresh()->current_balance)->toBe('0.00');
});

it('stops safely when the original journal is missing or its movement is inconsistent', function (bool $inconsistent) {
    $original = $this->service->deposit($this->account, 10000, $this->admin);
    if ($inconsistent) {
        $original->update(['balance_after' => 9999]);
    } else {
        $original->update(['journal_entry_id' => null]);
        JournalEntry::first()->update(['description' => 'Unrelated journal']);
    }
    expect(fn () => $this->service->reverse($original, $this->admin, 'Cannot match'))->toThrow(InvalidArgumentException::class);
    expect(SavingsTransaction::count())->toBe(1)->and(JournalEntry::count())->toBe(1)
        ->and($this->account->fresh()->current_balance)->toBe('9900.00')
        ->and($original->fresh()->status)->toBe('successful');
})->with([false, true]);

it('matches an unlinked legacy journal without resetting later balances', function () {
    $original = $this->service->deposit($this->account, 10000, $this->admin);
    $original->update(['journal_entry_id' => null]);
    $this->service->deposit($this->account, 20000, $this->admin);
    $this->service->reverse($original, $this->admin, 'Legacy correction');
    expect($this->account->fresh()->current_balance)->toBe('19900.00')->and($original->fresh()->journal_entry_id)->not->toBeNull();
});

it('requires approval permission and an open current financial day', function () {
    $original = $this->service->deposit($this->account, 10000, $this->admin);
    $role = Role::create(['name' => 'Member', 'slug' => 'member']);
    $member = User::factory()->create(['role_id' => $role->id, 'organization_id' => $this->organization->id]);
    expect(fn () => $this->service->reverse($original, $member, 'Not authorized'))->toThrow(InvalidArgumentException::class, 'permission');
    FinancialDay::create(['branch_id' => $this->branch->id, 'business_date' => today(), 'status' => 'locked']);
    expect(fn () => $this->service->reverse($original, $this->admin, 'Locked day'))->toThrow(InvalidArgumentException::class, 'closed');
    expect($original->fresh()->status)->toBe('successful')->and($this->account->fresh()->current_balance)->toBe('9900.00');
});

it('rolls back the compensating journal and balance if the audit write fails', function () {
    $original = $this->service->deposit($this->account, 10000, $this->admin);
    $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
    expect(fn () => app(SavingsTransactionService::class)->reverse($original, $this->admin, 'Atomic correction'))->toThrow(RuntimeException::class, 'Audit unavailable');
    expect(SavingsTransaction::count())->toBe(1)->and(JournalEntry::count())->toBe(1)
        ->and($original->fresh()->status)->toBe('successful')->and($this->account->fresh()->current_balance)->toBe('9900.00');
});

it('preserves linked reversal history when permanent client deletion is attempted', function () {
    $original = $this->service->deposit($this->account, 10000, $this->admin);
    $this->service->reverse($original, $this->admin, 'Preserved correction');
    expect(fn () => app(ClientDeletionService::class)->delete($this->account->client))->toThrow(ValidationException::class);
    expect(SavingsTransaction::count())->toBe(2)->and(JournalEntry::count())->toBe(2)
        ->and(Client::whereKey($this->account->client_id)->exists())->toBeTrue();
});
