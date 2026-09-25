<?php

namespace App\Console\Commands;

use App\Jobs\RunBackupJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backups:run')]
#[Description('Run the database backup and email the configured recipients')]
class RunApplicationBackup extends Command
{
    public function handle(): int
    {
        app(RunBackupJob::class)->handle();

        $this->info('Database backup completed and configured recipients were notified when available.');

        return self::SUCCESS;
    }
}
