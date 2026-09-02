<?php

namespace App\Enums;

/**
 * How swingy a game's payouts are. Drives the win-size distribution and the
 * base hit-rate the engine steers by. Every value here can be overridden
 * per-template via game_templates.win_distribution (JSON).
 */
enum Volatility: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * Win-size + hit-rate shaping for LineSlotServer / GameConfig.
     *
     *  hit_bonus    — scales the base win-chance tables (>1 = hits more often)
     *  small_prob   — share of wins that are "small" (below the mean)
     *  small_floor  — smallest small-win, as a fraction of the mean
     *  small_span   — spread of the small-win band
     *  tail_exp     — steepness of the big-win tail (higher = rarer huge hits)
     *  tail_scale   — how large the biggest hits get, × mean
     *  min_factor   — hard floor on any paid win, × mean
     *  budget_frac  — most of the bank pool a single win may take
     *
     * @return array<string, float>
     */
    public function shape(): array
    {
        return match ($this) {
            self::Low => [
                'hit_bonus' => 1.35, 'small_prob' => 0.80, 'small_floor' => 0.30,
                'small_span' => 0.80, 'tail_exp' => 2.2, 'tail_scale' => 2.2,
                'min_factor' => 0.20, 'budget_frac' => 0.75,
            ],
            self::Medium => [
                'hit_bonus' => 1.00, 'small_prob' => 0.70, 'small_floor' => 0.15,
                'small_span' => 0.85, 'tail_exp' => 2.0, 'tail_scale' => 4.0,
                'min_factor' => 0.10, 'budget_frac' => 0.85,
            ],
            self::High => [
                'hit_bonus' => 0.70, 'small_prob' => 0.55, 'small_floor' => 0.08,
                'small_span' => 0.90, 'tail_exp' => 1.7, 'tail_scale' => 9.0,
                'min_factor' => 0.05, 'budget_frac' => 0.90,
            ],
        };
    }
}
