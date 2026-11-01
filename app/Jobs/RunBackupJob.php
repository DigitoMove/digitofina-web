<?php

namespace App\Jobs;

use App\Models\SystemSetting;
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

    public function __construct(public bool $preferPackageBackup = true, public bool $emailBackup = true) {}

    public function handle(): void
    {
        app(OperationsMonitor::class)->run('backup', function (): array {
            if (! $this->preferPackageBackup || ! collect(Artisan::all())->has('backup:run')) {
                throw new RuntimeException('No application backup provider is configured. A manifest is not a backup. Configure and test database and file backups before retrying.');
            }

            $recipientSetting = SystemSetting::forPlatform()->where('key', 'backup_mail_to')->value('value');
            $recipients = array_values(array_filter(array_map('trim', explode(',', (string) ($recipientSetting ?: env('BACKUP_MAIL_TO', ''))))));
            $shouldEmail = $this->emailBackup && $recipients !== [];

            if ($shouldEmail && (! filled(config('backup.backup.password')) || config('backup.backup.encryption') === 'none')) {
                throw new RuntimeException('Backup email requires an encrypted archive. Configure BACKUP_ARCHIVE_PASSWORD first.');
            }

            if ($shouldEmail && (in_array(config('mail.default'), ['log', 'array'], true) || config('mail.from.address') === 'hello@example.com')) {
                throw new RuntimeException('Backup email requires a delivery mailer and a real sender address.');
            }

            // The configured backup source is database-only, so the provider
            // command does not need a separate --only-db flag.
            $backupStartedAt = now()->timestamp;
            if (Artisan::call('backup:run', ['--no-interaction' => true, '--disable-notifications' => true]) !== 0) {
                throw new RuntimeException('The backup command returned a failure exit code. Check backup logs and storage.');
            }

            $disk = Storage::disk('local');
            $backupPath = collect($disk->allFiles(config('backup.backup.name')))
                ->filter(fn (string $path): bool => str_ends_with(strtolower($path), '.zip') && $disk->lastModified($path) >= $backupStartedAt)
                ->sortByDesc(fn (string $path): int => $disk->lastModified($path))
                ->first();

            if ($shouldEmail && $backupPath === null) {
                throw new RuntimeException('The new backup archive was not found, so no backup email was sent.');
            }

            if ($shouldEmail) {
                Mail::raw('The latest DigitoFina database backup is attached. Keep it in protected storage and test restoration regularly.', function ($message) use ($recipients, $disk, $backupPath): void {
                    $message->to($recipients)
                        ->subject('DigitoFina database backup - '.now()->format('Y-m-d'))
                        ->attach($disk->path($backupPath), ['as' => basename($backupPath), 'mime' => 'application/zip']);
                });
            }

            Log::info('Queued database backup command completed.', ['command' => 'backup:run', 'emailed' => $shouldEmail]);

            return ['command' => 'backup:run', 'emailed' => $shouldEmail, 'restore_verified' => false];
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Queued backup job failed.', [
            'error' => $exception->getMessage(),
        ]);
    }
}
