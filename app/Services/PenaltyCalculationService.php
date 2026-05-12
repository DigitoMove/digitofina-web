<?php

namespace App\Services;

use App\Models\LoanSchedule;

class PenaltyCalculationService
{
    public function calculate(LoanSchedule $schedule, float $rate, string $type = 'percentage', int $daysOverdue = 0): float
    {
        $balance = (float) $schedule->outstanding_amount;

        return match ($type) {
            'fixed' => round($rate, 2),
            'daily' => round($balance * ($rate / 100) * max(1, $daysOverdue), 2),
            default => round($balance * ($rate / 100), 2),
        };
    }
}
