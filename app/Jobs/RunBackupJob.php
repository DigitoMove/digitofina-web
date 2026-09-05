<?php

namespace App\Jobs;

use App\Services\OperationsMonitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RunBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public bool $preferPackageBackup = true) {}

    public function handle(): void
    {
        app(OperationsMonitor::class)->run('backup', function (): array {
            if (! $this->preferPackageBackup || ! collect(Artisan::all())->has('backup:run')) {
                throw new RuntimeException('No application backup provider is configured. A manifest is not a backup. Configure and test database and file backups before retrying.');
            }

            if (Artisan::call('backup:run', ['--no-interaction' => true]) !== 0) {
                throw new RuntimeException('The backup command returned a failure exit code. Check backup logs and storage.');
            }

            Log::info('Queued application backup command completed.', ['command' => 'backup:run']);

            return ['command' => 'backup:run', 'restore_verified' => false];
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Queued backup job failed.', [
            'error' => $exception->getMessage(),
        ]);
    }
}
