<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\ReportExport;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public ReportExport $reportExport) {}

    public function handle(): void
    {
        $export = ReportExport::query()->findOrFail($this->reportExport->id);

        if ($export->status === 'completed') {
            return;
        }

        $export->update(['status' => 'processing']);

        $rows = $this->rows($export);
        $path = $this->path($export);

        Storage::disk($export->disk ?? 'local')->put($path, $this->serialize($rows, $export->format));

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
        $export = ReportExport::query()->find($this->reportExport->id);

        if ($export) {
            $export->update(['status' => 'failed']);
        }

        Log::error('Report export generation failed.', [
            'report_export_id' => $this->reportExport->id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(ReportExport $export): array
    {
        $filters = $export->filters ?? [];

        return match ($export->report_type) {
            'clients', 'client_report' => Client::query()
                ->where('organization_id', $export->organization_id)
                ->when($export->branch_id, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->latest()
                ->limit(5000)
                ->get(['client_number', 'first_name', 'last_name', 'phone', 'status', 'created_at', 'updated_at'])
                ->map(fn (Client $client): array => $client->only(['client_number', 'first_name', 'last_name', 'phone', 'status', 'created_at', 'updated_at']))
                ->all(),
            'savings', 'savings_report' => SavingsAccount::query()
                ->where('organization_id', $export->organization_id)
                ->when($export->branch_id, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
                ->latest()
                ->limit(5000)
                ->get(['account_number', 'client_id', 'current_balance', 'available_balance', 'status', 'updated_at'])
                ->map(fn (SavingsAccount $account): array => $account->only(['account_number', 'client_id', 'current_balance', 'available_balance', 'status', 'updated_at']))
                ->all(),
            'savings_transactions' => SavingsTransaction::query()
                ->where('organization_id', $export->organization_id)
                ->when($export->branch_id, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
                ->latest()
                ->limit(5000)
                ->get(['reference', 'type', 'amount', 'charges', 'payment_method', 'status', 'transaction_date', 'updated_at'])
                ->map(fn (SavingsTransaction $transaction): array => $transaction->only(['reference', 'type', 'amount', 'charges', 'payment_method', 'status', 'transaction_date', 'updated_at']))
                ->all(),
            'loans', 'loan_report' => Loan::query()
                ->where('organization_id', $export->organization_id)
                ->when($export->branch_id, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
                ->latest()
                ->limit(5000)
                ->get(['loan_number', 'client_id', 'approved_amount', 'principal_balance', 'interest_balance', 'penalty_balance', 'status', 'updated_at'])
                ->map(fn (Loan $loan): array => $loan->only(['loan_number', 'client_id', 'approved_amount', 'principal_balance', 'interest_balance', 'penalty_balance', 'status', 'updated_at']))
                ->all(),
            'arrears', 'arrears_report' => LoanSchedule::query()
                ->excludingCancelledLoans()
                ->eligibleForArrears(now())
                ->whereHas('loan', fn ($query) => $query->where('organization_id', $export->organization_id)->when($export->branch_id, fn ($query, int $branchId) => $query->where('branch_id', $branchId)))
                ->latest()
                ->limit(5000)
                ->get(['loan_id', 'installment_number', 'due_date', 'outstanding_amount', 'days_overdue', 'status', 'updated_at'])
                ->map(fn (LoanSchedule $schedule): array => $schedule->only(['loan_id', 'installment_number', 'due_date', 'outstanding_amount', 'days_overdue', 'status', 'updated_at']))
                ->all(),
            'branch_performance' => Branch::query()
                ->where('organization_id', $export->organization_id)
                ->withCount('clients')
                ->withSum('savingsAccounts', 'current_balance')
                ->get()
                ->map(fn (Branch $branch): array => [
                    'code' => $branch->code,
                    'name' => $branch->name,
                    'clients_count' => $branch->clients_count,
                    'savings_balance' => $branch->savings_accounts_sum_current_balance,
                    'updated_at' => $branch->updated_at,
                ])
                ->all(),
            default => [[
                'report_type' => $export->report_type,
                'organization_id' => $export->organization_id,
                'branch_id' => $export->branch_id,
                'generated_at' => now()->toIso8601String(),
            ]],
        };
    }

    private function path(ReportExport $export): string
    {
        return "reports/exports/{$export->id}/{$export->report_type}-{$export->id}.{$export->format}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function serialize(array $rows, string $format): string
    {
        if ($format === 'csv') {
            $handle = fopen('php://temp', 'r+');
            $headers = array_keys($rows[0] ?? ['generated_at' => now()->toIso8601String()]);
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn (mixed $value): mixed => is_scalar($value) || $value === null ? $value : json_encode($value), $row));
            }

            rewind($handle);

            return stream_get_contents($handle) ?: '';
        }

        return json_encode([
            'generated_at' => now()->toIso8601String(),
            'format' => $format,
            'rows' => $rows,
        ], JSON_PRETTY_PRINT);
    }
}
