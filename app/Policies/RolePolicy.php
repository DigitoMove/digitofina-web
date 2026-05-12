<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerform('manage-roles');
    }

    public function create(User $user): bool
    {
        return $user->canPerform('manage-roles');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->canPerform('manage-roles') && ($role->organization_id === null || $user->organization_id === null || $user->organization_id === $role->organization_id);
    }
}
