<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class TransactionTimestamp
{
    public static function forBusinessDate(?CarbonInterface $businessDate = null, ?CarbonInterface $recordedAt = null): CarbonImmutable
    {
        $recordedAt = $recordedAt
            ? CarbonImmutable::instance($recordedAt)
            : CarbonImmutable::now();

        if ($businessDate === null) {
            return $recordedAt;
        }

        $businessDate = CarbonImmutable::instance($businessDate);

        return $businessDate->setTime(
            $recordedAt->hour,
            $recordedAt->minute,
            $recordedAt->second,
            $recordedAt->microsecond,
        );
    }
}
