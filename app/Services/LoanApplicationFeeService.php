<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Support\CurrencyDenomination;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class LoanApplicationFeeService
{
    public function feeFor(float $amount, ?int $organizationId = null): float
    {
        CurrencyDenomination::assertValid($amount, 'Loan amount');

        $range = $this->ranges($organizationId)
            ->first(fn (array $range): bool => $amount >= (float) $range['min'] && ($range['max'] === null || $amount <= (float) $range['max']));

        if ($range) {
            return (float) $range['fee'];
        }

        return (float) SystemSetting::value('loan_application_fee', 0, $organizationId);
    }

    /**
     * @return Collection<int, array{min:float,max:float|null,fee:float}>
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

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($ranges)) {
            throw new InvalidArgumentException('Loan application fee ranges must be valid JSON.');
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
            CurrencyDenomination::assertValid($range['fee'], 'Fee range #'.($index + 1).' fee');

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
