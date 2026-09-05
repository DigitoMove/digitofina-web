<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class LedgerAccountStatementService
{
    public function currentBalance(ChartAccount $account, ?int $branchId = null): float
    {
        $totals = $this->totals($this->baseQuery($account, $branchId), $account);

        return $this->naturalBalance($account, $totals['debit'], $totals['credit']);
    }

    /**
     * @return array{
     *     from: CarbonImmutable,
     *     to: CarbonImmutable,
     *     opening_debits: float,
     *     opening_credits: float,
     *     opening_balance: float,
     *     period_debits: float,
     *     period_credits: float,
     *     client_payments: float,
     *     drawer_funding_out: float,
     *     drawer_deposits_in: float,
     *     closing_balance: float,
     *     entries: LengthAwarePaginator<int, JournalEntry>
     * }
     */
    public function build(ChartAccount $chartAccount, CarbonImmutable $from, CarbonImmutable $to, ?int $branchId = null, string $search = ''): array
    {
        $baseQuery = $this->baseQuery($chartAccount, $branchId);
        $openingTotals = $this->totals(
            (clone $baseQuery)->whereRaw($this->effectiveDateExpression().' < ?', [$from->toDateString()]),
            $chartAccount,
        );
        $periodQuery = (clone $baseQuery)->whereRaw(
            $this->effectiveDateExpression().' between ? and ?',
            [$from->toDateString(), $to->toDateString()],
        );
        $periodTotals = $this->totals(clone $periodQuery, $chartAccount);
        $openingBalance = $this->naturalBalance($chartAccount, $openingTotals['debit'], $openingTotals['credit']);
        $periodMovement = $this->naturalBalance($chartAccount, $periodTotals['debit'], $periodTotals['credit']);

        $entryQuery = $this->withStatementAmounts(clone $periodQuery, $chartAccount)
            ->with([
                'branch:id,name,code',
                'transaction.type:id,name,code',
                'transaction.account.client:id,client_number,first_name,middle_name,last_name,business_name',
                'transaction.drawerSession.drawer:id,code,name',
                'lines' => fn ($query) => $query->where('chart_account_id', $chartAccount->id),
            ]);

        if ($search !== '') {
            $entryQuery->where(function (Builder $query) use ($search): void {
                $query->whereAny(['reference', 'journal_number', 'description', 'memo'], 'like', "%{$search}%")
                    ->orWhereHas('transaction.account', fn (Builder $query): Builder => $query->whereAny(['account_number', 'account_name'], 'like', "%{$search}%"))
                    ->orWhereHas('transaction.account.client', fn (Builder $query): Builder => $query->whereAny(['client_number', 'first_name', 'middle_name', 'last_name', 'business_name'], 'like', "%{$search}%"))
                    ->orWhereHas('lines', function (Builder $query) use ($search): void {
                        $query->where('narration', 'like', "%{$search}%")
                            ->orWhereHas('chartAccount', fn (Builder $query): Builder => $query->whereAny(['code', 'name'], 'like', "%{$search}%"));
                    });
            });
        }

        $entries = $entryQuery
            ->orderByRaw($this->effectiveDateExpression())
            ->orderBy('journal_entries.id')
            ->paginate(50)
            ->withQueryString();
        $this->applyRunningBalances($entries, $periodQuery, $chartAccount, $openingBalance);

        return [
            'from' => $from,
            'to' => $to,
            'opening_debits' => $openingTotals['debit'],
            'opening_credits' => $openingTotals['credit'],
            'opening_balance' => $openingBalance,
            'period_debits' => $periodTotals['debit'],
            'period_credits' => $periodTotals['credit'],
            'client_payments' => $this->entryTotal((clone $periodQuery)->whereHas('transaction', fn (Builder $query): Builder => $query->whereNotNull('account_id')), $chartAccount, 'debit'),
            'drawer_funding_out' => $this->entryTotal((clone $periodQuery)->whereHas('transaction', fn (Builder $query): Builder => $query->whereNotNull('drawer_session_id')), $chartAccount, 'credit'),
            'drawer_deposits_in' => $this->entryTotal((clone $periodQuery)->whereHas('transaction', fn (Builder $query): Builder => $query->whereNotNull('drawer_session_id')), $chartAccount, 'debit'),
            'closing_balance' => $openingBalance + $periodMovement,
            'entries' => $entries,
        ];
    }

    private function baseQuery(ChartAccount $chartAccount, ?int $branchId): Builder
    {
        return JournalEntry::query()
            ->where('status', 'posted')
            ->where(function (Builder $query) use ($chartAccount): void {
                $query->where('chart_account_id', $chartAccount->id)
                    ->orWhereHas('lines', fn (Builder $query): Builder => $query->where('chart_account_id', $chartAccount->id));
            })
            ->when($branchId, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId));
    }

    /**
     * @return array{debit: float, credit: float}
     */
    private function totals(Builder $query, ChartAccount $chartAccount): array
    {
        [$debitExpression, $debitBindings] = $this->statementAmountExpression($chartAccount, 'debit', 'debit');
        [$creditExpression, $creditBindings] = $this->statementAmountExpression($chartAccount, 'credit', 'credit');

        $totals = $query->selectRaw("COALESCE(SUM({$debitExpression}), 0) AS debit_total", $debitBindings)
            ->selectRaw("COALESCE(SUM({$creditExpression}), 0) AS credit_total", $creditBindings)
            ->first();

        return [
            'debit' => (float) ($totals?->debit_total ?? 0),
            'credit' => (float) ($totals?->credit_total ?? 0),
        ];
    }

    private function entryTotal(Builder $query, ChartAccount $chartAccount, string $side): float
    {
        [$expression, $bindings] = $this->statementAmountExpression($chartAccount, $side, $side);

        return (float) $query->selectRaw("COALESCE(SUM({$expression}), 0) AS total", $bindings)->value('total');
    }

    private function naturalBalance(ChartAccount $chartAccount, float $debit, float $credit): float
    {
        return $chartAccount->normal_balance === 'debit' ? $debit - $credit : $credit - $debit;
    }

    private function effectiveDateExpression(string $table = 'journal_entries'): string
    {
        return "COALESCE(DATE({$table}.journal_date), DATE({$table}.posted_at), DATE({$table}.created_at))";
    }

    private function withStatementAmounts(Builder $query, ChartAccount $chartAccount): Builder
    {
        [$debitExpression, $debitBindings] = $this->statementAmountExpression($chartAccount, 'debit', 'debit');
        [$creditExpression, $creditBindings] = $this->statementAmountExpression($chartAccount, 'credit', 'credit');

        return $query
            ->select('journal_entries.*')
            ->selectRaw("({$debitExpression}) AS statement_debit", $debitBindings)
            ->selectRaw("({$creditExpression}) AS statement_credit", $creditBindings);
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function statementAmountExpression(ChartAccount $chartAccount, string $lineColumn, string $fallbackEntryType, string $table = 'journal_entries'): array
    {
        return [
            "COALESCE((SELECT SUM(statement_lines.{$lineColumn}) FROM journal_lines AS statement_lines WHERE statement_lines.journal_entry_id = {$table}.id AND statement_lines.chart_account_id = ?), CASE WHEN {$table}.chart_account_id = ? AND {$table}.entry_type = ? THEN {$table}.amount ELSE 0 END)",
            [$chartAccount->id, $chartAccount->id, $fallbackEntryType],
        ];
    }

    private function applyRunningBalances(LengthAwarePaginator $entries, Builder $periodQuery, ChartAccount $chartAccount, float $openingBalance): void
    {
        $collection = $entries->getCollection();

        if ($collection->isEmpty()) {
            return;
        }

        /** @var JournalEntry $firstEntry */
        $firstEntry = $collection->first();
        $firstEntryDate = $this->effectiveDateForEntry($firstEntry);
        $beforePageTotals = $this->totals(
            (clone $periodQuery)->where(function (Builder $query) use ($firstEntry, $firstEntryDate): void {
                $query->whereRaw($this->effectiveDateExpression().' < ?', [$firstEntryDate])
                    ->orWhere(function (Builder $query) use ($firstEntry, $firstEntryDate): void {
                        $query->whereRaw($this->effectiveDateExpression().' = ?', [$firstEntryDate])
                            ->where('journal_entries.id', '<', $firstEntry->id);
                    });
            }),
            $chartAccount,
        );
        $runningBalance = $openingBalance + $this->naturalBalance($chartAccount, $beforePageTotals['debit'], $beforePageTotals['credit']);

        $entries->setCollection($collection->map(function (JournalEntry $entry) use (&$runningBalance, $chartAccount): JournalEntry {
            $runningBalance += $this->naturalBalance(
                $chartAccount,
                (float) $entry->getAttribute('statement_debit'),
                (float) $entry->getAttribute('statement_credit'),
            );
            $entry->setAttribute('running_balance_after', $runningBalance);

            return $entry;
        }));
    }

    private function effectiveDateForEntry(JournalEntry $entry): string
    {
        return CarbonImmutable::parse($entry->journal_date ?? $entry->posted_at ?? $entry->created_at)->toDateString();
    }
}
