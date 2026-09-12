<?php

namespace App;

class CurrencyRegistry
{
    /** @return array<string, array{name: string, symbol: string, region: string}> */
    public static function all(): array
    {
        return [
            'UGX' => ['name' => 'Ugandan Shilling', 'symbol' => 'USh', 'region' => 'East Africa'],
            'KES' => ['name' => 'Kenyan Shilling', 'symbol' => 'KSh', 'region' => 'East Africa'],
            'TZS' => ['name' => 'Tanzanian Shilling', 'symbol' => 'TSh', 'region' => 'East Africa'],
            'RWF' => ['name' => 'Rwandan Franc', 'symbol' => 'RF', 'region' => 'East Africa'],
            'BIF' => ['name' => 'Burundian Franc', 'symbol' => 'FBu', 'region' => 'East Africa'],
            'ETB' => ['name' => 'Ethiopian Birr', 'symbol' => 'Br', 'region' => 'Africa'],
            'NGN' => ['name' => 'Nigerian Naira', 'symbol' => '₦', 'region' => 'Africa'],
            'GHS' => ['name' => 'Ghanaian Cedi', 'symbol' => 'GH₵', 'region' => 'Africa'],
            'ZAR' => ['name' => 'South African Rand', 'symbol' => 'R', 'region' => 'Africa'],
            'CDF' => ['name' => 'Congolese Franc', 'symbol' => 'FC', 'region' => 'Africa'],
            'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'region' => 'North America'],
            'CAD' => ['name' => 'Canadian Dollar', 'symbol' => 'CA$', 'region' => 'North America'],
            'EUR' => ['name' => 'Euro', 'symbol' => '€', 'region' => 'Europe'],
            'GBP' => ['name' => 'British Pound', 'symbol' => '£', 'region' => 'Europe'],
            'CHF' => ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'region' => 'Europe'],
            'INR' => ['name' => 'Indian Rupee', 'symbol' => '₹', 'region' => 'Asia'],
            'CNY' => ['name' => 'Chinese Yuan', 'symbol' => '¥', 'region' => 'Asia'],
            'JPY' => ['name' => 'Japanese Yen', 'symbol' => '¥', 'region' => 'Asia'],
            'AED' => ['name' => 'UAE Dirham', 'symbol' => 'د.إ', 'region' => 'Middle East'],
            'AUD' => ['name' => 'Australian Dollar', 'symbol' => 'A$', 'region' => 'Oceania'],
        ];
    }

    public static function supports(string $currency): bool
    {
        return array_key_exists(strtoupper($currency), self::all());
    }
}
