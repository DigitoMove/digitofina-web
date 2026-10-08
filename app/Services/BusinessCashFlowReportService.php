<?php

namespace App\Services;

use App\CurrencyRateService;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanRepayment;
use App\Models\User;
use App\Support\ApiSecurity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BusinessCashFlowReportService
{
    public function __construct(
        private TenantContext $tenant,
        private CurrencyRateService $currencyRates,
    ) {}

    public function earliestActivityDate(User $user, ?int $branchId = null): CarbonImmutable
    {
        $branchId = $this->effectiveBranchId($branchId, $user);
        $organizationId = $this->organizationId($user);
        $dates = collect([
            $this->earliestJournalDate($organizationId, $branchId),
            $this->earliestLoanDate(LoanDisbursement::class, 'disbursement_date', $organizationId, $branchId),
            $this->earliestLoanDate(LoanRepayment::class, 'paid_at', $organizationId, $branchId),
        ])->filter()->sort()->values();

        return CarbonImmutable::parse($dates->first() ?: today()->toDateString())->startOfDay();
    }

    /**
     * @return array{columns:array<int, string>, rows:Collection<int, array<string, mixed>>, summary:array<string, string>, position_columns:array<int, string>, position_rows:Collection<int, array<string, mixed>>, position_summary:array<string, string>, period:string}
     */
    public function generate(CarbonInterface $from, CarbonInterface $to, string $period, ?int $branchId, User $user, string $currency = 'UGX', string $baseCurrency = 'UGX'): array
    {
        $period = $this->normalizePeriod($period);
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->endOfDay();
        $activity = $this->activity($from, $to, $period, $branchId, $user);
        $periods = $this->periods($from, $to, $period);
        $rows = collect($periods)->map(function (array $periodRow) use ($activity, $currency, $baseCurrency): array {
            $metrics = $activity->get($periodRow['key'], $this->emptyMetrics());
            $net = $metrics['capital_in'] + $metrics['other_income_in'] + $metrics['loan_repayment_in'] - $metrics['operating_expense_out'] - $metrics['loan_disbursement_out'];

            return [
                'Period' => $periodRow['label'],
                'Capital / Funding In' => $this->money($metrics['capital_in'], $currency, $baseCurrency),
                'Other Income In' => $this->money($metrics['other_income_in'], $currency, $baseCurrency),
                'Operating Expenses Out' => $this->money($metrics['operating_expense_out'], $currency, $baseCurrency),
                'Loan Disbursements Out' => $this->money($metrics['loan_disbursement_out'], $currency, $baseCurrency),
                'Loan Repayments In' => $this->money($metrics['loan_repayment_in'], $currency, $baseCurrency),
                'Principal Repaid' => $this->money($metrics['principal_repaid'], $currency, $baseCurrency),
                'Interest Repaid' => $this->money($metrics['interest_repaid'], $currency, $baseCurrency),
                'Fees Repaid' => $this->money($metrics['fees_repaid'], $currency, $baseCurrency),
                'Penalties Repaid' => $this->money($metrics['penalties_repaid'], $currency, $baseCurrency),
                'Net Cash Flow' => $this->money($net, $currency, $baseCurrency),
                '_metrics' => [...$metrics, 'net_cash_flow' => $net],
            ];
        })->filter(fn (array $row): bool => $this->hasGrossActivity($row['_metrics']))->values();

        $cumulative = 0.0;
        $rows = $rows->map(function (array $row) use (&$cumulative, $currency, $baseCurrency): array {
            $cumulative += (float) $row['_metrics']['net_cash_flow'];
            $row['Cumulative Cash Flow'] = $this->money($cumulative, $currency, $baseCurrency);
            $row['_metrics']['cumulative_cash_flow'] = $cumulative;

            return $row;
        });

        $totals = $rows->reduce(function (array $carry, array $row): array {
            foreach ($row['_metrics'] as $key => $value) {
                if ($key !== 'cumulative_cash_flow') {
                    $carry[$key] = ($carry[$key] ?? 0) + (float) $value;
                }
            }

            return $carry;
        }, $this->emptyMetrics());
        $totalNet = $totals['capital_in'] + $totals['other_income_in'] + $totals['loan_repayment_in'] - $totals['operating_expense_out'] - $totals['loan_disbursement_out'];
        $rows->push([
            'Period' => 'Lifetime / Selected Total',
            'Capital / Funding In' => $this->money($totals['capital_in'], $currency, $baseCurrency),
            'Other Income In' => $this->money($totals['other_income_in'], $currency, $baseCurrency),
            'Operating Expenses Out' => $this->money($totals['operating_expense_out'], $currency, $baseCurrency),
            'Loan Disbursements Out' => $this->money($totals['loan_disbursement_out'], $currency, $baseCurrency),
            'Loan Repayments In' => $this->money($totals['loan_repayment_in'], $currency, $baseCurrency),
            'Principal Repaid' => $this->money($totals['principal_repaid'], $currency, $baseCurrency),
            'Interest Repaid' => $this->money($totals['interest_repaid'], $currency, $baseCurrency),
            'Fees Repaid' => $this->money($totals['fees_repaid'], $currency, $baseCurrency),
            'Penalties Repaid' => $this->money($totals['penalties_repaid'], $currency, $baseCurrency),
            'Net Cash Flow' => $this->money($totalNet, $currency, $baseCurrency),
            'Cumulative Cash Flow' => $this->money($totalNet, $currency, $baseCurrency),
            '_metrics' => [...$totals, 'net_cash_flow' => $totalNet],
            '_is_total' => true,
        ]);

        $position = $this->currentPosition($to, $branchId, $user);

        return [
            'columns' => ['Period', 'Capital / Funding In', 'Other Income In', 'Operating Expenses Out', 'Loan Disbursements Out', 'Loan Repayments In', 'Principal Repaid', 'Interest Repaid', 'Fees Repaid', 'Penalties Repaid', 'Net Cash Flow', 'Cumulative Cash Flow'],
            'rows' => $rows,
            'summary' => [
                'Period view' => ucfirst(str_replace('_', ' ', $period)),
                'Selected net cash flow' => $this->money($totalNet, $currency, $baseCurrency),
                'Loan repayments received' => $this->money($totals['loan_repayment_in'], $currency, $baseCurrency),
                'Current profit (accrual)' => $this->money($position['profit'], $currency, $baseCurrency),
                'Current cash (accounting)' => $this->money($position['cash'], $currency, $baseCurrency),
            ],
            'position_columns' => ['Metric', 'Amount', 'Basis'],
            'position_rows' => $this->positionRows($position, $currency, $baseCurrency),
            'position_summary' => [
                'Total assets' => $this->money($position['assets'], $currency, $baseCurrency),
                'Total liabilities' => $this->money($position['liabilities'], $currency, $baseCurrency),
                'Total equity' => $this->money($position['equity'], $currency, $baseCurrency),
                'Profit margin' => number_format($position['margin'], 2).'%',
            ],
            'period' => $period,
        ];
    }

    /**
     * @return Collection<string, array<string, float>>
     */
    private function activity(CarbonInterface $from, CarbonInterface $to, string $period, ?int $branchId, User $user): Collection
    {
        $activity = collect();

        foreach ($this->ledgerMovements($from, $to, $branchId, $user) as $movement) {
            $metrics = $this->emptyMetrics();

            if ($movement['type'] === 'equity') {
                $metrics['capital_in'] = max(0, $movement['credit'] - $movement['debit']);
            } elseif ($movement['type'] === 'income' && ! $movement['is_loan_repayment']) {
                $income = max(0, $movement['credit'] - $movement['debit']);

                if (str_contains(strtolower($movement['account_name']), 'application fee')) {
                    $metrics['application_fee_ledger_in'] = $income;
                } else {
                    $metrics['other_income_in'] = $income;
                }
            } elseif ($movement['type'] === 'expense') {
                $metrics['operating_expense_out'] = max(0, $movement['debit'] - $movement['credit']);
            }

            $this->mergeActivity($activity, $this->periodKey($movement['date'], $period), $metrics);
        }

        foreach ($this->loanDisbursements($from, $to, $branchId, $user) as $disbursement) {
            $metrics = $this->emptyMetrics();
            $metrics['loan_disbursement_out'] = (float) $disbursement->disbursed_amount;
            $metrics['application_fee_disbursed'] = (float) $disbursement->fees_deducted;
            $this->mergeActivity($activity, $this->periodKey($disbursement->disbursement_date, $period), $metrics);
        }

        foreach ($this->loanRepayments($from, $to, $branchId, $user) as $repayment) {
            $metrics = $this->emptyMetrics();
            $metrics['loan_repayment_in'] = (float) $repayment->amount;
            $metrics['principal_repaid'] = (float) $repayment->principal_paid;
            $metrics['interest_repaid'] = (float) $repayment->interest_paid;
            $metrics['fees_repaid'] = (float) $repayment->fees_paid;
            $metrics['penalties_repaid'] = (float) $repayment->penalty_paid;
            $this->mergeActivity($activity, $this->periodKey($repayment->paid_at, $period), $metrics);
        }

        return $activity->map(function (array $metrics): array {
            $metrics['other_income_in'] += max($metrics['application_fee_ledger_in'], $metrics['application_fee_disbursed']);

            return $metrics;
        });
    }

    /**
     * @param  array<string, float>  $metrics
     */
    private function hasGrossActivity(array $metrics): bool
    {
        foreach ([
            'capital_in',
            'other_income_in',
            'operating_expense_out',
            'loan_disbursement_out',
            'loan_repayment_in',
            'principal_repaid',
            'interest_repaid',
            'fees_repaid',
            'penalties_repaid',
        ] as $metric) {
            if ((float) ($metrics[$metric] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentPosition(CarbonInterface $to, ?int $branchId, User $user): array
    {
        $balances = $this->ledgerBalances($to, $branchId, $user);
        $income = (float) $balances->where('type', 'income')->sum('balance');
        $expenses = (float) $balances->where('type', 'expense')->sum('balance');
        $profit = $income - $expenses;
        $assets = (float) $balances->where('type', 'asset')->sum('balance');
        $liabilities = (float) $balances->where('type', 'liability')->sum('balance');
        $equity = (float) $balances->where('type', 'equity')->sum('balance') + $profit;
        $cash = (float) $balances->where('type', 'asset')->where('is_cash_account', true)->sum('balance');
        $loans = Loan::query()
            ->where('organization_id', $this->organizationId($user))
            ->whereIn('status', ['disbursed', 'active', 'in_arrears', 'restructured'])
            ->when($this->effectiveBranchId($branchId, $user), fn (Builder $query, int $branch): Builder => $query->where('branch_id', $branch))
            ->get();
        $principal = (float) $loans->sum('principal_balance');
        $interest = (float) $loans->sum('interest_balance');
        $fees = (float) $loans->sum('fees_balance');
        $penalties = (float) $loans->sum('penalty_balance');
        $incomeTotal = $income;

        return compact('assets', 'liabilities', 'equity', 'cash', 'profit', 'incomeTotal', 'principal', 'interest', 'fees', 'penalties') + [
            'loan_total' => $principal + $interest + $fees + $penalties,
            'margin' => $incomeTotal > 0 ? ($profit / $incomeTotal) * 100 : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $position
     * @return Collection<int, array<string, string>>
     */
    private function positionRows(array $position, string $currency, string $baseCurrency): Collection
    {
        return collect([
            ['Metric' => 'Outstanding loan principal', 'Amount' => $this->money($position['principal'], $currency, $baseCurrency), 'Basis' => 'Current operational loan balances.'],
            ['Metric' => 'Outstanding loan interest', 'Amount' => $this->money($position['interest'], $currency, $baseCurrency), 'Basis' => 'Current accrued/remaining loan balances.'],
            ['Metric' => 'Outstanding loan fees', 'Amount' => $this->money($position['fees'], $currency, $baseCurrency), 'Basis' => 'Current remaining loan fees.'],
            ['Metric' => 'Outstanding loan penalties', 'Amount' => $this->money($position['penalties'], $currency, $baseCurrency), 'Basis' => 'Current remaining loan penalties.'],
            ['Metric' => 'Total outstanding loan balance', 'Amount' => $this->money($position['loan_total'], $currency, $baseCurrency), 'Basis' => 'Principal, interest, fees, and penalties combined.'],
            ['Metric' => 'Current profit', 'Amount' => $this->money($position['profit'], $currency, $baseCurrency), 'Basis' => 'Accounting/accrual ledger income less ledger expenses through report end.'],
            ['Metric' => 'Current cash', 'Amount' => $this->money($position['cash'], $currency, $baseCurrency), 'Basis' => 'Accounting asset accounts marked as cash.'],
            ['Metric' => 'Total assets', 'Amount' => $this->money($position['assets'], $currency, $baseCurrency), 'Basis' => 'Accounting/accrual ledger position through report end.'],
            ['Metric' => 'Total liabilities', 'Amount' => $this->money($position['liabilities'], $currency, $baseCurrency), 'Basis' => 'Accounting/accrual ledger position through report end.'],
            ['Metric' => 'Total equity', 'Amount' => $this->money($position['equity'], $currency, $baseCurrency), 'Basis' => 'Ledger equity plus current retained profit.'],
            ['Metric' => 'Profit margin', 'Amount' => number_format($position['margin'], 2).'%', 'Basis' => 'Current profit divided by accounting income.'],
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function ledgerMovements(CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user): Collection
    {
        $query = JournalEntry::query()
            ->with(['chartAccount', 'lines.chartAccount'])
            ->where('organization_id', $this->organizationId($user))
            ->where('status', 'posted')
            ->whereBetween(DB::raw('coalesce(journal_date, posted_at, created_at)'), [$from, $to]);
        $this->applyJournalBranchScope($query, $branchId, $user);

        return $query->get()->flatMap(function (JournalEntry $entry): Collection {
            $date = CarbonImmutable::parse($entry->journal_date?->toDateString() ?: $entry->posted_at?->toDateString() ?: $entry->created_at?->toDateString());
            $isLoanRepayment = str_starts_with((string) $entry->reference, 'LR-') || str_starts_with((string) $entry->journal_number, 'LR') || str_contains(strtolower((string) $entry->description), 'loan repayment');

            if ($entry->lines->isNotEmpty()) {
                return $entry->lines->map(fn ($line): array => [
                    'date' => $date,
                    'type' => $line->chartAccount?->type,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'is_cash_account' => (bool) $line->chartAccount?->is_cash_account,
                    'account_name' => (string) ($line->chartAccount?->name ?? ''),
                    'account_id' => $line->chart_account_id,
                    'is_loan_repayment' => $isLoanRepayment,
                ]);
            }

            if (! $entry->chartAccount) {
                return collect();
            }

            return collect([[
                'date' => $date,
                'type' => $entry->chartAccount->type,
                'debit' => $entry->entry_type === 'debit' ? (float) $entry->amount : 0.0,
                'credit' => $entry->entry_type === 'credit' ? (float) $entry->amount : 0.0,
                'is_cash_account' => (bool) $entry->chartAccount->is_cash_account,
                'account_name' => (string) $entry->chartAccount->name,
                'account_id' => $entry->chart_account_id,
                'is_loan_repayment' => $isLoanRepayment,
            ]]);
        })->values();
    }

    private function ledgerBalances(CarbonInterface $to, ?int $branchId, User $user): Collection
    {
        $accounts = ChartAccount::query()
            ->where('organization_id', $this->organizationId($user))
            ->when($this->effectiveBranchId($branchId, $user), fn (Builder $query, int $branch): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $branch)->orWhereNull('branch_id')))
            ->get()
            ->keyBy('id');
        $movements = $this->ledgerMovements(CarbonImmutable::parse('1900-01-01')->startOfDay(), $to, $branchId, $user);

        return $accounts->map(function (ChartAccount $account) use ($movements): array {
            $accountMovements = $movements->where('account_id', $account->id);
            $debit = (float) $accountMovements->sum('debit');
            $credit = (float) $accountMovements->sum('credit');
            $balance = in_array($account->type, ['asset', 'expense'], true) ? $debit - $credit : $credit - $debit;

            return [
                'type' => $account->type,
                'balance' => $balance,
                'is_cash_account' => (bool) $account->is_cash_account,
            ];
        })->values();
    }

    private function loanDisbursements(CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user): Collection
    {
        return LoanDisbursement::query()
            ->where('organization_id', $this->organizationId($user))
            ->where('status', 'successful')
            ->whereBetween('disbursement_date', [$from->toDateString(), $to->toDateString()])
            ->when($this->effectiveBranchId($branchId, $user), fn (Builder $query, int $branch): Builder => $query->where('branch_id', $branch))
            ->get();
    }

    private function loanRepayments(CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user): Collection
    {
        return LoanRepayment::query()
            ->where('organization_id', $this->organizationId($user))
            ->where('status', 'successful')
            ->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])
            ->when($this->effectiveBranchId($branchId, $user), fn (Builder $query, int $branch): Builder => $query->where('branch_id', $branch))
            ->get();
    }

    /**
     * @return array<int, array{key:string, label:string}>
     */
    private function periods(CarbonInterface $from, CarbonInterface $to, string $period): array
    {
        $cursor = CarbonImmutable::parse($from)->startOfMonth();
        $periods = [];

        while ($cursor->lte($to)) {
            $periodStart = match ($period) {
                'quarterly' => $cursor->startOfQuarter(),
                'half_yearly' => $cursor->month <= 6 ? $cursor->startOfYear() : $cursor->startOfYear()->addMonths(6),
                'yearly' => $cursor->startOfYear(),
                default => $cursor,
            };
            $periodEnd = match ($period) {
                'quarterly' => $periodStart->endOfQuarter(),
                'half_yearly' => $periodStart->month === 1 ? $periodStart->endOfYear()->subMonths(6)->endOfMonth() : $periodStart->endOfYear(),
                'yearly' => $periodStart->endOfYear(),
                default => $periodStart->endOfMonth(),
            };
            $key = $this->periodKey($periodStart, $period);

            if (! collect($periods)->contains('key', $key)) {
                $periods[] = ['key' => $key, 'label' => $this->periodLabel($periodStart, $period)];
            }

            $cursor = $periodEnd->addDay()->startOfMonth();
        }

        return $periods;
    }

    private function periodKey(CarbonInterface|string $date, string $period): string
    {
        $date = CarbonImmutable::parse($date);

        return match ($period) {
            'quarterly' => $date->year.'-Q'.$date->quarter,
            'half_yearly' => $date->year.'-H'.($date->month <= 6 ? 1 : 2),
            'yearly' => (string) $date->year,
            default => $date->format('Y-m'),
        };
    }

    private function periodLabel(CarbonInterface $date, string $period): string
    {
        return match ($period) {
            'quarterly' => 'Q'.$date->quarter.' '.$date->year,
            'half_yearly' => 'H'.($date->month <= 6 ? 1 : 2).' '.$date->year,
            'yearly' => (string) $date->year,
            default => $date->format('M Y'),
        };
    }

    /**
     * @param  Collection<string, array<string, float>>  $activity
     * @param  array<string, float>  $metrics
     */
    private function mergeActivity(Collection $activity, string $key, array $metrics): void
    {
        $current = $activity->get($key, $this->emptyMetrics());

        foreach ($metrics as $metric => $amount) {
            $current[$metric] = ($current[$metric] ?? 0) + $amount;
        }

        $activity->put($key, $current);
    }

    /**
     * @return array<string, float>
     */
    private function emptyMetrics(): array
    {
        return [
            'capital_in' => 0.0,
            'other_income_in' => 0.0,
            'operating_expense_out' => 0.0,
            'loan_disbursement_out' => 0.0,
            'loan_repayment_in' => 0.0,
            'principal_repaid' => 0.0,
            'interest_repaid' => 0.0,
            'fees_repaid' => 0.0,
            'penalties_repaid' => 0.0,
            'application_fee_ledger_in' => 0.0,
            'application_fee_disbursed' => 0.0,
        ];
    }

    private function normalizePeriod(string $period): string
    {
        if (! in_array($period, ['monthly', 'quarterly', 'half_yearly', 'yearly'], true)) {
            throw new InvalidArgumentException('Unsupported business cash-flow period.');
        }

        return $period;
    }

    private function organizationId(User $user): int
    {
        $organizationId = $user->hasGlobalOrganizationAccess()
            ? ($this->tenant->id() ?: $user->organization_id)
            : $user->organization_id;

        abort_unless($organizationId, 422, 'Select an active organization before generating reports.');

        return (int) $organizationId;
    }

    private function effectiveBranchId(?int $branchId, User $user): ?int
    {
        if ($branchId) {
            return $branchId;
        }

        return ! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id ? (int) $user->branch_id : null;
    }

    private function applyJournalBranchScope(Builder $query, ?int $branchId, User $user): void
    {
        $branchId = $this->effectiveBranchId($branchId, $user);

        if (! $branchId) {
            return;
        }

        $query->where(function (Builder $query) use ($branchId): void {
            $query->where('journal_entries.branch_id', $branchId)
                ->orWhere(function (Builder $query) use ($branchId): void {
                    $query->whereNull('journal_entries.branch_id')
                        ->whereHas('transaction', fn (Builder $query): Builder => $query->where('branch_id', $branchId));
                });
        });
    }

    private function earliestJournalDate(int $organizationId, ?int $branchId): ?string
    {
        return JournalEntry::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'posted')
            ->when($branchId, function (Builder $query, int $branch): Builder {
                return $query->where(function (Builder $query) use ($branch): void {
                    $query->where('branch_id', $branch)
                        ->orWhere(function (Builder $query) use ($branch): void {
                            $query->whereNull('branch_id')
                                ->whereHas('transaction', fn (Builder $query): Builder => $query->where('branch_id', $branch));
                        });
                });
            })
            ->min(DB::raw('coalesce(journal_date, posted_at, created_at)'));
    }

    private function earliestLoanDate(string $model, string $column, int $organizationId, ?int $branchId): ?string
    {
        return $model::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'successful')
            ->when($branchId, fn (Builder $query, int $branch): Builder => $query->where('branch_id', $branch))
            ->min($column);
    }

    private function money(float $amount, string $currency, string $baseCurrency): string
    {
        $converted = strtoupper($currency) === strtoupper($baseCurrency)
            ? $amount
            : $this->currencyRates->convert($amount, $baseCurrency, $currency);

        return strtoupper($currency).' '.number_format($converted, 2);
    }
}
