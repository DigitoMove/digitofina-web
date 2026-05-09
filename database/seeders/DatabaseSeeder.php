<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\LoanProduct;
use App\Models\Permission;
use App\Models\Role;
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
        $branch = Branch::firstOrCreate(['code' => '1001'], [
            'name' => '1001 LUWEERO',
            'phone' => '+256 700 000 001',
            'address' => 'Luweero Branch',
        ]);

        $permissions = collect([
            'manage-users', 'manage-clients', 'manage-accounts', 'post-transactions',
            'manage-drawers', 'manage-loans', 'approve-loans', 'view-reports', 'audit-system',
            'view-own-accounts',
        ])->map(fn ($slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => str($slug)->headline()]));

        $roleMap = [
            'admin' => $permissions->whereNotIn('slug', ['view-own-accounts'])->pluck('id')->all(),
            'management' => $permissions->whereIn('slug', ['manage-clients', 'manage-accounts', 'manage-loans', 'approve-loans', 'view-reports', 'audit-system'])->pluck('id')->all(),
            'officer' => $permissions->whereIn('slug', ['manage-clients', 'manage-accounts', 'manage-loans', 'view-reports'])->pluck('id')->all(),
            'cashier' => $permissions->whereIn('slug', ['manage-clients', 'manage-accounts', 'post-transactions', 'manage-drawers'])->pluck('id')->all(),
            'member' => $permissions->whereIn('slug', ['view-own-accounts'])->pluck('id')->all(),
        ];

        foreach ($roleMap as $slug => $permissionIds) {
            $role = Role::firstOrCreate(['slug' => $slug], ['name' => str($slug)->headline()]);
            $role->permissions()->sync($permissionIds);
        }

        $admin = User::updateOrCreate(['email' => 'admin@digitofina.test'], [
            'branch_id' => $branch->id,
            'role_id' => Role::where('slug', 'admin')->value('id'),
            'name' => 'DigitoFina Admin',
            'phone' => '+256 700 000 100',
            'position' => 'System Administrator',
            'password' => Hash::make('password'),
        ]);

        $teller = User::updateOrCreate(['email' => 'teller@digitofina.test'], [
            'branch_id' => $branch->id,
            'role_id' => Role::where('slug', 'cashier')->value('id'),
            'name' => 'Luweero Cashier',
            'phone' => '+256 700 000 101',
            'position' => 'Cashier',
            'password' => Hash::make('password'),
        ]);

        User::updateOrCreate(['email' => 'cashier@digitofina.test'], [
            'branch_id' => $branch->id,
            'role_id' => Role::where('slug', 'cashier')->value('id'),
            'name' => 'Luweero Cashier',
            'phone' => '+256 700 000 101',
            'position' => 'Cashier',
            'password' => Hash::make('password'),
        ]);

        User::updateOrCreate(['email' => 'officer@digitofina.test'], [
            'branch_id' => $branch->id,
            'role_id' => Role::where('slug', 'officer')->value('id'),
            'name' => 'Luweero Loans Officer',
            'phone' => '+256 700 000 102',
            'position' => 'Credit Officer',
            'password' => Hash::make('password'),
        ]);

        User::updateOrCreate(['email' => 'management@digitofina.test'], [
            'branch_id' => $branch->id,
            'role_id' => Role::where('slug', 'management')->value('id'),
            'name' => 'Luweero Management',
            'phone' => '+256 700 000 103',
            'position' => 'Management',
            'password' => Hash::make('password'),
        ]);

        $member = User::updateOrCreate(['email' => 'member@digitofina.test'], [
            'branch_id' => $branch->id,
            'role_id' => Role::where('slug', 'member')->value('id'),
            'name' => 'Amina Nakato',
            'phone' => '+256 701 111 222',
            'position' => 'Member',
            'password' => Hash::make('password'),
        ]);

        $accountTypes = [
            ['name' => 'Savings Account', 'code' => 'SAV', 'category' => 'savings', 'minimum_balance' => 5000],
            ['name' => 'Loan Account', 'code' => 'LON', 'category' => 'loan', 'minimum_balance' => 0],
            ['name' => 'Shares Account', 'code' => 'SHA', 'category' => 'shares', 'minimum_balance' => 0],
            ['name' => 'Fixed Deposit Account', 'code' => 'FD', 'category' => 'fixed_deposit', 'minimum_balance' => 100000],
        ];

        foreach ($accountTypes as $type) {
            AccountType::firstOrCreate(['code' => $type['code']], $type);
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
            TransactionType::firstOrCreate(['code' => $type['code']], $type);
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
            ChartAccount::updateOrCreate(['code' => $account['code']], $account + ['is_system' => true, 'is_active' => true]);
        }

        $client = Client::firstOrCreate(['client_number' => '1001-C000001'], [
            'branch_id' => $branch->id,
            'officer_id' => $admin->id,
            'user_id' => $member->id,
            'first_name' => 'Amina',
            'last_name' => 'Nakato',
            'phone' => '+256 701 111 222',
            'email' => 'amina@example.test',
            'national_id' => 'CM90000001',
            'address' => 'Luweero town',
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
            'client_id' => $client->id,
            'branch_id' => $branch->id,
            'account_type_id' => AccountType::where('code', 'SAV')->value('id'),
            'account_name' => 'Amina Nakato Savings',
            'status' => 'active',
            'balance' => 150000,
            'opened_at' => now()->toDateString(),
        ]);

        LoanProduct::firstOrCreate(['code' => 'MICRO-12'], [
            'name' => 'Micro Business Loan',
            'min_amount' => 100000,
            'max_amount' => 5000000,
            'annual_interest_rate' => 24,
            'interest_method' => 'reducing_balance',
            'repayment_frequency' => 'monthly',
            'default_term_months' => 12,
            'grace_period_days' => 0,
        ]);

        TellerDrawer::firstOrCreate(['code' => 'TD-1001-01'], [
            'branch_id' => $branch->id,
            'assigned_user_id' => $teller->id,
            'name' => '1001 LUWEERO',
            'currency' => 'UGX',
            'current_balance' => 75800,
            'status' => 'closed',
        ]);
    }
}
