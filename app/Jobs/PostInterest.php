<?php

namespace App\Jobs;

use App\Models\SavingsAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostInterest implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public ?int $organizationId = null,
        public ?int $branchId = null,
    ) {}

    public function handle(): void
    {
        $eligibleAccounts = SavingsAccount::query()
            ->where('status', 'active')
            ->when($this->organizationId, fn ($query, int $organizationId) => $query->where('organization_id', $organizationId))
            ->when($this->branchId, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->whereHas('product', fn ($query) => $query->where('interest_rate', '>', 0))
            ->count();

        Log::info('Queued savings interest posting scan completed.', [
            'organization_id' => $this->organizationId,
            'branch_id' => $this->branchId,
            'eligible_accounts' => $eligibleAccounts,
        ]);
    }

    public function backoff(): array
    {
        return [300, 900];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Queued savings interest posting failed.', [
            'organization_id' => $this->organizationId,
            'branch_id' => $this->branchId,
            'error' => $exception->getMessage(),
        ]);
    }
}
