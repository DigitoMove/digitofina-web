<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\User;
use App\Services\EndOfDayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendRepaymentReminders implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public int $branchId,
        public string $businessDate,
        public ?int $userId = null,
    ) {}

    public function handle(EndOfDayService $endOfDay): void
    {
        $branch = Branch::query()->findOrFail($this->branchId);
        $user = $this->userId ? User::query()->find($this->userId) : null;
        $count = $endOfDay->queueRepaymentReminders($branch, $this->businessDate, $user);

        Log::info('Repayment reminders queued.', [
            'branch_id' => $this->branchId,
            'business_date' => $this->businessDate,
            'count' => $count,
        ]);
    }

    public function backoff(): array
    {
        return [120, 600];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Repayment reminders failed.', [
            'branch_id' => $this->branchId,
            'business_date' => $this->businessDate,
            'error' => $exception->getMessage(),
        ]);
    }
}
