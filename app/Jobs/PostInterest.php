<?php

namespace App\Jobs;

use App\Services\OperationsMonitor;
use App\Services\SavingsInterestService;
use Carbon\CarbonImmutable;
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
        public ?string $asOf = null,
    ) {}

    public function handle(SavingsInterestService $interest): void
    {
        $result = app(OperationsMonitor::class)->run($this->asOf ? 'interest-manual' : 'interest', fn (): array => ['posted_accounts' => $interest->postDueInterest(
            $this->organizationId, $this->branchId, $this->asOf ? CarbonImmutable::parse($this->asOf) : null,
        )], $this->organizationId, $this->branchId);
        $posted = $result['posted_accounts'];

        Log::info('Queued savings interest posting completed.', [
            'organization_id' => $this->organizationId,
            'branch_id' => $this->branchId,
            'as_of' => $this->asOf,
            'posted_accounts' => $posted,
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
