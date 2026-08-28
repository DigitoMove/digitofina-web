<?php

namespace App\Support;

final class RoleCatalog
{
    /**
     * Roles supplied by DigitoFina and assignable within every organization.
     *
     * The legacy cashier aliases remain here for upgraded installations.
     *
     * @var array<int, string>
     */
    public const ASSIGNABLE_SLUGS = [
        'organization-admin',
        'branch-manager',
        'accountant',
        'loan-officer',
        'loan-recovery-officer',
        'teller-cashier',
        'cashier',
        'teller',
        'auditor',
        'data-entry-clerk',
        'read-only-viewer',
        'supervisor',
        'member',
    ];

    public const SUPER_ADMIN_SLUG = 'super-admin';
}
