<?php

namespace App\Services;

use Illuminate\Support\Collection;

class ReportSubtotalService
{
    /**
     * @var array<string, array{group: string, metrics: array<string, 'money'|'count'>}>
     */
    private const REPORTS = [
        'transactions' => ['group' => 'Type', 'metrics' => ['Amount' => 'money', 'Charges' => 'money']],
        'teller_transactions' => ['group' => 'Direction', 'metrics' => ['Amount' => 'money']],
        'non_cash_transactions' => ['group' => 'Type', 'metrics' => ['Amount' => 'money']],
        'cash_balances' => ['group' => 'Branch', 'metrics' => ['Balance' => 'money']],
        'branch_period_summary' => ['group' => 'Branch', 'metrics' => ['Cash In' => 'money', 'Cash Out' => 'money', 'Variance' => 'money', 'Loan Repayments' => 'money', 'Loan Disbursements' => 'money']],
        'clients' => ['group' => 'Branch', 'metrics' => []],
        'officer_entries' => ['group' => 'Branch', 'metrics' => ['Clients Added' => 'count', 'Loans Created' => 'count', 'Approved Amount' => 'money', 'Outstanding Balance' => 'money']],
        'chart_accounts' => ['group' => 'Type', 'metrics' => []],
        'journal_entries' => ['group' => 'Account', 'metrics' => ['Amount' => 'money']],
        'journal_analysis' => ['group' => 'Account', 'metrics' => ['Entries' => 'count', 'Amount' => 'money']],
        'application_fees_income' => ['group' => 'Branch', 'metrics' => ['Application Fee Charged' => 'money', 'Application Fee Refunded' => 'money']],
        'loan_interest_income' => ['group' => 'Product', 'metrics' => ['Projected Interest' => 'money', 'Interest Collected' => 'money', 'Interest Outstanding' => 'money', 'Interest Shown' => 'money']],
        'accounts' => ['group' => 'Branch', 'metrics' => ['Balance' => 'money']],
        'savings_adjusted_minimums' => ['group' => 'Branch', 'metrics' => ['Balance' => 'money']],
        'savings_posted_interest' => ['group' => 'Branch', 'metrics' => ['Postings' => 'count', 'Interest Posted' => 'money']],
        'savings_standing_orders' => ['group' => 'Frequency', 'metrics' => ['Amount' => 'money']],
        'shares' => ['group' => 'Product', 'metrics' => ['Shares' => 'count', 'Capital' => 'money']],
        'share_transactions' => ['group' => 'Type', 'metrics' => ['Shares' => 'count', 'Amount' => 'money']],
        'loans' => ['group' => 'Product', 'metrics' => ['Requested' => 'money', 'Approved' => 'money', 'Balance' => 'money']],
        'loan_disbursements' => ['group' => 'By', 'metrics' => ['Gross Principal' => 'money', 'Application Fee Charged' => 'money', 'Net Credited' => 'money']],
        'loan_book' => ['group' => 'Product', 'metrics' => ['Paid Total' => 'money', 'Overdue Total' => 'money', 'Balance Total' => 'money']],
        'loan_schedules' => ['group' => 'Product', 'metrics' => ['Installments Due' => 'count', 'Total Due' => 'money']],
        'loan_repayments' => ['group' => 'Officer', 'metrics' => ['Amount' => 'money', 'Principal' => 'money', 'Interest' => 'money', 'Fees' => 'money', 'Penalty' => 'money']],
        'term_deposits' => ['group' => 'Product', 'metrics' => ['Amount' => 'money', 'Interest' => 'money']],
        'investment_accounts' => ['group' => 'Plan', 'metrics' => ['Principal' => 'money', 'Balance' => 'money', 'Expected Return' => 'money', 'Actual Return' => 'money']],
        'investment_transactions' => ['group' => 'Plan', 'metrics' => ['Amount' => 'money']],
    ];

    /**
     * @param  array<int, string>  $columns
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{group: string, columns: array<int, string>, rows: array<int, array<string, string>>, displayed_count: int}|null
     */
    public function calculate(string $kind, array $columns, Collection $rows, string $currency): ?array
    {
        $configuration = self::REPORTS[$kind] ?? null;

        if ($configuration === null || $rows->count() < 2 || ! in_array($configuration['group'], $columns, true)) {
            return null;
        }

        foreach (array_keys($configuration['metrics']) as $metric) {
            if (! in_array($metric, $columns, true)) {
                return null;
            }
        }

        $detailRows = $rows->reject(fn (array $row): bool => (bool) ($row['_is_total'] ?? false));

        if ($detailRows->count() < 2) {
            return null;
        }

        $subtotals = [];

        foreach ($detailRows as $row) {
            $group = trim((string) ($row[$configuration['group']] ?? '')) ?: 'Unassigned';
            $subtotals[$group] ??= ['Rows' => 0];
            $subtotals[$group]['Rows']++;

            foreach ($configuration['metrics'] as $metric => $format) {
                $amount = $this->numericValue($row[$metric] ?? null);

                if ($amount === null) {
                    return null;
                }

                $subtotals[$group][$metric] = ($subtotals[$group][$metric] ?? 0) + $amount;
            }
        }

        ksort($subtotals);

        $formattedRows = [];

        foreach ($subtotals as $group => $amounts) {
            $formatted = [$configuration['group'] => $group, 'Rows' => number_format($amounts['Rows'])];

            foreach ($configuration['metrics'] as $metric => $format) {
                $amount = $amounts[$metric] ?? 0;
                $formatted[$metric] = $format === 'count'
                    ? number_format((int) round($amount))
                    : $currency.' '.number_format($amount, 2);
            }

            $formattedRows[] = $formatted;
        }

        return [
            'group' => $configuration['group'],
            'columns' => [$configuration['group'], 'Rows', ...array_keys($configuration['metrics'])],
            'rows' => $formattedRows,
            'displayed_count' => $detailRows->count(),
        ];
    }

    private function numericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $formatted = trim((string) $value);

        if ($formatted === '' || preg_match('/^(?:[A-Z]{3} )?-?[0-9][0-9,]*(?:\.[0-9]{1,2})?$/', $formatted) !== 1) {
            return null;
        }

        return (float) str_replace(',', '', preg_replace('/^[A-Z]{3} /', '', $formatted));
    }
}
