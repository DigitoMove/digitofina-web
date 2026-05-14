<?php

namespace App\Jobs;

use App\Models\Loan;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostBulkPenalties implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public ?int $loanId = null,
        public ?string $asOf = null,
    ) {}

    public function handle(LoanService $loanService): void
    {
        $loan = $this->loanId ? Loan::query()->findOrFail($this->loanId) : null;
        $asOf = $this->asOf ? CarbonImmutable::parse($this->asOf) : null;
        $count = $loanService->applyOverduePenalties($loan, $asOf);

        Log::info('Queued overdue penalty posting completed.', [
            'loan_id' => $this->loanId,
            'as_of' => $this->asOf,
            'installments_checked' => $count,
        ]);
    }

    public function backoff(): array
    {
        return [300, 900];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Queued overdue penalty posting failed.', [
            'loan_id' => $this->loanId,
            'as_of' => $this->asOf,
            'error' => $exception->getMessage(),
        ]);
    }
}
