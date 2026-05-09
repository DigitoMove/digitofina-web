<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerform('manage-accounts') || $user->canPerform('view-reports');
    }

    public function create(User $user): bool
    {
        return $user->canPerform('manage-accounts');
    }

    public function view(User $user, Account $account): bool
    {
        return $user->canPerform('manage-accounts') || $user->canPerform('view-reports');
    }
}
