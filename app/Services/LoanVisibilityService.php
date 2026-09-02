<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\User;
use App\Support\ApiSecurity;
use App\Support\RoleCatalog;
use Illuminate\Database\Eloquent\Builder;

class LoanVisibilityService
{
    /**
     * @param  Builder<Loan>  $query
     * @return Builder<Loan>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if ($user->isLoanOfficer()) {
            return $query
                ->where('officer_id', $user->id)
                ->when($user->branch_id, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId));
        }

        if (ApiSecurity::hasGlobalBranchAccess($user)) {
            return $query;
        }

        if ($user->canPerform('manage-users') || $user->canPerform('disburse-loans') || $user->canPerform('manage-branches')) {
            return $user->branch_id ? $query->where('branch_id', $user->branch_id) : $query;
        }

        return $user->branch_id ? $query->where('branch_id', $user->branch_id) : $query;
    }

    /**
     * @param  Builder<Loan>  $query
     * @return Builder<Loan>
     */
    public function scopeViewable(Builder $query, User $user): Builder
    {
        if ($user->isMember()) {
            return $query->where('client_id', $user->client?->id);
        }

        if ($user->isLoanOfficer()) {
            return $query
                ->where('officer_id', $user->id)
                ->when($user->branch_id, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId));
        }

        if (ApiSecurity::hasGlobalBranchAccess($user)) {
            return $query;
        }

        return $user->branch_id ? $query->where('branch_id', $user->branch_id) : $query;
    }

    /**
     * @param  Builder<Loan>  $query
     * @return Builder<Loan>
     */
    public function scopeOperationalOrCreated(Builder $query, User $user): Builder
    {
        if (! ($user->isTellerCashier() || $user->isLoanOfficer() || $user->isLoanRecoveryOfficer())) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->forOperationalListing();

            if (! $user->isTellerCashier()) {
                $query->orWhere('created_by', $user->id);
            }
        });
    }

    public function canAssignOfficer(User $user): bool
    {
        return $user->hasRole('super-admin')
            || $user->canPerform('manage-users')
            || $user->canPerform('disburse-loans');
    }

    public function scopeRepaymentCalendar(Builder $query, User $user): Builder
    {
        if ($user->isLoanOfficer()) {
            return $query
                ->where('officer_id', $user->id)
                ->when($user->branch_id, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId));
        }

        if (ApiSecurity::hasGlobalBranchAccess($user)) {
            return $query;
        }

        return $user->branch_id ? $query->where('branch_id', $user->branch_id) : $query;
    }

    /**
     * @return Builder<User>
     */
    public function assignableOfficers(User $user): Builder
    {
        $organizationId = app(TenantContext::class)->id() ?: $user->organization_id;

        return User::query()
            ->with('role')
            ->where('status', 'active')
            ->when($organizationId, fn (Builder $query, int $organizationId): Builder => $query->where('organization_id', $organizationId))
            ->when($user->branch_id && ! ApiSecurity::hasGlobalBranchAccess($user), function (Builder $query) use ($user): void {
                $query->where(function (Builder $query) use ($user): void {
                    $query->where('branch_id', $user->branch_id)
                        ->orWhereHas('role', fn (Builder $query): Builder => $query->where('slug', 'organization-admin'));
                });
            })
            ->whereHas('role', fn (Builder $query): Builder => $query->whereIn('slug', RoleCatalog::LOAN_ASSIGNABLE_SLUGS))
            ->orderBy('name');
    }
}
