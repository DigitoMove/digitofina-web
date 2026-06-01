<?php

namespace App\Console\Commands;

use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('loans:repair-monthly-schedules {--as-of= : Date used to decide whether repaired schedules are overdue}')]
#[Description('Realign unpaid monthly loan schedules to 26 business days from application date')]
class RepairMonthlyLoanSchedules extends Command
{
    public function handle(LoanService $loans): int
    {
        $asOf = $this->option('as-of')
            ? CarbonImmutable::parse((string) $this->option('as-of'))
            : CarbonImmutable::today();
        $totals = $loans->repairMonthlyScheduleDates($asOf);

        $this->info(sprintf(
            'Repaired %d loan(s), updated %d schedule(s), and cleared UGX %s in premature pending penalties.',
            $totals['loans'],
            $totals['schedules'],
            number_format($totals['penalties_cleared'], 2),
        ));

        return self::SUCCESS;
    }
}
