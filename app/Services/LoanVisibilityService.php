<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class LoanVisibilityService
{
    /**
     * @param  Builder<\App\Models\Loan>  $query
     * @return Builder<\App\Models\Loan>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super-admin') || $user->canPerform('manage-organization') || $user->canPerform('audit-system')) {
            return $query;
        }

        if ($user->canPerform('manage-users') || $user->canPerform('disburse-loans') || $user->canPerform('manage-branches')) {
            return $user->branch_id ? $query->where('branch_id', $user->branch_id) : $query;
        }

        if ($user->canPerform('manage-loans')) {
            return $query->where('officer_id', $user->id);
        }

        return $user->branch_id ? $query->where('branch_id', $user->branch_id) : $query;
    }

    public function canAssignOfficer(User $user): bool
    {
        return $user->hasRole('super-admin')
            || $user->canPerform('manage-users')
            || $user->canPerform('disburse-loans');
    }

    /**
     * @return Builder<User>
     */
    public function assignableOfficers(User $user): Builder
    {
        return User::query()
            ->with('role')
            ->where('status', 'active')
            ->when($user->organization_id, fn (Builder $query, int $organizationId): Builder => $query->where('organization_id', $organizationId))
            ->when($user->branch_id && ! $user->canPerform('manage-organization'), fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id))
            ->whereHas('role', fn (Builder $query): Builder => $query->whereIn('slug', ['branch-manager', 'loan-officer']))
            ->orderBy('name');
    }
}
