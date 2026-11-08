<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\EquityContributor;
use App\Models\EquityStakeEntry;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EquityStakeService
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function register(ChartAccount $account, User $actor, ?int $userId, ?string $name): EquityContributor
    {
        $this->assertEquityAccount($account);
        $linkedUser = $userId ? User::query()->whereKey($userId)->firstOrFail() : null;

        if ($linkedUser && (int) $linkedUser->organization_id !== (int) $account->organization_id) {
            throw new InvalidArgumentException('The selected user belongs to another organization.');
        }

        $contributorName = $linkedUser?->name ?? trim((string) $name);

        if ($contributorName === '') {
            throw new InvalidArgumentException('Enter a contributor name or select a user.');
        }

        $contributor = EquityContributor::query()->firstOrCreate(
            $linkedUser
                ? ['chart_account_id' => $account->id, 'user_id' => $linkedUser->id]
                : ['chart_account_id' => $account->id, 'user_id' => null, 'name' => $contributorName],
            ['organization_id' => $account->organization_id, 'name' => $contributorName],
        );

        if ($contributor->wasRecentlyCreated) {
            $this->auditLogger->record('equity.contributor_registered', $contributor, [
                'chart_account_id' => $account->id,
                'user_id' => $linkedUser?->id,
                'name' => $contributorName,
            ], actor: $actor);
        }

        return $contributor;
    }

    public function allocate(ChartAccount $account, JournalEntry $journalEntry, EquityContributor $contributor, float $amount, User $actor): EquityStakeEntry
    {
        $this->assertEquityAccount($account);
        CurrencyDenomination::assertValid($amount, 'Stake amount');

        return DB::transaction(function () use ($account, $journalEntry, $contributor, $amount, $actor): EquityStakeEntry {
            $journalEntry = JournalEntry::query()->with('transaction')->whereKey($journalEntry->id)->lockForUpdate()->firstOrFail();
            $contributor = EquityContributor::query()->whereKey($contributor->id)->lockForUpdate()->firstOrFail();

            if ((int) $journalEntry->organization_id !== (int) $account->organization_id
                || (int) $contributor->organization_id !== (int) $account->organization_id
                || (int) $contributor->chart_account_id !== (int) $account->id) {
                throw new InvalidArgumentException('The contributor and posting must belong to this equity account and organization.');
            }

            if ($journalEntry->status !== 'posted'
                || ($journalEntry->transaction && ($journalEntry->transaction->status !== 'posted' || $journalEntry->transaction->reversal_of_id))) {
                throw new InvalidArgumentException('Only active, unreversed ledger postings can be attributed.');
            }

            [$entryType, $postingAmount] = $this->postingAmount($account, $journalEntry);
            $allocated = (float) EquityStakeEntry::query()->where('journal_entry_id', $journalEntry->id)->sum('amount');

            if (round($allocated + $amount, 2) > round($postingAmount, 2)) {
                throw new InvalidArgumentException('Stake allocations cannot exceed the posted equity amount.');
            }

            if ($entryType === 'debit' && $this->balanceFor($account, $contributor) + 0.01 < $amount) {
                throw new InvalidArgumentException('This payout exceeds the contributor’s recorded stake.');
            }

            $stakeEntry = EquityStakeEntry::query()->create([
                'organization_id' => $account->organization_id,
                'chart_account_id' => $account->id,
                'equity_contributor_id' => $contributor->id,
                'journal_entry_id' => $journalEntry->id,
                'created_by' => $actor->id,
                'entry_type' => $entryType,
                'amount' => $amount,
            ]);

            $this->auditLogger->record('equity.stake_attributed', $stakeEntry, [
                'journal_entry_id' => $journalEntry->id,
                'contributor_id' => $contributor->id,
                'entry_type' => $entryType,
                'amount' => $amount,
            ], actor: $actor);

            return $stakeEntry;
        });
    }

    /** @return Collection<int, EquityContributor> */
    public function contributorsWithStakes(ChartAccount $account): Collection
    {
        $balances = $this->balances($account);

        return $account->equityContributors()->with('user')->orderBy('name')->get()
            ->each(fn (EquityContributor $contributor): EquityContributor => $contributor->setAttribute('stake_balance', (float) ($balances[$contributor->id] ?? 0)));
    }

    public function assertReceiptCanBeReversed(Transaction $transaction): void
    {
        $entries = EquityStakeEntry::query()
            ->with(['chartAccount', 'journalEntry'])
            ->whereHas('journalEntry', fn ($query) => $query->where('transaction_id', $transaction->id))
            ->where('entry_type', 'credit')
            ->get();

        foreach ($entries->groupBy('chart_account_id') as $accountEntries) {
            $account = $accountEntries->first()->chartAccount;
            $balances = $this->balances($account);
            foreach ($accountEntries->groupBy('equity_contributor_id') as $contributorEntries) {
                $contributorId = $contributorEntries->first()->equity_contributor_id;
                if ((float) ($balances[$contributorId] ?? 0) + 0.01 < (float) $contributorEntries->sum('amount')) {
                    throw new InvalidArgumentException('Reverse this contributor’s later equity payouts before reversing the receipt.');
                }
            }
        }
    }

    /** @return Collection<int, JournalEntry> */
    public function unattributedPostings(ChartAccount $account, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $this->assertEquityAccount($account);

        return JournalEntry::query()
            ->with(['transaction', 'equityStakeEntries'])
            ->where(function ($query) use ($account): void {
                $query->where('chart_account_id', $account->id)
                    ->orWhereHas('lines', fn ($query) => $query->where('chart_account_id', $account->id));
            })
            ->where('status', 'posted')
            ->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])
            ->where(function ($query): void {
                $query->whereNull('transaction_id')
                    ->orWhereHas('transaction', fn ($query) => $query->where('status', 'posted')->whereNull('reversal_of_id'));
            })
            ->latest('journal_date')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(function (JournalEntry $journalEntry) use ($account): JournalEntry {
                try {
                    [, $postingAmount] = $this->postingAmount($account, $journalEntry);
                } catch (InvalidArgumentException) {
                    $postingAmount = 0;
                }
                $journalEntry->setAttribute('unattributed_amount', max(0, round($postingAmount - (float) $journalEntry->equityStakeEntries->sum('amount'), 2)));

                return $journalEntry;
            })
            ->filter(fn (JournalEntry $journalEntry): bool => (float) $journalEntry->unattributed_amount > 0)
            ->values();
    }

    private function balanceFor(ChartAccount $account, EquityContributor $contributor): float
    {
        return (float) ($this->balances($account)[$contributor->id] ?? 0);
    }

    /** @return array<int, float> */
    private function balances(ChartAccount $account): array
    {
        return EquityStakeEntry::query()
            ->with('journalEntry.transaction')
            ->where('chart_account_id', $account->id)
            ->get()
            ->filter(fn (EquityStakeEntry $entry): bool => $entry->journalEntry?->status === 'posted'
                && (! $entry->journalEntry->transaction
                    || ($entry->journalEntry->transaction->status === 'posted' && ! $entry->journalEntry->transaction->reversal_of_id)))
            ->groupBy('equity_contributor_id')
            ->map(fn (Collection $entries): float => (float) $entries->sum(fn (EquityStakeEntry $entry): float => ($entry->entry_type === 'credit' ? 1 : -1) * (float) $entry->amount))
            ->all();
    }

    /** @return array{0: 'credit'|'debit', 1: float} */
    private function postingAmount(ChartAccount $account, JournalEntry $journalEntry): array
    {
        $credit = (float) $journalEntry->lines()->where('chart_account_id', $account->id)->sum('credit');
        $debit = (float) $journalEntry->lines()->where('chart_account_id', $account->id)->sum('debit');

        if ($credit <= 0 && $debit <= 0 && (int) $journalEntry->chart_account_id === (int) $account->id) {
            $credit = $journalEntry->entry_type === 'credit' ? (float) $journalEntry->amount : 0;
            $debit = $journalEntry->entry_type === 'debit' ? (float) $journalEntry->amount : 0;
        }

        if (($credit > 0 && $debit > 0) || max($credit, $debit) <= 0) {
            throw new InvalidArgumentException('Select a posting with one equity debit or credit amount.');
        }

        return $credit > 0 ? ['credit', $credit] : ['debit', $debit];
    }

    private function assertEquityAccount(ChartAccount $account): void
    {
        if ($account->type !== 'equity' || $account->merged_into_id) {
            throw new InvalidArgumentException('Select an unmerged equity ledger account.');
        }
    }
}
