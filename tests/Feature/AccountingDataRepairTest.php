<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('backfills legacy application fee ownership and missing income accounts idempotently', function () {
    $organization = Organization::create([
        'name' => 'Legacy Fee SACCO',
        'slug' => 'legacy-fee-sacco',
        'status' => 'active',
    ]);
    $branch = Branch::create([
        'organization_id' => $organization->id,
        'code' => 'LFS',
        'name' => 'Legacy Fee Branch',
        'is_active' => true,
        'status' => 'active',
    ]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
    ]);
    $client = Client::create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'client_number' => 'LFS-C-001',
        'client_type' => 'individual',
        'first_name' => 'Legacy',
        'last_name' => 'Client',
        'phone' => '+256700000001',
        'status' => 'active',
        'registered_at' => today(),
    ]);
    $product = LoanProduct::create([
        'organization_id' => $organization->id,
        'name' => 'Legacy Fee Loan',
        'code' => 'LFS-LOAN',
        'min_amount' => 1,
        'max_amount' => 10_000,
        'annual_interest_rate' => 12,
        'interest_method' => 'flat',
        'repayment_frequency' => 'monthly',
        'default_term_months' => 1,
        'status' => 'active',
        'is_active' => true,
    ]);
    $loan = Loan::create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'loan_product_id' => $product->id,
        'loan_number' => 'LFS-LN-001',
        'requested_amount' => 1_000,
        'approved_amount' => 1_000,
        'interest_rate' => 12,
        'term_periods' => 1,
        'repayment_frequency' => 'monthly',
        'status' => 'active',
        'application_date' => today(),
    ]);
    $transactionTypeId = DB::table('transaction_types')->insertGetId([
        'organization_id' => $organization->id,
        'name' => 'Loan Application Fee',
        'code' => 'loan_application_fee',
        'direction' => 'debit',
        'affects_cash_drawer' => false,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $transactionId = DB::table('transactions')->insertGetId([
        'organization_id' => null,
        'transaction_type_id' => $transactionTypeId,
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'reference' => 'LFS-TX-001',
        'amount' => 75,
        'currency' => 'UGX',
        'description' => 'Loan application fee paid from savings for loan LFS-LN-001',
        'status' => 'posted',
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $journalId = DB::table('journal_entries')->insertGetId([
        'organization_id' => null,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'posted_by' => $user->id,
        'journal_number' => 'LFS-JE-001',
        'journal_date' => today(),
        'description' => 'Loan application fee paid from savings for loan LFS-LN-001',
        'status' => 'posted',
        'posted_at' => now(),
        'transaction_id' => $transactionId,
        'reference' => 'LFS-TX-001',
        'account_name' => 'Application fee',
        'entry_type' => 'credit',
        'amount' => 75,
        'memo' => 'Loan application fee income paid from savings',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $disbursementId = DB::table('loan_disbursements')->insertGetId([
        'organization_id' => null,
        'branch_id' => $branch->id,
        'loan_id' => $loan->id,
        'disbursed_by' => $user->id,
        'disbursed_amount' => 1_000,
        'fees_deducted' => 75,
        'net_amount' => 925,
        'payment_method' => 'cash',
        'transaction_reference' => 'LFS-DISB-001',
        'disbursement_date' => today(),
        'status' => 'successful',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('loan_disbursements')->where('id', $disbursementId)->value('organization_id'))->toBeNull()
        ->and((int) DB::table('loans')->where('id', $loan->id)->value('organization_id'))->toBe($organization->id);

    $migration = require database_path('migrations/2026_09_01_013440_backfill_application_fee_organizations_and_ledger_reversals.php');
    $migration->up();
    $migration->up();

    $journal = DB::table('journal_entries')->find($journalId);

    expect((int) DB::table('loan_disbursements')->where('id', $disbursementId)->value('organization_id'))->toBe($organization->id)
        ->and((int) DB::table('transactions')->where('id', $transactionId)->value('organization_id'))->toBe($organization->id)
        ->and((int) $journal->organization_id)->toBe($organization->id)
        ->and($journal->account_name)->toBe('Application Fees Income')
        ->and(DB::table('chart_accounts')->where('id', $journal->chart_account_id)->value('code'))->toBe('LFS-4330')
        ->and(DB::table('chart_accounts')->where('code', 'LFS-4330')->count())->toBe(1);
});
