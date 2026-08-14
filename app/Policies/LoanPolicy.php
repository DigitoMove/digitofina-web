<?php

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;

class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isMember();
    }

    public function view(User $user, Loan $loan): bool
    {
        if ($user->isMember()) {
            return (int) $loan->client_id === (int) $user->client?->id;
        }

        if ($user->isLoanOfficer()) {
            return (int) $loan->officer_id === (int) $user->id
                && $user->canAccessBranch($loan->branch_id, $loan->organization_id);
        }

        return $user->canAccessBranch($loan->branch_id, $loan->organization_id);
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
