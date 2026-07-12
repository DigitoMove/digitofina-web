<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LedgerReversalRepairService
{
    /**
     * @return array{scanned:int, affected_transactions:int, journals_created:int, journals_reused:int, journals_restored:int, lines_created:int, dry_run:bool, rows:array<int, array<string, mixed>>}
     */
    public function repairMissingLines(bool $dryRun = true, ?int $transactionId = null, ?int $limit = null, ?User $actor = null): array
    {
        $summary = [
            'scanned' => 0,
            'affected_transactions' => 0,
            'journals_created' => 0,
            'journals_reused' => 0,
            'journals_restored' => 0,
            'lines_created' => 0,
            'dry_run' => $dryRun,
            'rows' => [],
        ];

        $query = Transaction::query()
            ->with(['reversal', 'journalEntries.lines'])
            ->whereNotNull('reversed_by')
            ->whereNotNull('reversed_at')
            ->when($transactionId, fn (Builder $query, int $transactionId): Builder => $query->whereKey($transactionId))
            ->whereHas('journalEntries.lines')
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $query->get()->each(function (Transaction $original) use (&$summary, $dryRun, $actor): void {
            $summary['scanned']++;
            $reversal = $original->reversal;

            if (! $reversal) {
                return;
            }

            $transactionAffected = false;

            foreach ($original->journalEntries as $journal) {
                if ($journal->lines->isEmpty()) {
                    continue;
                }

                $result = $this->repairJournal($journal, $reversal, $actor, $dryRun);

                if ($result['lines_created'] > 0 || $result['journal_created'] || $result['journal_restored']) {
                    $transactionAffected = true;
                    $summary['journals_created'] += $result['journal_created'] ? 1 : 0;
                    $summary['journals_reused'] += $result['journal_reused'] ? 1 : 0;
                    $summary['journals_restored'] += $result['journal_restored'] ? 1 : 0;
                    $summary['lines_created'] += $result['lines_created'];
                    $summary['rows'][] = [
                        'transaction_id' => $original->id,
                        'transaction_reference' => $original->reference,
                        'reversal_id' => $reversal->id,
                        'reversal_reference' => $reversal->reference,
                        'journal_id' => $journal->id,
                        'journal_reference' => $journal->reference ?: $journal->journal_number,
                        'lines_created' => $result['lines_created'],
                        'journal_created' => $result['journal_created'],
                        'journal_restored' => $result['journal_restored'],
                    ];
                }
            }

            if ($transactionAffected) {
                $summary['affected_transactions']++;
            }
        });

        return $summary;
    }

    /**
     * @return array{journal_created:bool, journal_reused:bool, journal_restored:bool, lines_created:int}
     */
    public function repairJournal(JournalEntry $journal, Transaction $reversal, ?User $actor = null, bool $dryRun = false): array
    {
        return DB::transaction(function () use ($journal, $reversal, $actor, $dryRun): array {
            $journal = JournalEntry::query()->with('lines')->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            $reversalJournal = $this->reversalJournal($journal, $reversal);
            $journalCreated = false;
            $journalRestored = false;

            if (! $reversalJournal) {
                $journalCreated = true;

                if (! $dryRun) {
                    $reversalJournal = JournalEntry::create($this->reversalJournalPayload($journal, $reversal, $actor));
                }
            }

            if ($journal->status !== 'posted') {
                $journalRestored = true;

                if (! $dryRun) {
                    $journal->update(['status' => 'posted']);
                }
            }

            $linesCreated = $this->missingLineCount($journal, $reversalJournal);

            if (! $dryRun && $reversalJournal && $linesCreated > 0) {
                $this->createMissingLines($journal, $reversalJournal);
            }

            if (! $dryRun && $reversalJournal && Schema::hasColumn('journal_entries', 'reversed_entry_id') && (int) $journal->reversed_entry_id !== (int) $reversalJournal->id) {
                $journal->update(['reversed_entry_id' => $reversalJournal->id]);
            }

            return [
                'journal_created' => $journalCreated,
                'journal_reused' => ! $journalCreated && $reversalJournal !== null,
                'journal_restored' => $journalRestored,
                'lines_created' => $linesCreated,
            ];
        });
    }

    private function reversalJournal(JournalEntry $journal, Transaction $reversal): ?JournalEntry
    {
        if (Schema::hasColumn('journal_entries', 'reversed_entry_id')) {
            $linked = JournalEntry::query()
                ->with('lines')
                ->where('transaction_id', $reversal->id)
                ->where('reversed_entry_id', $journal->id)
                ->first();

            if ($linked) {
                return $linked;
            }
        }

        return JournalEntry::query()
            ->with('lines')
            ->where('transaction_id', $reversal->id)
            ->where('reference', $reversal->reference)
            ->where('description', 'like', 'Reversal of%')
            ->where('chart_account_id', $journal->chart_account_id)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function reversalJournalPayload(JournalEntry $journal, Transaction $reversal, ?User $actor): array
    {
        $payload = [
            'organization_id' => $journal->organization_id,
            'branch_id' => $journal->branch_id ?: $reversal->branch_id,
            'created_by' => $actor?->id ?? $reversal->user_id,
            'posted_by' => $actor?->id ?? $reversal->user_id,
            'journal_number' => 'RJ'.now()->format('ymdHis').Str::upper(Str::random(4)),
            'journal_date' => $reversal->posted_at?->toDateString() ?? today()->toDateString(),
            'description' => 'Reversal of '.$journal->reference,
            'status' => 'posted',
            'posted_at' => $reversal->posted_at ?? now(),
            'chart_account_id' => $journal->chart_account_id,
            'transaction_id' => $reversal->id,
            'reference' => $reversal->reference,
            'account_name' => $journal->account_name,
            'entry_type' => $journal->entry_type === 'debit' ? 'credit' : 'debit',
            'amount' => $journal->amount,
            'memo' => 'Transaction reversal',
        ];

        if (Schema::hasColumn('journal_entries', 'reversed_entry_id')) {
            $payload['reversed_entry_id'] = $journal->id;
        }

        return $payload;
    }

    private function missingLineCount(JournalEntry $journal, ?JournalEntry $reversalJournal): int
    {
        return $this->missingLineRows($journal, $reversalJournal)->count();
    }

    private function createMissingLines(JournalEntry $journal, JournalEntry $reversalJournal): void
    {
        $this->missingLineRows($journal, $reversalJournal)
            ->each(function (array $row) use ($reversalJournal, $journal): void {
                JournalLine::create([
                    'journal_entry_id' => $reversalJournal->id,
                    'chart_account_id' => $row['chart_account_id'],
                    'debit' => $row['debit'],
                    'credit' => $row['credit'],
                    'narration' => 'Reversal of '.($journal->reference ?: $journal->journal_number),
                ]);
            });
    }

    private function missingLineRows(JournalEntry $journal, ?JournalEntry $reversalJournal): Collection
    {
        $needed = $journal->lines
            ->groupBy('chart_account_id')
            ->map(fn ($lines, int $accountId): array => [
                'chart_account_id' => $accountId,
                'debit' => (float) $lines->sum('credit'),
                'credit' => (float) $lines->sum('debit'),
            ]);

        $existing = $reversalJournal
            ? $reversalJournal->lines
                ->groupBy('chart_account_id')
                ->map(fn ($lines): array => [
                    'debit' => (float) $lines->sum('debit'),
                    'credit' => (float) $lines->sum('credit'),
                ])
            : collect();

        return $needed
            ->map(function (array $row, int $accountId) use ($existing): ?array {
                $existingRow = $existing->get($accountId, ['debit' => 0.0, 'credit' => 0.0]);
                $debit = round($row['debit'] - (float) $existingRow['debit'], 2);
                $credit = round($row['credit'] - (float) $existingRow['credit'], 2);

                if ($debit <= 0 && $credit <= 0) {
                    return null;
                }

                return [
                    'chart_account_id' => $accountId,
                    'debit' => max(0, $debit),
                    'credit' => max(0, $credit),
                ];
            })
            ->filter()
            ->values();
    }
}
