<?php

namespace App\Services;

use Carbon\CarbonInterface;

class LoanScheduleService
{
    public function __construct(
        private LoanCalculatorService $calculator,
        private BusinessCalendarService $calendar,
    ) {}

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
            $dueDate = $this->calendar->dueDate($startDate, $row['installment'], $frequency);

            return $row + ['due_date' => $dueDate->toDateString()];
        }, $rows);
    }
}
