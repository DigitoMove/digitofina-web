<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('organization-admin') || $user->canPerform('manage-users');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('organization-admin') || $user->canPerform('manage-users');
    }

    public function update(User $user, User $staff): bool
    {
        return ($user->hasRole('organization-admin') || $user->canPerform('manage-users'))
            && ! $staff->isMember()
            && ($user->hasRole('super-admin') || ! $staff->hasRole('super-admin'))
            && ($user->hasGlobalOrganizationAccess() || (int) $user->organization_id === (int) $staff->organization_id);
    }
}
