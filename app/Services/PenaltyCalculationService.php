<?php

namespace App\Services;

use App\Models\LoanSchedule;
use App\Support\CurrencyDenomination;
use Carbon\CarbonInterface;

class PenaltyCalculationService
{
    public function calculate(LoanSchedule $schedule, float $rate, string $type = 'percentage', int $daysOverdue = 0, ?string $repaymentFrequency = null): float
    {
        $balance = max(0, (float) $schedule->outstanding_amount - (float) $schedule->penalty_due);
        $chargeableDays = max(1, $daysOverdue);

        $amount = match ($type) {
            'fixed' => $rate,
            default => $balance * ($rate / 100) * $chargeableDays,
        };

        return $this->roundToCurrencyUnit($amount);
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

    private function roundToCurrencyUnit(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        return (float) (ceil($amount / CurrencyDenomination::MINIMUM_UNIT) * CurrencyDenomination::MINIMUM_UNIT);
    }
}
