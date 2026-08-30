<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Support\RoleCatalog;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return ! $user->isTellerCashier()
            && ! $user->isMember()
            && $user->canPerform('manage-clients');
    }

    public function view(User $user, Client $client): bool
    {
        if ($user->isMember()) {
            return (int) $client->user_id === (int) $user->id;
        }

        return $this->canReadClient($user, $client);
    }

    public function update(User $user, Client $client): bool
    {
        return $user->canPerform('manage-clients') && $this->canAccessBranch($user, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        return ($user->hasRole('super-admin') || $user->hasRole('organization-admin') || $user->canPerform('manage-organization'))
            && $this->canAccessBranch($user, $client);
    }

    public function impersonate(User $user, Client $client): bool
    {
        if (! $user->hasAnyRole([RoleCatalog::SUPER_ADMIN_SLUG, 'organization-admin'])) {
            return false;
        }

        return $user->hasRole(RoleCatalog::SUPER_ADMIN_SLUG)
            || (int) $user->organization_id === (int) $client->organization_id;
    }

    public function resetPortalAccess(User $user, Client $client): bool
    {
        return $this->impersonate($user, $client);
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
        if (! $user->canAccessOrganization($client->organization_id)) {
            return false;
        }

        if ($user->isLoanOfficer()) {
            if ((int) $client->branch_id !== (int) $user->branch_id) {
                return false;
            }

            if ((int) $client->officer_id === (int) $user->id) {
                return true;
            }

            if ($client->officer_id !== null) {
                return false;
            }

            return $client->loans()
                ->where('officer_id', $user->id)
                ->exists();
        }

        return $user->branch_id === null
            || (int) $client->branch_id === (int) $user->branch_id;
    }

    private function canReadClient(User $user, Client $client): bool
    {
        return $user->canAccessBranch($client->branch_id, $client->organization_id);
    }

    private function loanOfficerCanAccessClient(User $user, Client $client): bool
    {
        if ((int) $client->officer_id === (int) $user->id
            && $user->canAccessOrganization($client->organization_id)
            && ($user->branch_id === null || (int) $client->branch_id === (int) $user->branch_id)) {
            return true;
        }

        return $client->loans()
            ->where('officer_id', $user->id)
            ->where(function ($query) use ($user): void {
                $query
                    ->whereNull('organization_id')
                    ->orWhere('organization_id', $user->organization_id);
            })
            ->when($user->branch_id, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->exists();
    }
}
