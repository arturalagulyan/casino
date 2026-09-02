<?php

namespace App\Enums;

/**
 * The bonus-round mechanics the engine knows how to run. A game's template
 * points each of its trigger symbols at one of these via `bonus_config`; the
 * numbers (free-spin counts, multiplier ranges, money ladders) are all params
 * on that same JSON. No per-game code.
 *
 * @see \App\Services\GamePlay\Bonus\BonusFlow  (the handler for each)
 */
enum BonusFlow: string
{
    /** Nothing — the trigger just pays its scatter award. */
    case None = 'none';

    /** Award a fixed (or per-scatter-count) number of free spins immediately. */
    case FreeSpins = 'free_spins';

    /** Pick a box → multiplier, pick a box → free-spin count (+ extra wild); then free spins. (EGT Action Money, scatter) */
    case PickMultiplierFreeSpins = 'pick_multiplier_freespins';

    /** Pick boxes for cash multipliers of the triggering bet. (EGT Action Money, "bank bonus") */
    case PickMoney = 'pick_money';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::FreeSpins => 'Free spins',
            self::PickMultiplierFreeSpins => 'Pick multiplier → pick free spins',
            self::PickMoney => 'Pick money boxes',
        };
    }
}
