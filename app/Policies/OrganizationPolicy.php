<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->canPerform('manage-organization') && $this->sameOrganization($user, $organization);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->canPerform('manage-organization') && $this->sameOrganization($user, $organization);
    }

    private function sameOrganization(User $user, Organization $organization): bool
    {
        return $user->organization_id === null || $user->organization_id === $organization->id;
    }
}
