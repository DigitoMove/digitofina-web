<?php

namespace App\Services;

use App\Models\SystemSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BusinessCalendarService
{
    public function dueDate(CarbonInterface $startDate, int $installment, string $frequency): CarbonInterface
    {
        return match ($frequency) {
            'daily' => $this->addBusinessDays($startDate, $installment),
            'weekly' => $this->nextBusinessDay($startDate->copy()->addWeeks($installment)),
            'bi-weekly' => $this->nextBusinessDay($startDate->copy()->addWeeks($installment * 2)),
            'quarterly' => $this->nextBusinessDay($startDate->copy()->addMonths($installment * 3)),
            default => $this->nextBusinessDay($startDate->copy()->addMonths($installment)),
        };
    }

    public function addBusinessDays(CarbonInterface $date, int $days): CarbonInterface
    {
        $cursor = $date->copy();
        $added = 0;

        while ($added < $days) {
            $cursor->addDay();

            if ($this->isBusinessDay($cursor)) {
                $added++;
            }
        }

        return $cursor;
    }

    public function nextBusinessDay(CarbonInterface $date): CarbonInterface
    {
        $cursor = $date->copy();

        while (! $this->isBusinessDay($cursor)) {
            $cursor->addDay();
        }

        return $cursor;
    }

    public function isBusinessDay(CarbonInterface $date): bool
    {
        return ! $date->isSunday() && ! $this->holidayDates()->contains($date->toDateString());
    }

    /**
     * @return Collection<int, string>
     */
    public function holidayDates(): Collection
    {
        $decoded = json_decode((string) SystemSetting::value('repayment_public_holidays', '[]'), true);

        if (! is_array($decoded)) {
            return collect();
        }

        return collect($decoded)
            ->filter(fn (mixed $date): bool => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1)
            ->values();
    }

    public function assertValidHolidayJson(?string $value): void
    {
        if (blank($value)) {
            return;
        }

        $dates = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($dates)) {
            throw new InvalidArgumentException('Public holidays must be a JSON array of dates.');
        }

        foreach ($dates as $index => $date) {
            if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new InvalidArgumentException('Public holiday #'.($index + 1).' must use YYYY-MM-DD format.');
            }
        }
    }
}
