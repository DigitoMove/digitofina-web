<?php

namespace App\Console\Commands;

use App\Models\OperationalRun;
use App\Services\OperationsMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

#[Signature('operations:check {--json : Output machine-readable checks for an external monitor}')]
#[Description('Read scheduler, task, backup-provider and failed-queue health without running financial jobs')]
class CheckOperationsHealth extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OperationsMonitor $monitor): int
    {
        $heartbeat = OperationalRun::query()->where('task', 'scheduler-heartbeat')->latest('finished_at')->first();
        $checks = [
            'scheduler' => $heartbeat?->finished_at?->gte(now()->subMinutes(5)) ? 'succeeded' : 'stale-or-missing',
            'backup_provider' => array_key_exists('backup:run', Artisan::all()) ? 'succeeded' : 'not-configured',
        ];

        foreach ($monitor->dailyStatuses() as $task) {
            $checks[$task['task']] = $task['status'];
        }

        if (in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            $failed = DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'))->count();
            $checks['failed_queue_jobs'] = $failed === 0 ? 'succeeded' : 'failed-jobs-present';
        } else {
            $checks['failed_queue_jobs'] = 'unverified';
        }

        $healthy = collect($checks)->every(fn (string $status): bool => in_array($status, ['succeeded', 'running'], true));

        if ($this->option('json')) {
            $this->line(json_encode(['healthy' => $healthy, 'checked_at' => now()->toIso8601String(), 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Check', 'Status'], collect($checks)->map(fn (string $status, string $name): array => [$name, $status])->values()->all());
            $this->line('Read-only check. Backup command success does not verify recoverability; test restores separately.');
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
