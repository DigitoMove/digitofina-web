<?php

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;

class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canCreateLoanApplications()
            || $user->canPerform('view-reports');
    }

    public function view(User $user, Loan $loan): bool
    {
        if ((int) $loan->created_by === (int) $user->id
            && $user->canAccessBranch($loan->branch_id, $loan->organization_id)) {
            return true;
        }

        if ($user->isLoanOfficer()) {
            return in_array($loan->status, Loan::OPERATIONAL_STATUSES, true)
                && (int) $loan->officer_id === (int) $user->id
                && (int) $loan->branch_id === (int) $user->branch_id;
        }

        if ($user->isLoanRecoveryOfficer()) {
            return $user->canPerform('view-arrears')
                && $user->canAccessBranch($loan->branch_id, $loan->organization_id)
                && $loan->schedules()->eligibleForArrears(today())->exists();
        }

        if ($user->isTellerCashier()) {
            return in_array($loan->status, Loan::OPERATIONAL_STATUSES, true)
                && $user->canAccessBranch($loan->branch_id, $loan->organization_id);
        }

        return $user->canPerform('manage-loans')
            || $user->canPerform('view-reports');
    }

    public function create(User $user): bool
    {
        return $user->canCreateLoanApplications();
    }

    public function approve(User $user, Loan $loan): bool
    {
        return ! $user->isTellerCashier() && ! $user->isMember() && $user->canPerform('approve-loans') && in_array($loan->status, ['submitted', 'under_review', 'recommended', 'pending_guarantor_approval', 'pending_collateral_verification'], true);
    }

    public function disburse(User $user, Loan $loan): bool
    {
        return $user->canPerform('disburse-loans') && $loan->status === 'approved';
    }

    public function repay(User $user, Loan $loan): bool
    {
        return $user->canPerform('post-repayments') && in_array($loan->status, ['active', 'in_arrears'], true);
    }
}
