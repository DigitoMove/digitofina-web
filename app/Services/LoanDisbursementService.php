<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\User;

class LoanDisbursementService
{
    public function __construct(private LoanService $loans) {}

    public function disburseToSavings(Loan $loan, User $user, string $paymentMethod = 'transfer_to_savings'): Loan
    {
        return $this->loans->disburseToSavings($loan, $user, $paymentMethod);
    }
}
