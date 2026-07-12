<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LedgerChargeService
{
    public const MODE_DEDUCT_FROM_AMOUNT = 'deduct_from_amount';

    public const MODE_ADD_TO_AMOUNT = 'add_to_amount';

    public function __construct(
        private BranchGeneralLedgerService $generalLedger,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function modes(): array
    {
        return [
            self::MODE_DEDUCT_FROM_AMOUNT => 'Deduct charges from the transaction amount',
            self::MODE_ADD_TO_AMOUNT => 'Add charges on top of the transaction amount',
        ];
    }

    public static function normalizeMode(?string $mode): string
    {
        return array_key_exists((string) $mode, self::modes())
            ? (string) $mode
            : self::MODE_DEDUCT_FROM_AMOUNT;
    }

    public static function assertMode(?string $mode): void
    {
        if (! array_key_exists((string) $mode, self::modes())) {
            throw new InvalidArgumentException('Select a valid charge handling option.');
        }
    }

    public function postStandaloneCharge(ChartAccount $chartAccount, float $amount, CarbonInterface $chargedAt, User $user, Branch $branch, ?string $description = null): JournalEntry
    {
        CurrencyDenomination::assertValid($amount, 'Ledger charges');

        return DB::transaction(function () use ($chartAccount, $amount, $chargedAt, $user, $branch, $description): JournalEntry {
            $chartAccount = ChartAccount::query()->whereKey($chartAccount->id)->lockForUpdate()->firstOrFail();
            $branch = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();

            if ((int) $chartAccount->organization_id !== (int) $branch->organization_id) {
                throw new InvalidArgumentException('The charge branch must belong to the same organization as the ledger.');
            }

            if ($chartAccount->branch_id !== null && (int) $chartAccount->branch_id !== (int) $branch->id) {
                throw new InvalidArgumentException('Charges for a branch ledger must be posted through its own branch.');
            }

            if (! $chartAccount->is_active || $chartAccount->merged_into_id) {
                throw new InvalidArgumentException('Charges cannot be posted to an inactive or merged source ledger.');
            }

            $chargeAccount = $this->generalLedger->transactionChargesExpense($branch);
            $selectedEntryType = $chartAccount->normal_balance === 'debit' ? 'credit' : 'debit';
            $reference = 'LC'.now()->format('ymdHis').Str::upper(Str::random(5));
            $narration = $description ?: 'Ledger charges for '.$chartAccount->name;

            $journal = JournalEntry::create([
                'organization_id' => $chartAccount->organization_id,
                'branch_id' => $branch->id,
                'created_by' => $user->id,
                'posted_by' => $user->id,
                'journal_number' => $reference,
                'journal_date' => $chargedAt->toDateString(),
                'description' => $narration,
                'status' => 'posted',
                'posted_at' => $chargedAt,
                'chart_account_id' => $chartAccount->id,
                'reference' => $reference,
                'account_name' => $chartAccount->name,
                'entry_type' => $selectedEntryType,
                'amount' => $amount,
                'memo' => $description,
            ]);

            foreach ($this->standaloneChargeLines($chartAccount, $chargeAccount, $amount, $narration) as [$accountId, $debit, $credit, $lineNarration]) {
                JournalLine::create([
                    'journal_entry_id' => $journal->id,
                    'chart_account_id' => $accountId,
                    'debit' => $debit,
                    'credit' => $credit,
                    'narration' => $lineNarration,
                ]);
            }

            $this->auditLogger->record('ledger.charge_posted', $chartAccount, [
                'journal_entry_id' => $journal->id,
                'reference' => $reference,
                'amount' => $amount,
                'branch_id' => $branch->id,
            ]);

            return $journal->refresh();
        });
    }

    /**
     * @return array<int, array{0:int, 1:float, 2:float, 3:string}>
     */
    private function standaloneChargeLines(ChartAccount $chartAccount, ChartAccount $chargeAccount, float $amount, string $narration): array
    {
        if ($chartAccount->normal_balance === 'debit') {
            return [
                [$chargeAccount->id, $amount, 0, 'Ledger charges'],
                [$chartAccount->id, 0, $amount, $narration],
            ];
        }

        return [
            [$chartAccount->id, $amount, 0, $narration],
            [$chargeAccount->id, 0, $amount, 'Ledger charges'],
        ];
    }
}
