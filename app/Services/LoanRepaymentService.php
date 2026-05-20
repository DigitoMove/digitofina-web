<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\User;
use Carbon\CarbonInterface;

class LoanRepaymentService
{
    public function __construct(private LoanService $loans) {}

    public function repay(Loan $loan, float $amount, User $user, ?int $transactionId = null, string $paymentMethod = 'cash', ?string $reference = null, ?CarbonInterface $paidAt = null, string $counterAccountCode = '1000', string $journalMemo = 'Loan repayment allocation'): LoanRepayment
    {
        return $this->loans->repay($loan, $amount, $user, $transactionId, $paymentMethod, $reference, $paidAt, $counterAccountCode, $journalMemo);
    }
}
