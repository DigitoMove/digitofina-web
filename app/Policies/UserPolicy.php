<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerform('manage-users');
    }

    public function create(User $user): bool
    {
        return $user->canPerform('manage-users');
    }

    public function update(User $user, User $staff): bool
    {
        return $user->canPerform('manage-users') && $user->canAccessOrganization($staff->organization_id);
    }
}
