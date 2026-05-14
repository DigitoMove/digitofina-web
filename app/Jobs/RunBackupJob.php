<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RunBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public bool $preferPackageBackup = true) {}

    public function handle(): void
    {
        $commands = collect(Artisan::all());

        if ($this->preferPackageBackup && $commands->has('backup:run')) {
            Artisan::call('backup:run', ['--no-interaction' => true]);

            Log::info('Queued application backup command completed.', [
                'command' => 'backup:run',
            ]);

            return;
        }

        $path = 'backups/manifests/backup-'.now()->format('Ymd-His').'.json';

        Storage::disk('local')->put($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'app' => config('app.name'),
            'database' => config('database.default'),
            'note' => 'Backup package not installed; manifest created for external backup orchestration.',
        ], JSON_PRETTY_PRINT));

        Log::info('Queued backup manifest created.', ['path' => $path]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Queued backup job failed.', [
            'error' => $exception->getMessage(),
        ]);
    }
}
