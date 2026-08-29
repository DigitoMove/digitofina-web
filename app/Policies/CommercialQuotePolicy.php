<?php

namespace App\Policies;

use App\Models\CommercialQuote;
use App\Models\User;

class CommercialQuotePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->isQuoteAdministrator($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, CommercialQuote $commercialQuote): bool
    {
        return $user->hasRole('super-admin')
            || ($user->hasRole('organization-admin') && $user->organization_id === $commercialQuote->organization_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('organization-admin') && $user->organization_id !== null;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, CommercialQuote $commercialQuote): bool
    {
        return $user->hasRole('super-admin');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CommercialQuote $commercialQuote): bool
    {
        return false;
    }

    public function pay(User $user, CommercialQuote $commercialQuote): bool
    {
        return $user->hasRole('organization-admin')
            && $user->organization_id === $commercialQuote->organization_id
            && $commercialQuote->isPayable();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CommercialQuote $commercialQuote): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CommercialQuote $commercialQuote): bool
    {
        return false;
    }

    private function isQuoteAdministrator(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'organization-admin']);
    }
}
