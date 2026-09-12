<?php

namespace App;

use App\Models\CurrencyRate;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CurrencyRateService
{
    public function rate(string $base, string $quote): float
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);
        if ($base === $quote) {
            return 1.0;
        }

        $direct = CurrencyRate::query()->where(['base_currency' => $base, 'quote_currency' => $quote])->value('rate');
        if ($direct !== null) {
            return (float) $direct;
        }

        $inverse = CurrencyRate::query()->where(['base_currency' => $quote, 'quote_currency' => $base])->value('rate');
        if ((float) $inverse > 0) {
            return 1 / (float) $inverse;
        }

        throw new RuntimeException("No exchange rate configured for {$base} to {$quote}.");
    }

    public function convert(float $amount, string $base, string $quote): float
    {
        return $amount * $this->rate($base, $quote);
    }

    public function refresh(string $base): int
    {
        $base = strtoupper($base);
        $rates = Http::timeout(10)->get("https://open.er-api.com/v6/latest/{$base}")->throw()->json('rates', []);
        $count = 0;
        foreach ($rates as $quote => $rate) {
            if (CurrencyRegistry::supports($quote) && is_numeric($rate) && (float) $rate > 0 && $quote !== $base) {
                CurrencyRate::query()->updateOrCreate(
                    ['base_currency' => $base, 'quote_currency' => $quote],
                    ['rate' => $rate, 'source' => 'open.er-api.com', 'fetched_at' => now()],
                );
                $count++;
            }
        }

        return $count;
    }
}
