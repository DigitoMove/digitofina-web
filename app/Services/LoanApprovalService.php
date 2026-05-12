<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\User;

class LoanApprovalService
{
    public function __construct(private LoanService $loans) {}

    public function approve(Loan $loan, User $approver, float $amount, ?string $comments = null): Loan
    {
        return $this->loans->approve($loan, $approver, $amount, $comments);
    }
}
