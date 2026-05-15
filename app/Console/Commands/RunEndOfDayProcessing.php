<?php

namespace App\Console\Commands;

use App\Jobs\RunEndOfDayProcess;
use App\Models\Branch;
use Illuminate\Console\Command;

class RunEndOfDayProcessing extends Command
{
    protected $signature = 'end-of-day:process {--branch_id=} {--date=} {--sync : Run immediately instead of queueing}';

    protected $description = 'Run end-of-day summaries, overdue checks, penalties, and repayment reminders';

    public function handle(): int
    {
        $date = $this->option('date') ?: today()->toDateString();
        $branches = Branch::query()
            ->when($this->option('branch_id'), fn ($query, string $branchId) => $query->whereKey($branchId))
            ->get();

        if ($branches->isEmpty()) {
            $this->warn('No branches found for end-of-day processing.');

            return self::SUCCESS;
        }

        foreach ($branches as $branch) {
            $job = new RunEndOfDayProcess($branch->id, $date);

            if ($this->option('sync')) {
                dispatch_sync($job);
                $this->info("Processed {$branch->name} for {$date}.");

                continue;
            }

            dispatch($job);
            $this->info("Queued {$branch->name} for {$date}.");
        }

        return self::SUCCESS;
    }
}
