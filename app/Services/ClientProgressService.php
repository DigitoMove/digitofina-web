<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanRepayment;
use Illuminate\Support\Collection;

class ClientProgressService
{
    /**
     * @param  Collection<int, Loan>  $loans
     * @return array<string, mixed>
     */
    public function summarize(Collection $loans): array
    {
        $operational = $loans->whereIn('status', Loan::OPERATIONAL_STATUSES);
        $installments = $operational->flatMap(fn (Loan $loan) => $loan->schedules
            ->filter(fn ($schedule): bool => $schedule->status !== 'paid' && (float) $schedule->outstanding_amount > 0)
            ->map(fn ($schedule): array => ['loan' => $loan, 'schedule' => $schedule]))
            ->sortBy(fn (array $item): string => $item['schedule']->due_date->toDateString())->values();
        $overdue = $installments->filter(fn (array $item): bool => $item['schedule']->due_date->lt(today()));
        $confirmed = $loans->flatMap->repayments->filter(fn (LoanRepayment $repayment): bool => $repayment->status === 'successful' && $repayment->reversed_at === null);
        $principalPaid = (float) $confirmed->sum('principal_paid');
        $principalRemaining = max(0, (float) $loans->sum('principal_balance'));
        $months = collect(range(5, 0))->map(function (int $offset) use ($confirmed): array {
            $month = today()->startOfMonth()->subMonthsNoOverflow($offset);

            return [
                'label' => $month->format('M'),
                'period' => $month->format('F Y'),
                'amount' => (float) $confirmed->filter(fn (LoanRepayment $repayment): bool => $repayment->paid_at?->format('Y-m') === $month->format('Y-m'))->sum('amount'),
            ];
        });

        return [
            'installments' => $installments,
            'next' => $installments->first(),
            'overdue' => (float) $overdue->sum(fn (array $item): float => (float) $item['schedule']->outstanding_amount),
            'status' => $overdue->isNotEmpty() ? 'Repayment overdue' : ($operational->isNotEmpty() ? 'Active loan' : ($loans->contains(fn (Loan $loan): bool => ! in_array($loan->status, ['fully_paid', 'closed', 'rejected', 'cancelled', 'written_off'], true)) ? 'Application in progress' : 'No active repayments')),
            'principal_paid' => $principalPaid,
            'principal_remaining' => $principalRemaining,
            'percentage' => $principalPaid + $principalRemaining > 0 ? min(100, round(100 * $principalPaid / ($principalPaid + $principalRemaining))) : 0,
            'months' => $months,
            'maximum' => max(1, (float) $months->max('amount')),
        ];
    }
}
