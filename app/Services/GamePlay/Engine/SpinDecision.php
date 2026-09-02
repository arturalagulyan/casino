<?php

namespace App\Services\GamePlay\Engine;

/** What the SpinDecider decided this spin should be. */
class SpinDecision
{
    public function __construct(
        /** 'none' | 'win' | 'bonus' */
        public string $type,
        /** ceiling the win may reach (bank affordability) */
        public float $budget = 0.0,
        /** max-win multiplier in force this spin (RTP correction can shrink it) */
        public float $maxWinMultiplier = 0.0,
        /** effective 1/N spin-win chance used (after the feedback loop) */
        public int $spinChance = 8,
        /** target RTP % the engine is steering toward */
        public float $targetRtp = 90.0,
        /** 1.0 = pay freely; → 0 = clamp wins hard (game is ahead of target RTP) */
        public float $winScale = 1.0,
    ) {}

    public function isWin(): bool
    {
        return $this->type !== 'none';
    }
}
