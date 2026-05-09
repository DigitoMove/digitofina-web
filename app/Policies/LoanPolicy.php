<?php

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;

class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerform('manage-loans') || $user->canPerform('view-reports');
    }

    public function create(User $user): bool
    {
        return $user->canPerform('manage-loans');
    }

    public function approve(User $user, Loan $loan): bool
    {
        return ! $user->hasRole('cashier') && ! $user->hasRole('member') && $user->canPerform('approve-loans') && in_array($loan->status, ['submitted', 'under_review', 'recommended'], true);
    }

    public function disburse(User $user, Loan $loan): bool
    {
        return $user->canPerform('manage-loans') && $loan->status === 'approved';
    }
}
