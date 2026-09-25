<?php

namespace App\Jobs;

use App\Services\OperationsMonitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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

            if (Artisan::call('backup:run', ['--only-db' => true, '--no-interaction' => true]) !== 0) {
                throw new RuntimeException('The backup command returned a failure exit code. Check backup logs and storage.');
            }

            $recipients = array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_MAIL_TO', '')))));
            $disk = Storage::disk('local');
            $backupPath = collect($disk->allFiles())
                ->filter(fn (string $path): bool => str_ends_with(strtolower($path), '.zip'))
                ->sortByDesc(fn (string $path): int => $disk->lastModified($path))
                ->first();

            if ($recipients !== [] && $backupPath !== null) {
                Mail::raw('The latest DigitoFina database backup is attached. Keep it in protected storage and test restoration regularly.', function ($message) use ($recipients, $disk, $backupPath): void {
                    $message->to($recipients)
                        ->subject('DigitoFina database backup - '.now()->format('Y-m-d'))
                        ->attach($disk->path($backupPath), ['as' => basename($backupPath), 'mime' => 'application/zip']);
                });
            }

            Log::info('Queued database backup command completed.', ['command' => 'backup:run --only-db', 'emailed' => $recipients !== [] && $backupPath !== null]);

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
