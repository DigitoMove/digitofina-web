<?php

namespace App\Services;

use InvalidArgumentException;

class LoanCalculatorService
{
    /**
     * @return array<int, array{installment:int, principal:float, interest:float, total:float, balance:float}>
     */
    public function flatRate(float $principal, float $ratePercent, int $installments): array
    {
        $this->guardInputs($principal, $installments);

        $totalInterest = round($principal * ($ratePercent / 100) * $installments, 2);
        $principalPortion = round($principal / $installments, 2);
        $interestPortion = round($totalInterest / $installments, 2);
        $balance = $principal;
        $schedule = [];

        for ($installment = 1; $installment <= $installments; $installment++) {
            $principalDue = $installment === $installments ? round($balance, 2) : $principalPortion;
            $balance = round($balance - $principalDue, 2);

            $schedule[] = [
                'installment' => $installment,
                'principal' => $principalDue,
                'interest' => $interestPortion,
                'total' => round($principalDue + $interestPortion, 2),
                'balance' => max(0, $balance),
            ];
        }

        return $schedule;
    }

    /**
     * @return array<int, array{installment:int, principal:float, interest:float, total:float, balance:float}>
     */
    public function decliningBalance(float $principal, float $periodicRatePercent, int $installments): array
    {
        $this->guardInputs($principal, $installments);

        $principalPortion = round($principal / $installments, 2);
        $balance = $principal;
        $schedule = [];

        for ($installment = 1; $installment <= $installments; $installment++) {
            $interest = round($balance * ($periodicRatePercent / 100), 2);
            $principalDue = $installment === $installments ? round($balance, 2) : $principalPortion;
            $balance = round($balance - $principalDue, 2);

            $schedule[] = [
                'installment' => $installment,
                'principal' => $principalDue,
                'interest' => $interest,
                'total' => round($principalDue + $interest, 2),
                'balance' => max(0, $balance),
            ];
        }

        return $schedule;
    }

    /**
     * @return array<int, array{installment:int, principal:float, interest:float, total:float, balance:float}>
     */
    public function reducingBalanceEqualInstallments(float $principal, float $periodicRatePercent, int $installments): array
    {
        $this->guardInputs($principal, $installments);

        $periodicRate = $periodicRatePercent / 100;
        $payment = $periodicRate > 0
            ? $principal * ($periodicRate * ((1 + $periodicRate) ** $installments)) / (((1 + $periodicRate) ** $installments) - 1)
            : $principal / $installments;

        $balance = $principal;
        $schedule = [];

        for ($installment = 1; $installment <= $installments; $installment++) {
            $interest = round($balance * $periodicRate, 2);
            $principalDue = round($payment - $interest, 2);

            if ($installment === $installments) {
                $principalDue = round($balance, 2);
                $payment = $principalDue + $interest;
            }

            $balance = round($balance - $principalDue, 2);

            $schedule[] = [
                'installment' => $installment,
                'principal' => $principalDue,
                'interest' => $interest,
                'total' => round($payment, 2),
                'balance' => max(0, $balance),
            ];
        }

        return $schedule;
    }

    private function guardInputs(float $principal, int $installments): void
    {
        if ($principal <= 0) {
            throw new InvalidArgumentException('Principal must be greater than zero.');
        }

        if ($installments <= 0) {
            throw new InvalidArgumentException('Installments must be greater than zero.');
        }
    }
}
