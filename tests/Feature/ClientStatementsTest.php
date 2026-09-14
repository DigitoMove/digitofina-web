<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\ClientProgressService;
use App\Services\ClientStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::persistentFake('local');
    Cache::clear();
    $this->organization = Organization::create(['name' => 'Example Finance Company', 'slug' => 'statement-fixture', 'status' => 'active', 'currency' => 'UGX', 'address' => 'Sentema Road, Wakiso, Uganda', 'phone' => '+256 700 000 000', 'email' => 'info@example.test', 'website' => 'https://example.test']);
    $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Lukwanga', 'code' => 'ST01', 'is_active' => true, 'status' => 'active']);
    $role = Role::create(['slug' => 'member', 'name' => 'Member']);
    $this->member = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id, 'must_change_password' => false, 'status' => 'active', 'is_active' => true]);
    $this->client = Client::create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'user_id' => $this->member->id, 'client_number' => 'ST-C001', 'client_type' => 'individual', 'first_name' => 'Esther Ruth', 'last_name' => 'Nanyumba', 'phone' => '0701000000', 'status' => 'active', 'kyc_status' => 'verified']);
    foreach (['clients', 'savings', 'loans', 'investments'] as $slug) {
        $module = Module::create(['name' => ucfirst($slug), 'slug' => $slug, 'status' => 'active']);
        OrganizationModule::create(['organization_id' => $this->organization->id, 'module_id' => $module->id, 'is_enabled' => true]);
    }
    $type = AccountType::create(['name' => 'Savings', 'code' => 'STATEMENT-SAV', 'category' => 'savings']);
    $this->account = Account::create(['organization_id' => $this->organization->id, 'client_id' => $this->client->id, 'branch_id' => $this->branch->id, 'account_type_id' => $type->id, 'account_number' => 'ST-SAV-001', 'account_name' => $this->client->full_name, 'balance' => 0, 'opened_at' => '2026-01-01', 'status' => 'active']);
    $this->credit = TransactionType::create(['name' => 'Cash deposit', 'code' => 'statement-deposit', 'direction' => 'credit']);
    $this->debit = TransactionType::create(['name' => 'Cash withdrawal', 'code' => 'statement-withdrawal', 'direction' => 'debit']);
});

it('keeps statement access limited to the owning client and authorized organization', function () {
    $this->actingAs($this->member)->get(route('member.statements'))->assertOk()->assertSee('ST-SAV-001')->assertSee('My Statements');
    $other = $this->client->replicate();
    $other->client_number = 'ST-C002';
    $other->user_id = null;
    $other->save();
    $otherAccount = $this->account->replicate();
    $otherAccount->account_number = 'PRIVATE-SAV-002';
    $otherAccount->client_id = $other->id;
    $otherAccount->save();
    foreach ([null, 'pdf', 'xlsx', 'csv'] as $format) {
        $this->get(route('member.statements', ['account' => 'account:'.$otherAccount->id, 'format' => $format]))->assertNotFound();
        $this->get(route('clients.statements', ['client' => $other->id, 'format' => $format]))->assertRedirect()->assertSessionHas('warning');
    }
    $otherOrganization = Organization::create(['name' => 'Other', 'slug' => 'other-statement', 'status' => 'active']);
    $other->update(['organization_id' => $otherOrganization->id]);
    $this->get(route('clients.statements', $other))->assertNotFound();
});

it('uses historical opening and closing balances including empty date ranges', function () {
    statementTransaction($this, 'JAN-DEPOSIT', '2026-01-01', 5000, 5000);
    statementTransaction($this, 'FEB-DEPOSIT', '2026-02-01', 195000, 200000);
    statementTransaction($this, 'MAR-WITHDRAWAL', '2026-03-01', 200000, 0, true);
    $this->actingAs($this->member);
    $service = app(ClientStatementService::class);
    $statement = $service->statement($this->client, ['date_from' => '2026-02-01', 'date_to' => '2026-02-28']);
    expect($statement['opening'])->toEqual(5000)->and($statement['closing'])->toEqual(200000)->and($statement['count'])->toBe(1);
    $empty = $service->statement($this->client, ['date_from' => '2026-02-10', 'date_to' => '2026-02-20']);
    expect($empty['opening'])->toEqual(200000)->and($empty['closing'])->toEqual(200000)->and($empty['count'])->toBe(0);
    $before = $service->statement($this->client, ['date_to' => '2025-12-31']);
    expect($before['closing'])->toEqual(0);
    $this->get(route('member.statements', ['date_to' => '2026-02-28']))->assertOk();
    $this->get(route('member.statements', ['date_from' => '2026-03-01', 'date_to' => '2026-02-01']))->assertSessionHasErrors('date_to');
});

it('shows the original posting and its reversal with opposite account movements', function () {
    $original = statementTransaction($this, 'ORIGINAL', '2026-01-01', 5000, 5000);
    $reversal = statementTransaction($this, 'REVERSED', '2026-01-02', 5000, 0);
    $reversal->update(['reversal_of_id' => $original->id]);
    $original->update(['status' => 'reversed', 'reversed_by' => $reversal->id, 'reversed_at' => '2026-01-02']);
    $this->actingAs($this->member);
    $statement = app(ClientStatementService::class)->statement($this->client);
    expect($statement['credits'])->toEqual(5000)->and($statement['debits'])->toEqual(5000)->and($statement['closing'])->toEqual(0);
    expect($statement['rows'][2]['transaction_type'])->toBe('Reversal');
});

it('includes savings charges in net movements and restricts the older statement route', function () {
    $product = SavingsProduct::create(['organization_id' => $this->organization->id, 'name' => 'Regular', 'code' => 'ST-REG', 'status' => 'active']);
    $account = SavingsAccount::create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'client_id' => $this->client->id, 'savings_product_id' => $product->id, 'account_number' => 'ST-PACKAGE-001', 'opening_date' => '2026-01-01', 'current_balance' => 4800, 'available_balance' => 4800, 'status' => 'active']);
    SavingsTransaction::create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'client_id' => $this->client->id, 'savings_account_id' => $account->id, 'reference' => 'CHARGED-DEPOSIT', 'type' => 'deposit', 'amount' => 5000, 'charges' => 200, 'balance_before' => 0, 'balance_after' => 4800, 'transaction_date' => '2026-01-01', 'value_date' => '2026-01-01', 'payment_method' => 'cash', 'status' => 'successful']);
    $this->actingAs($this->member);
    $statement = app(ClientStatementService::class)->statement($this->client, ['account' => 'savings:'.$account->id]);
    expect($statement['credits'])->toEqual(4800)->and($statement['closing'])->toEqual(4800);
    $this->account->update(['balance' => 1000]);
    $this->get(route('member.dashboard'))->assertOk()->assertSee('5,800.00 UGX')->assertSee('ST-SAV-001');
    $this->get(route('savings-accounts.statement', $account))->assertRedirect(route('member.statements', ['account' => 'savings:'.$account->id]));
    $this->client->update(['user_id' => null]);
    $this->get(route('savings-accounts.statement', $account))->assertRedirect()->assertSessionHas('warning');
});

it('downloads complete branded statements as real PDF and Excel files and safe CSV', function () {
    Storage::fake('public');
    Storage::fake('local');
    $logo = imagecreatetruecolor(180, 80);
    $white = imagecolorallocate($logo, 255, 255, 255);
    $green = imagecolorallocate($logo, 4, 120, 87);
    imagefill($logo, 0, 0, $white);
    imagefilledrectangle($logo, 5, 5, 70, 70, $green);
    imagestring($logo, 5, 80, 30, 'EF', $green);
    ob_start();
    imagepng($logo);
    $logoBytes = ob_get_clean();
    imagedestroy($logo);
    Storage::disk('public')->put('organization-logos/statement.png', $logoBytes);
    $this->organization->update(['logo' => 'organization-logos/statement.png']);
    for ($index = 1; $index <= 42; $index++) {
        $transaction = statementTransaction($this, 'ROW-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), '2026-01-01', 5000, 5000 * $index);
        $transaction->update(['description' => $index === 1 ? '=HYPERLINK("https://example.test")' : ($index === 42 ? str_repeat('Long transaction description for the full statement. ', 35).'END-OF-DESCRIPTION' : 'Business savings deposit for the weekly trading cycle')]);
    }
    $this->account->update(['balance' => 210000]);
    $statementPage = $this->actingAs($this->member)->get(route('member.statements'))->assertOk()->assertDontSee('ROW-042');
    $statementPage->assertSee('data:image/png;base64,', false);
    Storage::disk('local')->put('statement-layout-qa.html', $statementPage->getContent());
    $pdf = $this->get(route('member.statements', ['format' => 'pdf', 'page' => 2]))->assertOk()->assertDownload('st-sav-001-statement.pdf')->streamedContent();
    expect($pdf)->toStartWith('%PDF-')->toContain('/Subtype /Image', 'ROW-042', 'END-OF-DESCRIPTION', 'Opening balance', 'Closing balance', 'Example Finance Company', 'Sentema Road', '+256 700 000 000', 'info@example.test', 'Current Balance', 'Generated ', 'Page 1 of ');
    Storage::disk('local')->put('statement-layout-qa.pdf', $pdf);
    $xlsx = $this->get(route('member.statements', ['format' => 'xlsx']))->assertOk()->assertDownload('st-sav-001-statement.xlsx')->streamedContent();
    expect($xlsx)->toStartWith('PK');
    $path = tempnam(sys_get_temp_dir(), 'statement-xlsx');
    file_put_contents($path, $xlsx);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    expect($zip->getFromName('xl/worksheets/sheet1.xml'))->toContain('ROW-042', 'Example Finance Company', 'Sentema Road', '+256 700 000 000', 'info@example.test', 'Current Balance', 'Generated ');
    expect($zip->getFromName('xl/media/logo.png'))->toBe($logoBytes);
    $zip->close();
    unlink($path);
    $csv = $this->get(route('member.statements', ['format' => 'csv']))->assertOk()->streamedContent();
    expect($csv)->toContain('ROW-042', "'=HYPERLINK", 'Opening balance', 'Closing balance');
});

it('prioritizes unpaid installments and excludes reversed repayments from progress and loan statements', function () {
    $product = LoanProduct::create(['organization_id' => $this->organization->id, 'name' => 'Business Loan', 'code' => 'ST-LOAN', 'annual_interest_rate' => 12, 'status' => 'active']);
    $loan = Loan::create(['organization_id' => $this->organization->id, 'client_id' => $this->client->id, 'branch_id' => $this->branch->id, 'loan_product_id' => $product->id, 'loan_number' => 'ST-LN-001', 'requested_amount' => 100000, 'approved_amount' => 100000, 'principal_balance' => 80000, 'interest_balance' => 5000, 'interest_rate' => 12, 'term_periods' => 4, 'repayment_frequency' => 'monthly', 'status' => 'active']);
    $loan->schedules()->create(['installment_number' => 1, 'due_date' => today()->subDays(3), 'total_due' => 25000, 'outstanding_amount' => 25000, 'status' => 'pending']);
    $loan->schedules()->create(['installment_number' => 2, 'due_date' => today()->addMonth(), 'total_due' => 25000, 'outstanding_amount' => 25000, 'status' => 'pending']);
    $loan->repayments()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'amount' => 25000, 'principal_paid' => 20000, 'interest_paid' => 5000, 'paid_at' => today(), 'status' => 'successful', 'reference' => 'CONFIRMED-PAYMENT']);
    $loan->repayments()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'amount' => 40000, 'principal_paid' => 40000, 'paid_at' => today(), 'status' => 'reversed', 'reversed_at' => now(), 'reference' => 'REVERSED-PAYMENT']);
    $this->actingAs($this->member);
    $progress = app(ClientProgressService::class)->summarize($this->client->loans()->with(['schedules', 'repayments'])->get());
    expect($progress['percentage'])->toEqual(20)->and($progress['overdue'])->toEqual(25000)->and($progress['months']->last()['amount'])->toEqual(25000);
    $dashboard = $this->get(route('member.dashboard'))->assertOk()->assertSeeInOrder(['Repayment overdue', 'Repayment trend', 'Loans & Online Repayments', 'Request a new loan'], false)->assertSee('value="25000"', false);
    Storage::disk('local')->put('member-dashboard-qa.html', $dashboard->getContent());
    $this->get(route('member.statements', ['account' => 'loan:'.$loan->id]))->assertOk()->assertSee('CONFIRMED-PAYMENT')->assertDontSee('REVERSED-PAYMENT')->assertSee('85,000.00');
});

it('keeps staff client actions beside the statement workspace', function () {
    $role = Role::create(['slug' => 'organization-admin', 'name' => 'Organization Admin']);
    $admin = User::factory()->create(['role_id' => $role->id, 'organization_id' => $this->organization->id, 'branch_id' => null, 'is_active' => true, 'status' => 'active']);
    $dashboard = $this->actingAs($admin)->get(route('clients.show', $this->client))->assertOk()->assertSee('Manage Account')->assertSee('Client Account Dashboard')->assertSee('Account Statements')->assertSee('Accounts & statements', false)->assertSee('ST-SAV-001');
    Storage::disk('local')->put('staff-dashboard-qa.html', $dashboard->getContent());
});

it('requires the temporary password to be changed before downloading statements', function () {
    $this->member->update(['must_change_password' => true]);
    $this->actingAs($this->member)->get(route('member.statements', ['format' => 'pdf']))->assertRedirect(route('profile.edit'));
});

function statementTransaction(object $test, string $reference, string $date, float $amount, float $balance, bool $debit = false): Transaction
{
    return Transaction::create(['organization_id' => $test->organization->id, 'branch_id' => $test->branch->id, 'account_id' => $test->account->id, 'transaction_type_id' => ($debit ? $test->debit : $test->credit)->id, 'user_id' => $test->member->id, 'reference' => $reference, 'posted_at' => $date, 'amount' => $amount, 'balance_after' => $balance, 'status' => 'posted']);
}
