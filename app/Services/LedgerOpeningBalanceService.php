<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LedgerOpeningBalanceService
{
    public function __construct(
        private BranchGeneralLedgerService $generalLedger,
        private AuditLogger $auditLogger,
    ) {}

    public function post(ChartAccount $chartAccount, float $amount, string $entryType, CarbonInterface $openingDate, User $user, ?string $description = null): JournalEntry
    {
        if (! in_array($entryType, ['debit', 'credit'], true)) {
            throw new InvalidArgumentException('Opening balance must be a debit or credit.');
        }

        CurrencyDenomination::assertValid($amount, 'Opening balance');

        return DB::transaction(function () use ($chartAccount, $amount, $entryType, $openingDate, $user, $description): JournalEntry {
            $chartAccount = ChartAccount::query()->with('branch')->whereKey($chartAccount->id)->lockForUpdate()->firstOrFail();

            if (! $chartAccount->branch) {
                throw new InvalidArgumentException('Opening balances can only be posted to branch ledger accounts.');
            }

            if (! $chartAccount->is_active) {
                throw new InvalidArgumentException('Opening balance cannot be posted to an inactive ledger account.');
            }

            $offsetAccount = $this->generalLedger->openingBalanceEquity($chartAccount->branch);
            $reference = 'OB'.now()->format('ymdHis').Str::upper(Str::random(5));
            $narration = $description ?: 'Opening balance for '.$chartAccount->name;

            $journal = JournalEntry::create([
                'organization_id' => $chartAccount->organization_id,
                'branch_id' => $chartAccount->branch_id,
                'created_by' => $user->id,
                'posted_by' => $user->id,
                'journal_number' => $reference,
                'journal_date' => $openingDate->toDateString(),
                'description' => $narration,
                'status' => 'posted',
                'posted_at' => $openingDate,
                'chart_account_id' => $chartAccount->id,
                'reference' => $reference,
                'account_name' => $chartAccount->name,
                'entry_type' => $entryType,
                'amount' => $amount,
                'memo' => $description,
            ]);

            foreach ([
                [$chartAccount->id, $entryType === 'debit' ? $amount : 0, $entryType === 'credit' ? $amount : 0, $narration],
                [$offsetAccount->id, $entryType === 'credit' ? $amount : 0, $entryType === 'debit' ? $amount : 0, 'Opening balance offset'],
            ] as [$accountId, $debit, $credit, $lineNarration]) {
                JournalLine::create([
                    'journal_entry_id' => $journal->id,
                    'chart_account_id' => $accountId,
                    'debit' => $debit,
                    'credit' => $credit,
                    'narration' => $lineNarration,
                ]);
            }

            $this->auditLogger->record('ledger.opening_balance_posted', $chartAccount, [
                'journal_entry_id' => $journal->id,
                'reference' => $reference,
                'entry_type' => $entryType,
                'amount' => $amount,
                'opening_date' => $openingDate->toDateString(),
            ]);

            return $journal->refresh();
        });
    }
}
