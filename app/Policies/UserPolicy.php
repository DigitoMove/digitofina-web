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
        return $user->canPerform('manage-users') && ($staff->organization_id === null || $user->organization_id === null || $user->organization_id === $staff->organization_id);
    }
}
