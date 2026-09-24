<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Models\SavingsAccount;
use App\Services\ClientStatementService;
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
        $export = ReportExport::query()->withoutGlobalScope('tenant')
            ->whereKey($this->reportExport->id)
            ->where('organization_id', $this->reportExport->organization_id)
            ->firstOrFail();
        $account = SavingsAccount::query()
            ->withoutGlobalScope('tenant')
            ->with(['branch', 'client', 'organization'])
            ->whereKey($this->savingsAccountId)
            ->where('organization_id', $export->organization_id)
            ->when($export->branch_id, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->firstOrFail();
        $filters = $export->filters ?? [];
        abort_unless($account->organization_id === $export->organization_id && (! $export->branch_id || $account->branch_id === $export->branch_id), 403);

        $export->update(['status' => 'processing']);

        $document = app(ClientStatementService::class)->forAccount($account->client, $account, 'savings:'.$account->id, $filters);
        $document['title'] = 'Savings Account Transactions';
        $document['heading'] = 'Savings Account Transactions';
        $document['rows'][0]['description'] = 'Opening Balance';

        $path = "reports/statements/{$account->id}/statement-{$export->id}.{$export->format}";

        Storage::disk($export->disk ?? 'local')->put(
            $path,
            app(ReportDocumentFormatter::class)->renderClientStatement($document, $export->format)
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
