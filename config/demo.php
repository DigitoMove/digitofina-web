<?php

return [
    'trial_days' => (int) env('DEMO_TRIAL_DAYS', 14),

    'hidden_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('DEMO_HIDDEN_HOSTS', ''))
    ))),

    'limits' => [
        'branches' => (int) env('DEMO_MAX_BRANCHES', 2),
        'users' => (int) env('DEMO_MAX_USERS', 3),
        'clients' => (int) env('DEMO_MAX_CLIENTS', 25),
        'loans' => (int) env('DEMO_MAX_LOANS', 25),
    ],

    'permissions' => [
        'manage-organization',
        'manage-branches',
        'manage-users',
        'manage-roles',
        'manage-settings',
        'view-audit-logs',
        'manage-clients',
        'verify-kyc',
        'manage-accounts',
        'manage-investments',
        'post-transactions',
        'transact-savings-across-branches',
        'approve-transactions',
        'request-reversals',
        'approve-reversals',
        'approve-maker-checker',
        'override-maker-checker',
        'view-approvals',
        'post-backdated-transactions',
        'manage-drawers',
        'manage-end-of-day',
        'import-data',
        'manage-loans',
        'view-arrears',
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
    ],
];
