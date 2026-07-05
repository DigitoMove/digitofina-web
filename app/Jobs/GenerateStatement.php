<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Models\SavingsAccount;
use App\Services\ReportDocumentFormatter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateStatement implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public ReportExport $reportExport,
        public int $savingsAccountId,
    ) {}

    public function handle(): void
    {
        $export = ReportExport::query()->findOrFail($this->reportExport->id);
        $account = SavingsAccount::query()
            ->with(['branch', 'client', 'organization'])
            ->findOrFail($this->savingsAccountId);
        $filters = $export->filters ?? [];

        $export->update(['status' => 'processing']);

        $transactions = $account->transactions()
            ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('transaction_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('transaction_date', '<=', $date))
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get(['reference', 'type', 'amount', 'charges', 'balance_before', 'balance_after', 'payment_method', 'status', 'narration', 'transaction_date', 'updated_at'])
            ->map(fn ($transaction): array => $transaction->only(['reference', 'type', 'amount', 'charges', 'balance_before', 'balance_after', 'payment_method', 'status', 'narration', 'transaction_date', 'updated_at']))
            ->all();

        $path = "reports/statements/{$account->id}/statement-{$export->id}.{$export->format}";

        Storage::disk($export->disk ?? 'local')->put(
            $path,
            app(ReportDocumentFormatter::class)->renderStatement($export, $account, $transactions)
        );

        $export->update([
            'path' => $path,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function failed(Throwable $exception): void
    {
        ReportExport::query()->whereKey($this->reportExport->id)->update(['status' => 'failed']);

        Log::error('Statement generation failed.', [
            'report_export_id' => $this->reportExport->id,
            'savings_account_id' => $this->savingsAccountId,
            'error' => $exception->getMessage(),
        ]);
    }
}
