<?php

namespace App\Support;

class ModuleCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function modules(): array
    {
        return [
            ['name' => 'Client Management', 'slug' => 'clients', 'category' => 'core', 'is_core' => true, 'sort_order' => 10, 'description' => 'Client, member, KYC, groups, notes, and member profiles.'],
            ['name' => 'User Management', 'slug' => 'users', 'category' => 'core', 'is_core' => true, 'sort_order' => 20, 'description' => 'Staff users and access administration.'],
            ['name' => 'Roles and Permissions', 'slug' => 'roles', 'category' => 'core', 'is_core' => true, 'sort_order' => 30, 'description' => 'Role setup and permission assignment.'],
            ['name' => 'Settings', 'slug' => 'settings', 'category' => 'core', 'is_core' => true, 'sort_order' => 40, 'description' => 'Organization and operating settings.'],
            ['name' => 'Reports', 'slug' => 'reports', 'category' => 'core', 'is_core' => true, 'sort_order' => 50, 'description' => 'Operational, portfolio, and management reports.'],
            ['name' => 'Audit Logs', 'slug' => 'audit-logs', 'category' => 'core', 'is_core' => true, 'sort_order' => 60, 'description' => 'Audit trails and system activity logs.'],
            ['name' => 'Savings Management', 'slug' => 'savings', 'category' => 'optional', 'is_core' => false, 'sort_order' => 110, 'description' => 'Savings products, accounts, deposits, withdrawals, transfers, and savings goals.'],
            ['name' => 'Loan Management', 'slug' => 'loans', 'category' => 'optional', 'is_core' => false, 'sort_order' => 120, 'description' => 'Loan products, applications, approvals, disbursement, repayments, and arrears.'],
            ['name' => 'Investment Management', 'slug' => 'investments', 'category' => 'optional', 'is_core' => false, 'sort_order' => 130, 'description' => 'Investment plans, investor accounts, transactions, returns, and maturity tracking.'],
            ['name' => 'Shares Management', 'slug' => 'shares', 'category' => 'optional', 'is_core' => false, 'sort_order' => 140, 'description' => 'Member shares, share products, and share balances.'],
            ['name' => 'Fixed Deposits', 'slug' => 'fixed-deposits', 'category' => 'optional', 'is_core' => false, 'sort_order' => 150, 'description' => 'Fixed savings and fixed deposit maturity tracking.'],
            ['name' => 'Group Savings', 'slug' => 'group-savings', 'category' => 'optional', 'is_core' => false, 'sort_order' => 160, 'description' => 'Group savings accounts and member contributions.'],
            ['name' => 'Teller/Cashier Operations', 'slug' => 'teller', 'category' => 'optional', 'is_core' => false, 'sort_order' => 170, 'description' => 'Cash drawer, non-cash posting, and end-of-day operations.'],
            ['name' => 'Accounting', 'slug' => 'accounting', 'category' => 'optional', 'is_core' => false, 'sort_order' => 180, 'description' => 'Chart of accounts, journal entries, ledgers, and financial statements.'],
            ['name' => 'Mobile Money', 'slug' => 'mobile-money', 'category' => 'optional', 'is_core' => false, 'sort_order' => 190, 'description' => 'Mobile money collections, callbacks, and reconciliation.'],
            ['name' => 'SMS Notifications', 'slug' => 'sms', 'category' => 'optional', 'is_core' => false, 'sort_order' => 200, 'description' => 'SMS providers, logs, and member notifications.'],
            ['name' => 'Document Management', 'slug' => 'documents', 'category' => 'optional', 'is_core' => false, 'sort_order' => 210, 'description' => 'Client documents, uploads, verification, and downloads.'],
            ['name' => 'API Access', 'slug' => 'api-access', 'category' => 'optional', 'is_core' => false, 'sort_order' => 220, 'description' => 'External API and mobile/offline integrations.'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function dependencies(): array
    {
        return [
            'savings' => ['clients'],
            'loans' => ['clients'],
            'investments' => ['clients'],
            'shares' => ['clients'],
            'fixed-deposits' => ['clients'],
            'group-savings' => ['clients', 'savings'],
            'teller' => ['savings', 'loans'],
            'mobile-money' => ['savings', 'loans', 'investments'],
            'sms' => ['clients'],
            'accounting' => ['savings', 'loans', 'investments', 'shares', 'fixed-deposits'],
            'api-access' => ['users', 'roles'],
            'reports' => ['clients'],
        ];
    }

    /**
     * @return array<string, array{name:string,description:string,price:float,billing_type:string,modules:array<int, string>}>
     */
    public static function packages(): array
    {
        return [
            'loan-company' => ['name' => 'Loan Company Package', 'description' => 'Client and loan operations for credit-only businesses.', 'price' => 0, 'billing_type' => 'monthly', 'modules' => ['clients', 'loans', 'reports', 'audit-logs', 'sms']],
            'savings-group' => ['name' => 'Savings Group Package', 'description' => 'Savings-led groups with member contribution tracking.', 'price' => 0, 'billing_type' => 'monthly', 'modules' => ['clients', 'savings', 'group-savings', 'reports', 'audit-logs']],
            'investment-club' => ['name' => 'Investment Club Package', 'description' => 'Investor accounts and investment tracking.', 'price' => 0, 'billing_type' => 'monthly', 'modules' => ['clients', 'investments', 'reports', 'audit-logs']],
            'sacco' => ['name' => 'SACCO Package', 'description' => 'Savings, loans, shares, accounting, reports, and audit logs.', 'price' => 0, 'billing_type' => 'monthly', 'modules' => ['clients', 'savings', 'loans', 'shares', 'accounting', 'reports', 'audit-logs', 'sms']],
            'full-mfi' => ['name' => 'Full MFI Package', 'description' => 'Complete financial institution operations.', 'price' => 0, 'billing_type' => 'monthly', 'modules' => ['clients', 'savings', 'loans', 'investments', 'shares', 'fixed-deposits', 'teller', 'accounting', 'mobile-money', 'sms', 'reports', 'api-access', 'audit-logs', 'group-savings', 'documents', 'users', 'roles', 'settings']],
        ];
    }

    public static function moduleForPermission(string $permission): ?string
    {
        return match (true) {
            str_contains($permission, 'loan'), str_contains($permission, 'repayment'), str_contains($permission, 'penalt') => 'loans',
            str_contains($permission, 'investment') => 'investments',
            str_contains($permission, 'accounting'), str_contains($permission, 'journal') => 'accounting',
            str_contains($permission, 'drawer'), str_contains($permission, 'end-of-day'), str_contains($permission, 'reversal') => 'teller',
            str_contains($permission, 'report') => 'reports',
            str_contains($permission, 'audit') => 'audit-logs',
            str_contains($permission, 'setting') => 'settings',
            str_contains($permission, 'role') => 'roles',
            str_contains($permission, 'user'), str_contains($permission, 'organization'), str_contains($permission, 'branch') => 'users',
            str_contains($permission, 'client'), str_contains($permission, 'kyc') => 'clients',
            str_contains($permission, 'import') => 'settings',
            str_contains($permission, 'api') => 'api-access',
            str_contains($permission, 'sms') => 'sms',
            default => null,
        };
    }
}
