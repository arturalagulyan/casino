<?php

namespace App\Support;

use App\Enums\Currency;

/**
 * Money formatting that tolerates the legacy currency set — crypto (BTC/mBTC)
 * and non-ISO-4217 codes (CFA, ALL used as "lek") that PHP intl / Filament's
 * ->money() choke on.
 */
class Money
{
    public static function format(int|float|string|null $amount, Currency|string|null $currency = null): string
    {
        $currency = self::currency($currency);
        $value = (float) $amount;
        $sign = $value < 0 ? '-' : '';

        $number = number_format(abs($value), $currency->decimals(), '.', ',');

        return $currency->isCrypto()
            ? "{$sign}{$number} {$currency->symbol()}"
            : "{$sign}{$currency->symbol()}{$number}";
    }

    public static function currency(Currency|string|null $currency): Currency
    {
        if ($currency instanceof Currency) {
            return $currency;
        }

        return Currency::tryFrom((string) $currency) ?? Currency::default();
    }
}
