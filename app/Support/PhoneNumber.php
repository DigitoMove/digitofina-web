<?php

namespace App\Support;

use Illuminate\Support\Str;

class PhoneNumber
{
    public static function normalize(string $phone): string
    {
        $digits = Str::of($phone)->replaceMatches('/\D+/', '')->toString();

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '256'.substr($digits, 1);
        }

        return $digits;
    }

    /** @return array<int, string> */
    public static function variants(string $phone): array
    {
        $normalized = self::normalize($phone);
        $variants = [$phone, $normalized, '+'.$normalized];

        if (str_starts_with($normalized, '256') && strlen($normalized) === 12) {
            $variants[] = '0'.substr($normalized, 3);
        }

        return array_values(array_unique($variants));
    }
}
