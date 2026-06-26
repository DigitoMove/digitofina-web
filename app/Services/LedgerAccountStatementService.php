<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class LedgerAccountStatementService
{
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
        );
        $periodQuery = (clone $baseQuery)->whereRaw(
            $this->effectiveDateExpression().' between ? and ?',
            [$from->toDateString(), $to->toDateString()],
        );
        $periodTotals = $this->totals(clone $periodQuery);
        $openingBalance = $this->naturalBalance($chartAccount, $openingTotals['debit'], $openingTotals['credit']);
        $periodMovement = $this->naturalBalance($chartAccount, $periodTotals['debit'], $periodTotals['credit']);

        $entryQuery = (clone $periodQuery)
            ->with([
                'branch:id,name,code',
                'transaction.type:id,name,code',
                'transaction.account.client:id,client_number,first_name,middle_name,last_name,business_name',
                'transaction.drawerSession.drawer:id,code,name',
            ]);

        if ($search !== '') {
            $entryQuery->where(function (Builder $query) use ($search): void {
                $query->whereAny(['reference', 'journal_number', 'description', 'memo'], 'like', "%{$search}%")
                    ->orWhereHas('transaction.account', fn (Builder $query): Builder => $query->whereAny(['account_number', 'account_name'], 'like', "%{$search}%"))
                    ->orWhereHas('transaction.account.client', fn (Builder $query): Builder => $query->whereAny(['client_number', 'first_name', 'middle_name', 'last_name', 'business_name'], 'like', "%{$search}%"));
            });
        }

        $entryQuery->select('journal_entries.*')->selectRaw(
            '? + COALESCE((SELECT SUM(CASE WHEN running.entry_type = ? THEN running.amount ELSE -running.amount END) FROM journal_entries AS running WHERE running.chart_account_id = journal_entries.chart_account_id AND running.status = ? AND '.$this->effectiveDateExpression('running').' >= ?'.($branchId ? ' AND running.branch_id = ?' : '').' AND ('.$this->effectiveDateExpression('running').' < '.$this->effectiveDateExpression('journal_entries').' OR ('.$this->effectiveDateExpression('running').' = '.$this->effectiveDateExpression('journal_entries').' AND running.id <= journal_entries.id))), 0) AS running_balance_after',
            array_values(array_filter([
                $openingBalance,
                $chartAccount->normal_balance,
                'posted',
                $from->toDateString(),
                $branchId,
            ], fn (mixed $value): bool => $value !== null)),
        );

        $entries = $entryQuery
            ->orderByRaw($this->effectiveDateExpression())
            ->orderBy('journal_entries.id')
            ->paginate(50)
            ->withQueryString();

        return [
            'from' => $from,
            'to' => $to,
            'opening_debits' => $openingTotals['debit'],
            'opening_credits' => $openingTotals['credit'],
            'opening_balance' => $openingBalance,
            'period_debits' => $periodTotals['debit'],
            'period_credits' => $periodTotals['credit'],
            'client_payments' => $this->entryTotal((clone $periodQuery)->where('entry_type', 'debit')->whereHas('transaction', fn (Builder $query): Builder => $query->whereNotNull('account_id'))),
            'drawer_funding_out' => $this->entryTotal((clone $periodQuery)->where('entry_type', 'credit')->whereHas('transaction', fn (Builder $query): Builder => $query->whereNotNull('drawer_session_id'))),
            'drawer_deposits_in' => $this->entryTotal((clone $periodQuery)->where('entry_type', 'debit')->whereHas('transaction', fn (Builder $query): Builder => $query->whereNotNull('drawer_session_id'))),
            'closing_balance' => $openingBalance + $periodMovement,
            'entries' => $entries,
        ];
    }

    private function baseQuery(ChartAccount $chartAccount, ?int $branchId): Builder
    {
        return JournalEntry::query()
            ->where('chart_account_id', $chartAccount->id)
            ->where('status', 'posted')
            ->when($branchId, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId));
    }

    /**
     * @return array{debit: float, credit: float}
     */
    private function totals(Builder $query): array
    {
        $totals = $query->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE 0 END), 0) AS debit_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END), 0) AS credit_total")
            ->first();

        return [
            'debit' => (float) ($totals?->debit_total ?? 0),
            'credit' => (float) ($totals?->credit_total ?? 0),
        ];
    }

    private function entryTotal(Builder $query): float
    {
        return (float) $query->sum('amount');
    }

    private function naturalBalance(ChartAccount $chartAccount, float $debit, float $credit): float
    {
        return $chartAccount->normal_balance === 'debit' ? $debit - $credit : $credit - $debit;
    }

    private function effectiveDateExpression(string $table = 'journal_entries'): string
    {
        return "COALESCE(DATE({$table}.journal_date), DATE({$table}.posted_at), DATE({$table}.created_at))";
    }
}
