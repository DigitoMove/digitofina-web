<?php

use App\Models\Branch;
use App\Models\FinancialDay;
use App\Models\MakerCheckerRequest;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows admins to request reversal for a posted transaction from a locked backdated business day', function () {
    $branch = Branch::create([
        'code' => '1001',
        'name' => 'Main Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $role = Role::create([
        'name' => 'Super Admin',
        'slug' => 'super-admin',
    ]);
    $admin = User::factory()->create([
        'branch_id' => null,
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
    $type = TransactionType::create([
        'name' => 'Deposit',
        'code' => 'deposit',
        'direction' => 'credit',
        'affects_cash_drawer' => true,
        'is_active' => true,
    ]);
    $postedAt = today()->subDays(5);
    $transaction = Transaction::create([
        'transaction_type_id' => $type->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'reference' => 'TX-BACKDATED-001',
        'amount' => 50000,
        'balance_after' => 50000,
        'currency' => 'UGX',
        'description' => 'Backdated posting',
        'status' => 'posted',
        'posted_at' => $postedAt,
        'created_at' => $postedAt,
    ]);

    FinancialDay::create([
        'branch_id' => $branch->id,
        'business_date' => $postedAt->toDateString(),
        'status' => 'locked',
        'opened_by' => $admin->id,
        'closed_by' => $admin->id,
        'opened_at' => $postedAt,
        'closed_at' => $postedAt,
    ]);

    $this->actingAs($admin)
        ->from(route('receipts.show', $transaction))
        ->post(route('transactions.reversal-requests.store', $transaction), [
            'reason' => 'Correcting a backdated transaction',
        ])
        ->assertRedirect(route('receipts.show', $transaction))
        ->assertSessionHas('status', 'Reversal request submitted for admin or supervisor approval.');

    $request = MakerCheckerRequest::firstOrFail();

    expect($request->action)->toBe('transaction_reversal')
        ->and($request->subject_id)->toBe($transaction->id)
        ->and($request->status)->toBe('pending')
        ->and($request->reason)->toBe('Correcting a backdated transaction');
});
