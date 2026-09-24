<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\ReportExport;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\BusinessCalendarService;
use App\Services\ReportDocumentFormatter;
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
        $export = ReportExport::query()->withoutGlobalScope('tenant')
            ->whereKey($this->reportExport->id)
            ->where('organization_id', $this->reportExport->organization_id)
            ->firstOrFail();

        if ($export->status === 'completed') {
            return;
        }

        $export->update(['status' => 'processing']);

        $rows = $this->rows($export);
        $path = $this->path($export);

        Storage::disk($export->disk ?? 'local')->put($path, $this->serialize($rows, $export));

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
            'loans', 'loan_report' => $this->activeLoanRows($export),
            'arrears', 'arrears_report' => $this->arrearsLoanRows($export),
            'branch_performance' => Branch::query()
                ->where('organization_id', $export->organization_id)
                ->when($export->branch_id, fn ($query, int $branchId) => $query->whereKey($branchId))
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeLoanRows(ReportExport $export): array
    {
        return Loan::query()
            ->with('client')
            ->where('organization_id', $export->organization_id)
            ->when($export->branch_id, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->whereIn('status', Loan::OPERATIONAL_STATUSES)
            ->where(function ($query): void {
                $query->where('principal_balance', '>', 0)
                    ->orWhere('interest_balance', '>', 0)
                    ->orWhere('fees_balance', '>', 0)
                    ->orWhere('penalty_balance', '>', 0);
            })
            ->latest()
            ->limit(5000)
            ->get(['id', 'loan_number', 'client_id', 'approved_amount', 'principal_balance', 'interest_balance', 'fees_balance', 'penalty_balance', 'status', 'updated_at'])
            ->map(fn (Loan $loan): array => [
                'loan_number' => $loan->loan_number,
                'client_id' => $loan->client_id,
                'client' => $loan->client?->full_name,
                'approved_amount' => (float) $loan->approved_amount,
                'principal_balance' => (float) $loan->principal_balance,
                'interest_balance' => (float) $loan->interest_balance,
                'fees_balance' => (float) $loan->fees_balance,
                'penalty_balance' => (float) $loan->penalty_balance,
                'balance_total' => (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance,
                'status' => $loan->status,
                'updated_at' => $loan->updated_at,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function arrearsLoanRows(ReportExport $export): array
    {
        $scheduleScope = fn ($query) => $query
            ->excludingCancelledLoans()
            ->eligibleForArrears(now());
        $calendar = app(BusinessCalendarService::class);

        return Loan::query()
            ->with([
                'client',
                'schedules' => fn ($query) => $scheduleScope($query)->orderBy('due_date')->orderBy('installment_number'),
            ])
            ->where('organization_id', $export->organization_id)
            ->when($export->branch_id, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->whereHas('schedules', $scheduleScope)
            ->withCount(['schedules as due_installments_count' => $scheduleScope])
            ->withSum(['schedules as due_installments_total' => $scheduleScope], 'outstanding_amount')
            ->withMin(['schedules as earliest_due_date' => $scheduleScope], 'due_date')
            ->orderBy('earliest_due_date')
            ->limit(5000)
            ->get()
            ->map(function (Loan $loan) use ($calendar): array {
                $earliestSchedule = $loan->schedules->sortBy('due_date')->first();
                $daysDue = (int) $loan->schedules->max(
                    fn (LoanSchedule $schedule): int => (int) ($schedule->days_overdue ?: ($schedule->due_date ? $calendar->businessDaysBetween($schedule->due_date, today()) : 0))
                );

                return [
                    'loan_id' => $loan->id,
                    'loan_number' => $loan->loan_number,
                    'client_id' => $loan->client_id,
                    'client' => $loan->client?->full_name,
                    'earliest_due_date' => $earliestSchedule?->due_date?->toDateString(),
                    'installments_due' => (int) $loan->due_installments_count,
                    'total_due' => (float) $loan->due_installments_total,
                    'days_due' => $daysDue,
                    'status' => $loan->status,
                    'updated_at' => $loan->updated_at,
                ];
            })
            ->all();
    }

    private function path(ReportExport $export): string
    {
        return "reports/exports/{$export->id}/{$export->report_type}-{$export->id}.{$export->format}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function serialize(array $rows, ReportExport $export): string
    {
        if ($export->format === 'csv') {
            $handle = fopen('php://temp', 'r+');
            $headers = array_keys($rows[0] ?? ['generated_at' => now()->toIso8601String()]);
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn (mixed $value): mixed => is_scalar($value) || $value === null ? $value : json_encode($value), $row));
            }

            rewind($handle);

            return stream_get_contents($handle) ?: '';
        }

        return app(ReportDocumentFormatter::class)->renderReport($export, $rows);
    }
}
