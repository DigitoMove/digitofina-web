<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerform('manage-clients') || $user->canPerform('view-reports');
    }

    public function create(User $user): bool
    {
        return $user->canPerform('manage-clients');
    }

    public function update(User $user, Client $client): bool
    {
        return $user->canPerform('manage-clients');
    }
}
