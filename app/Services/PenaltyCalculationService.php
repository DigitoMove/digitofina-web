<?php

namespace App\Services;

use App\Models\LoanSchedule;
use Carbon\CarbonInterface;

class PenaltyCalculationService
{
    public function calculate(LoanSchedule $schedule, float $rate, string $type = 'percentage', int $daysOverdue = 0, ?string $repaymentFrequency = null): float
    {
        $balance = (float) $schedule->outstanding_amount;

        return match ($type) {
            'fixed' => round($rate, 2),
            'daily', 'weekly', 'monthly' => round($balance * ($rate / 100), 2),
            default => round($balance * ($rate / 100), 2),
        };
    }

    public function penaltyDate(LoanSchedule $schedule, CarbonInterface $asOf, string $type, ?string $repaymentFrequency = null): CarbonInterface
    {
        return match ($this->effectiveFrequency($type, $repaymentFrequency)) {
            'daily' => $asOf->copy()->startOfDay(),
            'weekly' => $asOf->copy()->startOfWeek(),
            'monthly' => $asOf->copy()->startOfMonth(),
            default => $asOf->copy()->startOfDay(),
        };
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public function penaltyPeriod(LoanSchedule $schedule, CarbonInterface $asOf, string $type, ?string $repaymentFrequency = null): array
    {
        return match ($this->effectiveFrequency($type, $repaymentFrequency)) {
            'daily' => [$asOf->copy()->startOfDay(), $asOf->copy()->endOfDay()],
            'weekly' => [$asOf->copy()->startOfWeek(), $asOf->copy()->endOfWeek()],
            'monthly' => [$asOf->copy()->startOfMonth(), $asOf->copy()->endOfMonth()],
            default => [$asOf->copy()->startOfDay(), $asOf->copy()->endOfDay()],
        };
    }

    public function effectiveFrequency(string $type, ?string $repaymentFrequency = null): string
    {
        if (in_array($type, ['daily', 'weekly', 'monthly'], true)) {
            return $type;
        }

        return match ($repaymentFrequency) {
            'daily' => 'daily',
            'weekly', 'bi-weekly' => 'weekly',
            default => 'monthly',
        };
    }
}
