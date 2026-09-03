<?php

namespace App\Services\GamePlay;

use App\Enums\Currency;
use App\Services\Fx;

/**
 * Scales a game's money knobs from the currency they're priced in (the
 * template's `pricing_currency`, default EUR) into the currency the player is
 * actually betting in.
 *
 * Only the *denomination* moves: `bet_options` stay as fixed "credit" values so
 * the stake `lines × betline × denomination` scales linearly with the FX rate
 * exactly once. A €0.10 denomination becomes ~10 ALL, so a €10 minimum stake
 * lands at ~1000 ALL — the same real money, a sane-looking ladder.
 *
 * The raw FX result is snapped to a 1 / 2 / 5 × 10ⁿ value so bets read cleanly
 * (1017.4 ALL → 1000, not a ragged decimal).
 */
class CurrencyScaler
{
    public function __construct(private readonly Fx $fx) {}

    /** Denomination in `$from`, re-priced into `$to`. No-ops when they match. */
    public function denomination(float $base, Currency $from, ?Currency $to): float
    {
        if ($base <= 0 || $to === null || $to === $from) {
            return $base;
        }

        return $this->snap($this->fx->convert($base, $from, $to), $to);
    }

    /** Snap a positive amount to the nearest 1 / 2 / 5 × 10ⁿ, floored at the currency's minor unit. */
    private function snap(float $value, Currency $currency): float
    {
        $floor = $currency->minorUnit();

        if ($value <= $floor) {
            return $floor;
        }

        $magnitude = 10 ** floor(log10($value));   // largest power of 10 ≤ value
        $normalized = $value / $magnitude;         // 1.0 .. <10.0

        $nice = match (true) {
            $normalized < 1.5 => 1.0,
            $normalized < 3.5 => 2.0,
            $normalized < 7.5 => 5.0,
            default => 10.0,
        };

        return max($floor, round($nice * $magnitude, 8));
    }
}
