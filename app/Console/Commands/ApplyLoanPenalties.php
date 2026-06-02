<?php

namespace App\Console\Commands;

use App\Services\LoanService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('loans:apply-penalties')]
#[Description('Mark overdue loan schedules and apply configured penalties')]
class ApplyLoanPenalties extends Command
{
    public function handle(LoanService $loanService): int
    {
        $summary = $loanService->applyOverduePenaltiesWithSummary();
        $this->info(sprintf(
            'Completed overdue loan penalty posting. Checked %d overdue installment(s), applied UGX %s to %d schedule(s).',
            $summary['checked'],
            number_format($summary['amount'], 2),
            $summary['applied_schedules'],
        ));

        return self::SUCCESS;
    }
}
