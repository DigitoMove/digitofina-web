<?php

namespace App\Services;

use Carbon\CarbonInterface;

class LoanScheduleService
{
    public function __construct(private LoanCalculatorService $calculator) {}

    /**
     * @return array<int, array{installment:int, due_date:string, principal:float, interest:float, total:float, balance:float}>
     */
    public function preview(float $principal, float $periodicRate, int $installments, string $method, CarbonInterface $startDate, string $frequency = 'monthly'): array
    {
        $rows = match ($method) {
            'flat' => $this->calculator->flatRate($principal, $periodicRate, $installments),
            'declining_balance' => $this->calculator->decliningBalance($principal, $periodicRate, $installments),
            default => $this->calculator->reducingBalanceEqualInstallments($principal, $periodicRate, $installments),
        };

        return array_map(function (array $row) use ($startDate, $frequency): array {
            $dueDate = match ($frequency) {
                'daily' => $startDate->copy()->addDays($row['installment']),
                'weekly' => $startDate->copy()->addWeeks($row['installment']),
                'bi_weekly' => $startDate->copy()->addWeeks($row['installment'] * 2),
                'quarterly' => $startDate->copy()->addMonths($row['installment'] * 3),
                default => $startDate->copy()->addMonths($row['installment']),
            };

            return $row + ['due_date' => $dueDate->toDateString()];
        }, $rows);
    }
}
