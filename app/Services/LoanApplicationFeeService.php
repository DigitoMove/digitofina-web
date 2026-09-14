<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\SystemSetting;
use App\Support\CurrencyDenomination;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class LoanApplicationFeeService
{
    public function feeForLoan(Loan $loan): float
    {
        return $loan->application_fee_override !== null
            ? (float) $loan->application_fee_override
            : $this->feeFor((float) $loan->requested_amount, $loan->organization_id);
    }

    public function feeFor(float $amount, ?int $organizationId = null): float
    {
        CurrencyDenomination::assertValid($amount, 'Loan amount');

        $range = $this->ranges($organizationId)
            ->first(fn (array $range): bool => $amount >= (float) $range['min'] && ($range['max'] === null || $amount <= (float) $range['max']));

        if ($range) {
            if ($range['fee_type'] === 'percentage') {
                return round($amount * $range['fee'] / CurrencyDenomination::MINIMUM_UNIT, 0, PHP_ROUND_HALF_UP) * CurrencyDenomination::MINIMUM_UNIT;
            }

            return (float) $range['fee'];
        }

        return (float) SystemSetting::value('loan_application_fee', 0, $organizationId);
    }

    /**
     * @return Collection<int, array{min:float,max:float|null,fee:float,fee_type:string}>
     */
    public function ranges(?int $organizationId = null): Collection
    {
        $value = SystemSetting::value('loan_application_fee_ranges', '[]', $organizationId);
        $decoded = json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return collect();
        }

        return collect($decoded)
            ->filter(fn (mixed $range): bool => is_array($range) && isset($range['min'], $range['fee']))
            ->map(fn (array $range): array => [
                'min' => (float) $range['min'],
                'max' => array_key_exists('max', $range) && $range['max'] !== null && $range['max'] !== '' ? (float) $range['max'] : null,
                'fee' => (float) $range['fee'],
                'fee_type' => $range['fee_type'] ?? 'fixed',
            ])
            ->sortBy('min')
            ->values();
    }

    public function assertValidJson(?string $value): void
    {
        if (blank($value)) {
            return;
        }

        $ranges = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($ranges) || ! array_is_list($ranges)) {
            throw new InvalidArgumentException('Loan application fee ranges must be a JSON list of ranges.');
        }

        $normalized = collect();

        foreach ($ranges as $index => $range) {
            if (! is_array($range) || ! isset($range['min'], $range['fee'])) {
                throw new InvalidArgumentException('Fee range #'.($index + 1).' must include min and fee.');
            }

            if (! is_numeric($range['min']) || ! is_numeric($range['fee'])) {
                throw new InvalidArgumentException('Fee range #'.($index + 1).' minimum and fee must be numeric.');
            }

            CurrencyDenomination::assertValid($range['min'], 'Fee range #'.($index + 1).' minimum amount');
            $feeType = array_key_exists('fee_type', $range) ? $range['fee_type'] : 'fixed';

            if (! in_array($feeType, ['fixed', 'percentage'], true)) {
                throw new InvalidArgumentException('Fee range #'.($index + 1).' fee_type must be fixed or percentage.');
            }

            if ($feeType === 'percentage') {
                if ((float) $range['fee'] < 0 || (float) $range['fee'] > 1) {
                    throw new InvalidArgumentException('Fee range #'.($index + 1).' percentage fee must be between 0 and 1 (use 0.01 for 1%).');
                }
            } elseif ((float) $range['fee'] !== 0.0) {
                CurrencyDenomination::assertValid($range['fee'], 'Fee range #'.($index + 1).' fee');
            }

            $max = null;
            if (array_key_exists('max', $range) && filled($range['max'])) {
                if (! is_numeric($range['max'])) {
                    throw new InvalidArgumentException('Fee range #'.($index + 1).' maximum must be numeric or null.');
                }

                CurrencyDenomination::assertValid($range['max'], 'Fee range #'.($index + 1).' maximum amount');

                if ((float) $range['max'] < (float) $range['min']) {
                    throw new InvalidArgumentException('Fee range #'.($index + 1).' maximum must be greater than minimum.');
                }

                $max = (float) $range['max'];
            }

            $normalized->push([
                'index' => $index + 1,
                'min' => (float) $range['min'],
                'max' => $max,
            ]);
        }

        $previous = null;

        foreach ($normalized->sortBy('min')->values() as $range) {
            if ($previous && $previous['max'] !== null && $range['min'] <= $previous['max']) {
                throw new InvalidArgumentException("Fee range #{$range['index']} overlaps fee range #{$previous['index']}.");
            }

            if ($previous && $previous['max'] === null) {
                throw new InvalidArgumentException("Fee range #{$previous['index']} is open-ended and must be the final range.");
            }

            $previous = $range;
        }
    }
}
