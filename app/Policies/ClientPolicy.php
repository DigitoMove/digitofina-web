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

    public function view(User $user, Client $client): bool
    {
        return ($user->canPerform('manage-clients') || $user->canPerform('view-reports'))
            && $this->canAccessBranch($user, $client);
    }

    public function update(User $user, Client $client): bool
    {
        return $user->canPerform('manage-clients') && $this->canAccessBranch($user, $client);
    }

    public function uploadDocument(User $user, Client $client): bool
    {
        return $this->update($user, $client);
    }

    public function verifyKyc(User $user, Client $client): bool
    {
        return ($user->canPerform('verify-kyc') || $user->canPerform('manage-clients')) && $this->canAccessBranch($user, $client);
    }

    private function canAccessBranch(User $user, Client $client): bool
    {
        if ($user->hasRole('super-admin') || $user->canPerform('manage-organization') || $user->canPerform('audit-system')) {
            return true;
        }

        if ($user->organization_id !== null && $client->organization_id !== null && $user->organization_id !== $client->organization_id) {
            return false;
        }

        return $user->branch_id === null || $user->branch_id === $client->branch_id;
    }
}
