<?php

namespace App\Services;

use App\Models\ExchangeRate;
use DomainException;
use Illuminate\Support\Facades\Cache;

class ExchangeRateService
{
    public function rateMicro(string $baseCurrency, string $quoteCurrency): int
    {
        $baseCurrency = strtoupper($baseCurrency);
        $quoteCurrency = strtoupper($quoteCurrency);
        $key = "exchange-rate:{$baseCurrency}:{$quoteCurrency}";

        return (int) Cache::remember($key, now()->addSeconds(30), function () use ($baseCurrency, $quoteCurrency): int {
            $rate = ExchangeRate::query()
                ->where('base_currency', $baseCurrency)
                ->where('quote_currency', $quoteCurrency)
                ->where('is_active', true)
                ->first();

            if ($rate === null) {
                throw new DomainException("No active {$baseCurrency}/{$quoteCurrency} exchange rate is configured.");
            }

            return $rate->rate_micro;
        });
    }
}
