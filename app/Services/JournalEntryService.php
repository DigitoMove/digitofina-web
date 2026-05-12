<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class JournalEntryService
{
    /**
     * @param  array<int, array{account_code:string, debit?:float, credit?:float, narration?:string}>  $lines
     */
    public function post(array $lines, string $description, ?User $user = null, ?int $branchId = null): JournalEntry
    {
        $debits = round(collect($lines)->sum(fn (array $line): float => (float) ($line['debit'] ?? 0)), 2);
        $credits = round(collect($lines)->sum(fn (array $line): float => (float) ($line['credit'] ?? 0)), 2);

        if ($debits <= 0 || $debits !== $credits) {
            throw new InvalidArgumentException('Journal entry debits and credits must balance.');
        }

        return DB::transaction(function () use ($lines, $description, $user, $branchId): JournalEntry {
            $journal = JournalEntry::create([
                'branch_id' => $branchId,
                'created_by' => $user?->id,
                'posted_by' => $user?->id,
                'journal_number' => 'JE'.now()->format('ymdHis').Str::upper(Str::random(4)),
                'journal_date' => now()->toDateString(),
                'description' => $description,
                'status' => 'posted',
                'posted_at' => now(),
                'reference' => 'AUTO',
                'account_name' => 'Balanced Journal',
                'entry_type' => 'journal',
                'amount' => collect($lines)->sum(fn (array $line): float => (float) ($line['debit'] ?? 0)),
            ]);

            foreach ($lines as $line) {
                $chartAccount = ChartAccount::query()->where('code', $line['account_code'])->firstOrFail();

                JournalLine::create([
                    'journal_entry_id' => $journal->id,
                    'chart_account_id' => $chartAccount->id,
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'narration' => $line['narration'] ?? null,
                ]);
            }

            return $journal->refresh();
        });
    }
}
