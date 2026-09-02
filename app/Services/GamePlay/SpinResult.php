<?php

namespace App\Services\GamePlay;

/** Outcome of one spin, produced by a game server and settled by GameContext. */
class SpinResult
{
    /**
     * @param  float  $bet  total stake for this spin (currency units)
     * @param  float  $win  total win (currency units)
     * @param  array  $reels  the reel window shown to the player
     * @param  array  $lines  winning lines [{line, symbol, count, amount}]
     * @param  string  $state  'bet' | 'freespin' | 'bonus' | 'gamble'
     * @param  array  $extra  anything else the frontend needs (freespins left, multiplier…)
     */
    public function __construct(
        public float $bet,
        public float $win = 0.0,
        public array $reels = [],
        public array $lines = [],
        public string $state = 'bet',
        public array $extra = [],
    ) {}

    public function toArray(): array
    {
        return [
            'bet' => round($this->bet, 4),
            'win' => round($this->win, 4),
            'reels' => $this->reels,
            'lines' => $this->lines,
            'state' => $this->state,
        ] + $this->extra;
    }
}
