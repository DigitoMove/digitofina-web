<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerform('manage-branches') || $user->canPerform('view-reports');
    }

    public function create(User $user): bool
    {
        return $user->canPerform('manage-branches');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->canPerform('manage-branches') && ($branch->organization_id === null || $user->organization_id === null || $user->organization_id === $branch->organization_id);
    }
}
