<?php

namespace App\Services\GamePlay\Egt;

use App\Models\EgtRound;
use App\Services\GamePlay\ReturnSteering;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Picks the recorded EGT round a spin is served from.
 *
 * The library's own return is EGT's (rounds drawn uniformly reproduce its
 * distribution). Our controls sit on top: {@see ReturnSteering} steers
 * it to the shop's target both ways, and a draw paying more than the bank (or
 * the max-win cap) can cover is then swapped for one that fits.
 */
class EgtLibrary
{
    /** @return array{rounds: int, rtp: float, rare_rtp: float, hit: float, max_cents: int, features: int} */
    public function stats(int $game): array
    {
        return Cache::remember("egt:library:{$game}", 600, function () use ($game) {
            $q = EgtRound::query()->where('gin', $game);
            $staked = (float) (clone $q)->sum('bet_cents');

            return [
                'rounds' => (clone $q)->count(),
                'rtp' => $staked > 0 ? 100 * (float) (clone $q)->sum('win_cents') / $staked : 0.0,
                'max_cents' => (int) (clone $q)->max('win_cents'),
                'features' => (clone $q)->where('feature', true)->count(),
            ] + ReturnSteering::stats(clone $q, 'win_cents', 'bet_cents');
        });
    }

    public function forget(int $game): void
    {
        Cache::forget("egt:library:{$game}");
    }

    /** A round for one spin — see the class doc. `$maxCents` is at the recorded (level 1) scale. */
    public function pick(int $game, float $rtpTarget, int $maxCents): EgtRound
    {
        $stats = $this->stats($game);
        if ($stats['rounds'] === 0) {
            throw new RuntimeException("No recorded rounds for EGT game '{$game}' — run egt:sample first");
        }
        // testing aid: a round forced for this game (tinker: Cache::put("egt:force:<game>", <id>, 300))
        if ($forced = Cache::pull("egt:force:{$game}")) {
            return EgtRound::query()->findOrFail($forced);
        }
        $round = $this->random($game);
        $round = match (ReturnSteering::decide($stats, $rtpTarget, $round->win_cents, $round->bet_cents)) {
            ReturnSteering::LOSE => $this->random($game, 0) ?? $round,
            ReturnSteering::WIN => $this->random($game, null, true) ?? $round,
            default => $round,
        };
        if ($round->win_cents > $maxCents) {
            $round = $this->random($game, $maxCents) ?? $this->random($game, 0) ?? $round;
        }

        return $round;
    }

    private function random(int $game, ?int $maxCents = null, bool $winning = false): ?EgtRound
    {
        $q = EgtRound::query()->where('gin', $game);
        if ($maxCents !== null) {
            $q->where('win_cents', '<=', $maxCents);
        }
        if ($winning) {
            ReturnSteering::ordinaryWins($q, 'win_cents', 'bet_cents');
        }
        $n = (clone $q)->count();

        if ($n === 0) {
            return null;
        }
        // the id alone: sorting whole rows (their JSON steps) runs MySQL out of sort memory
        $id = $q->orderBy('id')->offset(random_int(0, $n - 1))->value('id');

        return EgtRound::query()->find($id);
    }
}
