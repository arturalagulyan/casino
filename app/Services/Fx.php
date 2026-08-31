<?php

namespace App\Services;

use App\Enums\Currency;
use App\Models\CurrencyRate;

/**
 * Currency conversion via the `currency_rates` table (rate = units per 1 EUR).
 * Deliberately explicit and opt-in — reports only convert when the operator
 * asks for a single reporting currency; per-currency figures stay un-converted.
 */
class Fx
{
    /** @var array<string,float>|null */
    private ?array $rates = null;

    /** Rate: how many units of $currency == 1 EUR. Unknown currency => 1.0. */
    public function rate(Currency|string $currency): float
    {
        $code = $currency instanceof Currency ? $currency->value : $currency;

        $this->rates ??= CurrencyRate::pluck('rate', 'currency')
            ->map(fn ($r) => (float) $r)
            ->all();

        if ($code === Currency::EUR->value) {
            return 1.0;
        }

        return $this->rates[$code] ?? 1.0;
    }

    public function convert(float $amount, Currency|string $from, Currency|string $to): float
    {
        $fromRate = $this->rate($from);
        $toRate = $this->rate($to);

        if ($fromRate <= 0) {
            return 0.0;
        }

        return $amount / $fromRate * $toRate;
    }

    public function toEur(float $amount, Currency|string $from): float
    {
        return $this->convert($amount, $from, Currency::EUR);
    }

    public function flush(): void
    {
        $this->rates = null;
    }
}
