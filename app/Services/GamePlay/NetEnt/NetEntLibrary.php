<?php

namespace App\Services\GamePlay\NetEnt;

use App\Models\NetEntRound;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Picks the recorded NetEnt round a spin is served from.
 *
 * The library's own return is NetEnt's (rounds drawn uniformly reproduce its
 * distribution). Our controls sit on top, as for Play'n GO: a winning draw is
 * swapped for a losing round often enough to bring the return down to the
 * shop's target, and a draw paying more than the bank (or the max-win cap)
 * can cover is swapped for one that fits — never the other way round, so a
 * target above the library's return is capped at it.
 */
class NetEntLibrary
{
    /** @return array{rounds: int, rtp: float, max_coins: int, features: int} */
    public function stats(string $game): array
    {
        return Cache::remember("netent:library:{$game}", 600, function () use ($game) {
            $q = NetEntRound::query()->where('game_key', $game);
            $staked = (float) (clone $q)->sum('bet_coins');

            return [
                'rounds' => (clone $q)->count(),
                'rtp' => $staked > 0 ? 100 * (float) (clone $q)->sum('win_coins') / $staked : 0.0,
                'max_coins' => (int) (clone $q)->max('win_coins'),
                'features' => (clone $q)->where('feature', true)->count(),
            ];
        });
    }

    public function forget(string $game): void
    {
        Cache::forget("netent:library:{$game}");
    }

    /** A round for one spin — see the class doc. `$maxCoins` is at the recorded (level 1) scale. */
    public function pick(string $game, float $rtpTarget, int $maxCoins): NetEntRound
    {
        $stats = $this->stats($game);
        if ($stats['rounds'] === 0) {
            throw new RuntimeException("No recorded rounds for NetEnt game '{$game}' — run netent:sample first");
        }
        // testing aid: a round forced for this game (tinker: Cache::put("netent:force:<game>", <id>, 300))
        if ($forced = Cache::pull("netent:force:{$game}")) {
            return NetEntRound::query()->findOrFail($forced);
        }
        $round = $this->random($game);
        $keep = $stats['rtp'] > 0 ? min(1.0, $rtpTarget / $stats['rtp']) : 1.0;
        if ($round->win_coins > 0 && mt_rand() / mt_getrandmax() > $keep) {
            $round = $this->random($game, 0) ?? $round;
        }
        if ($round->win_coins > $maxCoins) {
            $round = $this->random($game, $maxCoins) ?? $this->random($game, 0) ?? $round;
        }

        return $round;
    }

    private function random(string $game, ?int $maxCoins = null): ?NetEntRound
    {
        $q = NetEntRound::query()->where('game_key', $game);
        if ($maxCoins !== null) {
            $q->where('win_coins', '<=', $maxCoins);
        }
        $n = (clone $q)->count();

        return $n === 0 ? null : $q->orderBy('id')->offset(random_int(0, $n - 1))->first();
    }
}
