<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\User;
use App\Services\EndOfDayService;
use App\Services\OperationsMonitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunEndOfDayProcess implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public int $branchId,
        public string $businessDate,
        public ?int $userId = null,
    ) {}

    public function handle(EndOfDayService $endOfDay): void
    {
        $branch = Branch::query()->findOrFail($this->branchId);
        $user = $this->userId ? User::query()->find($this->userId) : null;
        $result = app(OperationsMonitor::class)->run('end-of-day', fn (): array => ['business_date' => $this->businessDate, 'summary_id' => $endOfDay->process($branch, $this->businessDate, $user, true)->id], $branch->organization_id, $branch->id);

        Log::info('End-of-day processing completed.', [
            'branch_id' => $this->branchId,
            'business_date' => $this->businessDate,
            'summary_id' => $result['summary_id'],
        ]);
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('End-of-day processing failed.', [
            'branch_id' => $this->branchId,
            'business_date' => $this->businessDate,
            'error' => $exception->getMessage(),
        ]);
    }
}
