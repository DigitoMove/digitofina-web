<?php

namespace App\Services;

use App\Models\OperationalRun;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

class OperationsMonitor
{
    public const DAILY_TASKS = [
        'penalties' => ['label' => 'Loan penalties', 'time' => '00:00'],
        'interest' => ['label' => 'Savings interest', 'time' => '23:00'],
        'backup' => ['label' => 'Application backup', 'time' => '02:00'],
        'investment-maturities' => ['label' => 'Investment maturities', 'time' => '18:30'],
        'fixed-savings-maturities' => ['label' => 'Fixed savings maturities', 'time' => '18:40'],
    ];

    /** @param Closure(): array<string, mixed> $operation */
    public function run(string $task, Closure $operation, ?int $organizationId = null, ?int $branchId = null): array
    {
        $run = OperationalRun::create(['task' => $task, 'organization_id' => $organizationId, 'branch_id' => $branchId, 'status' => 'running', 'started_at' => now()]);

        try {
            $summary = $operation();
            $run->update(['status' => 'succeeded', 'finished_at' => now(), 'summary' => $summary]);

            return $summary;
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'failure' => class_basename($exception)]);
            Log::error('Operational task failed.', ['run_id' => $run->id, 'task' => $task, 'exception' => $exception]);

            try {
                $this->notifyFailure($run);
            } catch (Throwable $notificationException) {
                Log::error('Operational failure notification could not be delivered.', ['run_id' => $run->id, 'exception' => $notificationException]);
            }

            throw $exception;
        }
    }

    public function heartbeat(): void
    {
        OperationalRun::updateOrCreate(['task' => 'scheduler-heartbeat'], ['status' => 'succeeded', 'started_at' => now(), 'finished_at' => now()]);
    }

    /** @return array<int, array<string, mixed>> */
    public function dailyStatuses(): array
    {
        return collect(self::DAILY_TASKS)->map(function (array $definition, string $task): array {
            $query = OperationalRun::query()->where('task', $task)->whereNull('organization_id')->whereNull('branch_id');
            $latest = (clone $query)->latest('id')->first();
            $success = (clone $query)->where('status', 'succeeded')->latest('finished_at')->first();
            $due = today()->setTimeFromTimeString($definition['time']);
            $expected = now()->lessThan($due->copy()->addHour()) ? $due->copy()->subDay() : $due;
            $next = now()->lessThan($due) ? $due : $due->copy()->addDay();
            $status = match (true) {
                $latest?->status === 'running' => $latest->started_at->lt(now()->subMinutes(20)) ? 'stalled' : 'running',
                $latest?->status === 'failed' => 'failed',
                ! $success => 'unverified',
                $success->finished_at->lt($expected) => 'overdue',
                default => 'succeeded',
            };

            return $definition + ['task' => $task, 'status' => $status, 'last_success' => $success?->finished_at, 'next_due' => $next];
        })->values()->all();
    }

    private function notifyFailure(OperationalRun $run): void
    {
        User::query()->where('status', 'active')->whereHas('role', fn ($query) => $query->where('slug', 'super-admin'))
            ->each(function (User $user) use ($run): void {
                app(InAppNotificationService::class)->notifyUser($user, 'Operational task failed', "The {$run->task} task failed. Review Operations Health and the server logs before retrying.", route('operations-health.index'), 'system', 'danger', ['operational_run_id' => $run->id]);
            });
    }
}
