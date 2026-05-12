<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\User;

class LoanRepaymentService
{
    public function __construct(private LoanService $loans) {}

    public function repay(Loan $loan, float $amount, User $user, ?int $transactionId = null): LoanRepayment
    {
        return $this->loans->repay($loan, $amount, $user, $transactionId);
    }
}
