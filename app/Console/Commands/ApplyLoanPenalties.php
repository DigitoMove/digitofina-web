<?php

namespace App\Console\Commands;

use App\Jobs\PostBulkPenalties;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('loans:apply-penalties')]
#[Description('Mark overdue loan schedules and apply configured penalties')]
class ApplyLoanPenalties extends Command
{
    public function handle(): int
    {
        PostBulkPenalties::dispatch();
        $this->info('Queued overdue loan penalty posting.');

        return self::SUCCESS;
    }
}
