<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\DailyAccountingSummary;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LedgerAccountMergeService
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * @param  array<int, int>  $sourceAccountIds
     * @return array{journal_entries: int, journal_lines: int, daily_summaries: int, source_accounts: int}
     */
    public function merge(ChartAccount $targetAccount, array $sourceAccountIds, User $user, ?string $reason = null): array
    {
        $sourceAccountIds = array_values(array_unique(array_map('intval', $sourceAccountIds)));

        return DB::transaction(function () use ($targetAccount, $sourceAccountIds, $user, $reason): array {
            $target = ChartAccount::query()->whereKey($targetAccount->id)->lockForUpdate()->firstOrFail();
            $sources = ChartAccount::query()->whereKey($sourceAccountIds)->lockForUpdate()->get();

            if ($sources->count() !== count($sourceAccountIds)) {
                throw new InvalidArgumentException('One or more source ledger accounts could not be found.');
            }

            $this->validateTarget($target);

            foreach ($sources as $source) {
                $this->validateSource($source, $target);
            }

            $journalEntryCount = JournalEntry::query()->whereIn('chart_account_id', $sourceAccountIds)->update([
                'chart_account_id' => $target->id,
                'account_name' => $target->name,
            ]);
            $journalLineCount = JournalLine::query()->whereIn('chart_account_id', $sourceAccountIds)->update([
                'chart_account_id' => $target->id,
            ]);
            $dailySummaryCount = $this->mergeDailySummaries($target, $sourceAccountIds);

            ChartAccount::query()->whereKey($sourceAccountIds)->update([
                'is_active' => false,
                'drawer_direction' => 'none',
                'merged_into_id' => $target->id,
                'merged_by' => $user->id,
                'merged_at' => now(),
            ]);

            $result = [
                'journal_entries' => $journalEntryCount,
                'journal_lines' => $journalLineCount,
                'daily_summaries' => $dailySummaryCount,
                'source_accounts' => count($sourceAccountIds),
            ];

            $this->auditLogger->record('ledger_account.merged', $target, [
                'target_account_id' => $target->id,
                'source_account_ids' => $sourceAccountIds,
                'reason' => $reason,
                'moved_records' => $result,
            ], [], $user);

            return $result;
        }, attempts: 3);
    }

    private function validateTarget(ChartAccount $target): void
    {
        if ($target->branch_id !== null) {
            throw new InvalidArgumentException('The destination must be an organization-level ledger account.');
        }

        if (! $target->is_active || $target->merged_into_id) {
            throw new InvalidArgumentException('The destination ledger must be active and must not already be merged.');
        }

        if ($target->is_cash_account || $target->is_system) {
            throw new InvalidArgumentException('Cash and system ledger accounts cannot be merge destinations.');
        }

        if ($target->children()->exists()) {
            throw new InvalidArgumentException('Use a leaf ledger account without child accounts as the merge destination.');
        }
    }

    private function validateSource(ChartAccount $source, ChartAccount $target): void
    {
        if ($source->is($target)) {
            throw new InvalidArgumentException('A ledger account cannot be merged into itself.');
        }

        if ((int) $source->organization_id !== (int) $target->organization_id) {
            throw new InvalidArgumentException('All merged ledger accounts must belong to the same organization.');
        }

        if ($source->type !== $target->type || $source->normal_balance !== $target->normal_balance) {
            throw new InvalidArgumentException('Source and destination ledgers must have the same account type and normal balance.');
        }

        if ($source->merged_into_id) {
            throw new InvalidArgumentException("{$source->code} has already been merged.");
        }

        if ($source->is_cash_account || $source->is_system) {
            throw new InvalidArgumentException('Cash and system ledger accounts cannot be merged.');
        }

        if ($source->children()->exists()) {
            throw new InvalidArgumentException("{$source->code} has child accounts and cannot be merged.");
        }
    }

    /**
     * @param  array<int, int>  $sourceAccountIds
     */
    private function mergeDailySummaries(ChartAccount $target, array $sourceAccountIds): int
    {
        $summaries = DailyAccountingSummary::query()
            ->whereIn('chart_account_id', $sourceAccountIds)
            ->lockForUpdate()
            ->get();

        foreach ($summaries as $summary) {
            $targetSummary = DailyAccountingSummary::query()
                ->where('branch_id', $summary->branch_id)
                ->where('business_date', $summary->business_date)
                ->where('chart_account_id', $target->id)
                ->lockForUpdate()
                ->first();

            if ($targetSummary) {
                $debitTotal = (float) $targetSummary->debit_total + (float) $summary->debit_total;
                $creditTotal = (float) $targetSummary->credit_total + (float) $summary->credit_total;
                $targetSummary->update([
                    'account_code' => $target->code,
                    'account_name' => $target->name,
                    'debit_total' => $debitTotal,
                    'credit_total' => $creditTotal,
                    'net_movement' => $debitTotal - $creditTotal,
                    'line_count' => $targetSummary->line_count + $summary->line_count,
                ]);
                $summary->delete();

                continue;
            }

            $summary->update([
                'chart_account_id' => $target->id,
                'account_code' => $target->code,
                'account_name' => $target->name,
            ]);
        }

        return $summaries->count();
    }
}
