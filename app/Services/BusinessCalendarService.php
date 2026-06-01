<?php

namespace App\Services;

use App\Models\SystemSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BusinessCalendarService
{
    public function dueDate(CarbonInterface $startDate, int $installment, string $frequency, ?int $organizationId = null): CarbonInterface
    {
        return match ($frequency) {
            'daily' => $this->addBusinessDays($startDate, $installment, $organizationId),
            'weekly' => $this->nextBusinessDay($startDate->copy()->addWeeks($installment), $organizationId),
            'bi-weekly' => $this->nextBusinessDay($startDate->copy()->addWeeks($installment * 2), $organizationId),
            'quarterly' => $this->nextBusinessDay($startDate->copy()->addMonths($installment * 3), $organizationId),
            default => $this->nextBusinessDay($startDate->copy()->addMonthsNoOverflow($installment), $organizationId),
        };
    }

    public function addBusinessDays(CarbonInterface $date, int $days, ?int $organizationId = null): CarbonInterface
    {
        $cursor = $date->copy();
        $added = 0;

        while ($added < $days) {
            $cursor = $cursor->addDay();

            if ($this->isBusinessDay($cursor, $organizationId)) {
                $added++;
            }
        }

        return $cursor;
    }

    public function nextBusinessDay(CarbonInterface $date, ?int $organizationId = null): CarbonInterface
    {
        $cursor = $date->copy();

        while (! $this->isBusinessDay($cursor, $organizationId)) {
            $cursor = $cursor->addDay();
        }

        return $cursor;
    }

    public function businessDaysBetween(CarbonInterface $from, CarbonInterface $to, ?int $organizationId = null): int
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $days = 0;
        $cursor = $start->copy();

        while ($cursor->lessThan($end)) {
            $cursor = $cursor->addDay();

            if ($cursor->lessThanOrEqualTo($end) && $this->isBusinessDay($cursor, $organizationId)) {
                $days++;
            }
        }

        return $days;
    }

    public function isBusinessDay(CarbonInterface $date, ?int $organizationId = null): bool
    {
        return ! $date->isSunday() && ! $this->holidayDates($organizationId)->contains($date->toDateString());
    }

    /**
     * @return Collection<int, string>
     */
    public function holidayDates(?int $organizationId = null): Collection
    {
        $decoded = json_decode((string) SystemSetting::value('repayment_public_holidays', '[]', $organizationId), true);

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
