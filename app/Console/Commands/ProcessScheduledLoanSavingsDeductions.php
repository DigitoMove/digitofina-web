<?php

namespace App\Console\Commands;

use App\Services\OperationsMonitor;
use App\Services\ScheduledSavingsLoanDeductionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('loan-savings-deductions:process {--organization_id=} {--branch_id=} {--at= : Process as if the scheduler is running at this time}')]
#[Description('Process due automatic savings-to-loan deductions')]
class ProcessScheduledLoanSavingsDeductions extends Command
{
    public function handle(ScheduledSavingsLoanDeductionService $deductions, OperationsMonitor $operations): int
    {
        $summary = $operations->run(
            'scheduled-savings-loan-deductions',
            fn (): array => $deductions->processDueSchedules(
                $this->option('at') ? CarbonImmutable::parse($this->option('at')) : null,
                $this->option('organization_id') ? (int) $this->option('organization_id') : null,
                $this->option('branch_id') ? (int) $this->option('branch_id') : null,
            ),
        );

        $this->info(sprintf(
            'Processed %d due schedule(s): %d succeeded, %d skipped, %d failed.',
            $summary['checked'],
            $summary['succeeded'],
            $summary['skipped'],
            $summary['failed'],
        ));

        return self::SUCCESS;
    }
}
