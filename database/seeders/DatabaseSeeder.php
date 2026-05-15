<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\LoanProduct;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingsProduct;
use App\Models\SystemSetting;
use App\Models\TellerDrawer;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $organization = Organization::firstOrCreate(['slug' => 'digitofina-demo'], [
            'name' => 'DigitoFina Demo SACCO',
            'registration_number' => 'DF-UG-001',
            'tax_identification_number' => '1000000000',
            'phone' => '+256 700 000 000',
            'email' => 'info@digitofina.test',
            'address' => 'Luweero, Uganda',
            'currency' => 'UGX',
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
            'interest_settings' => ['default_method' => 'reducing_balance'],
        ]);

        $branch = Branch::firstOrCreate(['code' => '1001'], [
            'organization_id' => $organization->id,
            'name' => '1001 LUWEERO',
            'phone' => '+256 700 000 001',
            'address' => 'Luweero Branch',
        ]);
        $branch->update(['organization_id' => $organization->id, 'status' => 'active']);

        $permissions = collect([
            'manage-organization',
            'manage-branches',
            'manage-users',
            'manage-roles',
            'manage-settings',
            'view-audit-logs',
            'manage-clients',
            'verify-kyc',
            'manage-accounts',
            'post-transactions',
            'approve-transactions',
            'request-reversals',
            'approve-reversals',
            'approve-maker-checker',
            'override-maker-checker',
            'view-approvals',
            'post-backdated-transactions',
            'manage-drawers',
            'manage-loans',
            'approve-loans',
            'disburse-loans',
            'post-repayments',
            'waive-penalties',
            'manage-accounting',
            'post-journal-entries',
            'write-off-loans',
            'view-reports',
            'export-reports',
            'audit-system',
            'view-own-accounts',
        ])->map(fn ($slug) => Permission::updateOrCreate(['slug' => $slug], [
            'organization_id' => $organization->id,
            'name' => str($slug)->headline(),
        ]));

        $roleMap = [
            'super-admin' => $permissions->pluck('id')->all(),
            'organization-admin' => $permissions->whereNotIn('slug', ['view-own-accounts'])->pluck('id')->all(),
            'branch-manager' => $permissions->whereIn('slug', ['manage-branches', 'manage-users', 'manage-clients', 'verify-kyc', 'manage-accounts', 'approve-transactions', 'approve-reversals', 'approve-maker-checker', 'view-approvals', 'manage-loans', 'approve-loans', 'disburse-loans', 'waive-penalties', 'view-reports', 'view-audit-logs'])->pluck('id')->all(),
            'accountant' => $permissions->whereIn('slug', ['manage-accounts', 'approve-transactions', 'approve-reversals', 'approve-maker-checker', 'view-approvals', 'manage-accounting', 'post-journal-entries', 'view-reports', 'export-reports', 'post-repayments'])->pluck('id')->all(),
            'loan-officer' => $permissions->whereIn('slug', ['manage-clients', 'verify-kyc', 'manage-loans', 'post-repayments', 'view-reports'])->pluck('id')->all(),
            'teller-cashier' => $permissions->whereIn('slug', ['manage-clients', 'manage-accounts', 'post-transactions', 'request-reversals', 'manage-drawers', 'post-repayments'])->pluck('id')->all(),
            'field-officer' => $permissions->whereIn('slug', ['manage-clients', 'manage-loans', 'post-repayments'])->pluck('id')->all(),
            'auditor' => $permissions->whereIn('slug', ['view-reports', 'export-reports', 'view-audit-logs', 'audit-system'])->pluck('id')->all(),
            'data-entry-clerk' => $permissions->whereIn('slug', ['manage-clients', 'verify-kyc'])->pluck('id')->all(),
            'read-only-viewer' => $permissions->whereIn('slug', ['view-reports'])->pluck('id')->all(),
            'admin' => $permissions->whereNotIn('slug', ['view-own-accounts'])->pluck('id')->all(),
            'management' => $permissions->whereIn('slug', ['manage-branches', 'manage-users', 'manage-clients', 'verify-kyc', 'manage-accounts', 'approve-transactions', 'approve-reversals', 'approve-maker-checker', 'view-approvals', 'manage-loans', 'approve-loans', 'disburse-loans', 'write-off-loans', 'waive-penalties', 'view-reports', 'audit-system'])->pluck('id')->all(),
            'officer' => $permissions->whereIn('slug', ['manage-clients', 'manage-accounts', 'manage-loans', 'view-reports'])->pluck('id')->all(),
            'cashier' => $permissions->whereIn('slug', ['manage-clients', 'manage-accounts', 'post-transactions', 'manage-drawers'])->pluck('id')->all(),
            'member' => $permissions->whereIn('slug', ['view-own-accounts'])->pluck('id')->all(),
        ];

        foreach ($roleMap as $slug => $permissionIds) {
            $role = Role::updateOrCreate(['slug' => $slug], ['organization_id' => $organization->id, 'name' => str($slug)->headline()]);
            $role->permissions()->sync($permissionIds);
        }

        $admin = User::updateOrCreate(['email' => 'admin@digitofina.test'], [
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'name' => 'DigitoFina Admin',
            'phone' => '+256 700 000 100',
            'position' => 'System Administrator',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);

        $teller = User::updateOrCreate(['email' => 'teller@digitofina.test'], [
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'role_id' => Role::where('slug', 'cashier')->value('id'),
            'name' => 'Luweero Cashier',
            'phone' => '+256 700 000 101',
            'position' => 'Cashier',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);

        User::updateOrCreate(['email' => 'cashier@digitofina.test'], [
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'role_id' => Role::where('slug', 'cashier')->value('id'),
            'name' => 'Luweero Cashier',
            'phone' => '+256 700 000 101',
            'position' => 'Cashier',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);

        User::updateOrCreate(['email' => 'officer@digitofina.test'], [
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'role_id' => Role::where('slug', 'officer')->value('id'),
            'name' => 'Luweero Loans Officer',
            'phone' => '+256 700 000 102',
            'position' => 'Credit Officer',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);

        User::updateOrCreate(['email' => 'management@digitofina.test'], [
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'role_id' => Role::where('slug', 'management')->value('id'),
            'name' => 'Luweero Management',
            'phone' => '+256 700 000 103',
            'position' => 'Management',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);

        $member = User::updateOrCreate(['email' => 'member@digitofina.test'], [
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'role_id' => Role::where('slug', 'member')->value('id'),
            'name' => 'Amina Nakato',
            'phone' => '+256 701 111 222',
            'position' => 'Member',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);

        $branch->update(['manager_user_id' => $admin->id]);

        $accountTypes = [
            ['name' => 'Savings Account', 'code' => 'SAV', 'category' => 'savings', 'minimum_balance' => 5000],
            ['name' => 'Loan Account', 'code' => 'LON', 'category' => 'loan', 'minimum_balance' => 0],
            ['name' => 'Shares Account', 'code' => 'SHA', 'category' => 'shares', 'minimum_balance' => 0],
            ['name' => 'Fixed Deposit Account', 'code' => 'FD', 'category' => 'fixed_deposit', 'minimum_balance' => 100000],
        ];

        foreach ($accountTypes as $type) {
            AccountType::updateOrCreate(['code' => $type['code']], $type + ['organization_id' => $organization->id]);
        }

        $transactionTypes = [
            ['name' => 'Deposit', 'code' => 'deposit', 'direction' => 'credit', 'affects_cash_drawer' => true],
            ['name' => 'Withdrawal', 'code' => 'withdrawal', 'direction' => 'withdrawal', 'affects_cash_drawer' => true],
            ['name' => 'Loan Repayment', 'code' => 'loan_repayment', 'direction' => 'credit', 'affects_cash_drawer' => true],
            ['name' => 'Shares Deposit', 'code' => 'shares_deposit', 'direction' => 'credit', 'affects_cash_drawer' => true],
            ['name' => 'Charges', 'code' => 'charges', 'direction' => 'debit', 'affects_cash_drawer' => false],
            ['name' => 'Transfer', 'code' => 'transfer', 'direction' => 'transfer', 'affects_cash_drawer' => false],
            ['name' => 'Loan Disbursement', 'code' => 'loan_disbursement', 'direction' => 'credit', 'affects_cash_drawer' => false],
        ];

        foreach ($transactionTypes as $type) {
            TransactionType::updateOrCreate(['code' => $type['code']], $type + ['organization_id' => $organization->id]);
        }

        $chartAccounts = [
            ['code' => '1000', 'name' => 'Cash on Hand', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_account' => true],
            ['code' => '1010', 'name' => 'Cash in Teller Drawers', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_account' => true],
            ['code' => '1020', 'name' => 'Cash in Vault', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_account' => true],
            ['code' => '1100', 'name' => 'Bank Current Account', 'type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '1200', 'name' => 'Loans Receivable', 'type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '1300', 'name' => 'Interest Receivable', 'type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '2100', 'name' => 'Client Savings Liability', 'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '2200', 'name' => 'Client Fixed Deposit Liability', 'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '2300', 'name' => 'Charges Payable', 'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '3000', 'name' => 'Member Shares / Equity', 'type' => 'equity', 'normal_balance' => 'credit'],
            ['code' => '4000', 'name' => 'Operating Income', 'type' => 'income', 'normal_balance' => 'credit'],
            ['code' => '4100', 'name' => 'Interest Income', 'type' => 'income', 'normal_balance' => 'credit'],
            ['code' => '4200', 'name' => 'Fees and Penalties Income', 'type' => 'income', 'normal_balance' => 'credit'],
            ['code' => '5000', 'name' => 'Operating Expenses', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '5100', 'name' => 'Loan Loss Provision Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
        ];

        foreach ($chartAccounts as $account) {
            ChartAccount::updateOrCreate(['code' => $account['code']], $account + ['organization_id' => $organization->id, 'is_system' => true, 'is_active' => true]);
        }

        $settings = [
            ['key' => 'currency', 'label' => 'Default currency', 'value' => 'UGX', 'type' => 'string', 'group' => 'general', 'description' => 'Currency used for financial amounts.'],
            ['key' => 'date_format', 'label' => 'Date format', 'value' => 'd M Y', 'type' => 'string', 'group' => 'general', 'description' => 'Display format for operational dates.'],
            ['key' => 'client_number_format', 'label' => 'Client number format', 'value' => '{branch}-C{sequence}', 'type' => 'string', 'group' => 'numbering', 'description' => 'Template for generated client numbers.'],
            ['key' => 'account_number_format', 'label' => 'Account number format', 'value' => '{branch}-{product}-{sequence}', 'type' => 'string', 'group' => 'numbering', 'description' => 'Template for generated account numbers.'],
            ['key' => 'loan_number_format', 'label' => 'Loan number format', 'value' => '{branch}-LN-{sequence}', 'type' => 'string', 'group' => 'numbering', 'description' => 'Template for generated loan numbers.'],
            ['key' => 'repayment_priority', 'label' => 'Repayment allocation priority', 'value' => 'penalties,fees,interest,principal', 'type' => 'string', 'group' => 'loans', 'description' => 'Order used when allocating loan repayments.'],
            ['key' => 'small_loan_approval_limit', 'label' => 'Small loan approval limit', 'value' => '1000000', 'type' => 'money', 'group' => 'approvals', 'description' => 'Maximum amount for branch manager approval.'],
            ['key' => 'large_withdrawal_maker_checker_threshold', 'label' => 'Large withdrawal checker threshold', 'value' => '5000000', 'type' => 'money', 'group' => 'approvals', 'description' => 'Withdrawals at or above this amount require maker-checker approval.'],
            ['key' => 'suspicious_transaction_threshold', 'label' => 'Suspicious transaction threshold', 'value' => '10000000', 'type' => 'money', 'group' => 'controls', 'description' => 'Transactions at or above this amount create suspicious activity alerts.'],
            ['key' => 'sms_sender_id', 'label' => 'SMS sender ID', 'value' => 'DigitoFina', 'type' => 'string', 'group' => 'notifications', 'description' => 'Default SMS sender name.'],
            ['key' => 'mobile_money_default_provider', 'label' => 'Default mobile money provider', 'value' => 'manual', 'type' => 'string', 'group' => 'integrations', 'description' => 'Payment provider used for new collection requests.'],
        ];

        foreach ($settings as $setting) {
            SystemSetting::updateOrCreate(['key' => $setting['key']], $setting + ['organization_id' => $organization->id]);
        }

        $client = Client::firstOrCreate(['client_number' => '1001-C000001'], [
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'officer_id' => $admin->id,
            'user_id' => $member->id,
            'client_type' => 'individual',
            'first_name' => 'Amina',
            'last_name' => 'Nakato',
            'gender' => 'female',
            'nationality' => 'Ugandan',
            'phone' => '+256 701 111 222',
            'email' => 'amina@example.test',
            'national_id' => 'CM90000001',
            'address' => 'Luweero town',
            'district' => 'Luweero',
            'region' => 'Central',
            'next_of_kin_name' => 'Musa Nakato',
            'next_of_kin_phone' => '+256 702 222 333',
            'employment_status' => 'Business owner',
            'business_name' => 'Amina Produce Traders',
            'kyc_status' => 'verified',
            'registered_at' => now()->toDateString(),
        ]);

        if (! $client->user_id) {
            $client->update(['user_id' => $member->id]);
        }

        Account::firstOrCreate(['account_number' => '1001-SAV-0000001'], [
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'branch_id' => $branch->id,
            'account_type_id' => AccountType::where('code', 'SAV')->value('id'),
            'account_name' => 'Amina Nakato Savings',
            'status' => 'active',
            'balance' => 150000,
            'opened_at' => now()->toDateString(),
        ]);

        SavingsProduct::firstOrCreate(['code' => 'SAV-REG'], [
            'organization_id' => $organization->id,
            'name' => 'Regular Member Savings',
            'minimum_opening_balance' => 10000,
            'minimum_balance' => 5000,
            'interest_rate' => 2.5,
            'interest_posting_frequency' => 'monthly',
            'withdrawal_charge' => 1000,
            'deposit_charge' => 0,
            'monthly_maintenance_fee' => 0,
            'account_number_prefix' => 'SAV',
            'allows_overdraft' => false,
        ]);

        LoanProduct::firstOrCreate(['code' => 'MICRO-12'], [
            'organization_id' => $organization->id,
            'name' => 'Micro Business Loan',
            'description' => 'Working capital loans for member businesses.',
            'min_amount' => 100000,
            'max_amount' => 5000000,
            'min_term' => 1,
            'max_term' => 24,
            'term_unit' => 'months',
            'annual_interest_rate' => 24,
            'interest_method' => 'reducing_balance',
            'interest_frequency' => 'monthly',
            'repayment_frequency' => 'monthly',
            'default_term_months' => 12,
            'grace_period_days' => 0,
            'penalty_type' => 'percentage',
            'penalty_rate' => 2,
            'processing_fee' => 25000,
            'insurance_fee' => 0,
            'application_fee' => 10000,
            'required_savings_percentage' => 10,
            'require_guarantors' => true,
            'minimum_guarantors' => 1,
            'require_collateral' => false,
            'auto_deduct_repayment_from_savings' => false,
            'loan_account_number_prefix' => 'LN',
            'status' => 'active',
        ]);

        TellerDrawer::firstOrCreate(['code' => 'TD-1001-01'], [
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'assigned_user_id' => $teller->id,
            'name' => '1001 LUWEERO',
            'currency' => 'UGX',
            'current_balance' => 75800,
            'status' => 'closed',
        ]);
    }
}
