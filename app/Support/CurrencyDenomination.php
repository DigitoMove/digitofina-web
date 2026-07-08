<?php

namespace App\Support;

use InvalidArgumentException;

class CurrencyDenomination
{
    public const MINIMUM_UNIT = 100;

    public const UGX_DENOMINATIONS = [
        50000 => '50,000 note',
        20000 => '20,000 note',
        10000 => '10,000 note',
        5000 => '5,000 note',
        2000 => '2,000 note',
        1000 => '1,000 note',
        500 => '500 coin',
        200 => '200 coin',
        100 => '100 coin',
    ];

    public static function assertValid(float|int|string $amount, string $field = 'Amount'): void
    {
        $amount = (float) $amount;

        if ($amount < self::MINIMUM_UNIT) {
            throw new InvalidArgumentException("{$field} must be at least UGX ".number_format(self::MINIMUM_UNIT).'.');
        }

        if (fmod($amount, self::MINIMUM_UNIT) !== 0.0) {
            throw new InvalidArgumentException("{$field} must be in multiples of UGX ".number_format(self::MINIMUM_UNIT).'.');
        }
    }

    public static function validationRule(): string
    {
        return 'multiple_of:'.self::MINIMUM_UNIT;
    }

    /**
     * @return array<int, string>
     */
    public static function ugxDenominations(): array
    {
        return self::UGX_DENOMINATIONS;
    }
}
