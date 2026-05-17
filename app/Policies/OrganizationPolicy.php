<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $this->canManageOrganization($user)
            && ($this->isSystemAdministrator($user) || $this->sameOrganization($user, $organization));
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->canManageOrganization($user)
            && ($this->isSystemAdministrator($user) || $this->sameOrganization($user, $organization));
    }

    private function canManageOrganization(User $user): bool
    {
        return $user->canPerform('manage-organization')
            || $user->hasRole('super-admin')
            || $user->hasRole('admin')
            || $user->hasRole('organization-admin');
    }

    private function isSystemAdministrator(User $user): bool
    {
        return $user->hasRole('super-admin')
            || $user->hasRole('admin');
    }

    private function sameOrganization(User $user, Organization $organization): bool
    {
        return $user->organization_id === null || $user->organization_id === $organization->id;
    }
}
