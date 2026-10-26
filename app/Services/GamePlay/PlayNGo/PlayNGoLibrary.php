<?php

namespace App\Services\GamePlay\PlayNGo;

use App\Models\PlayNGoRound;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Picks the recorded Play'n GO round a spin is served from.
 *
 * The library's own return is Play'n GO's (rounds drawn uniformly reproduce
 * its distribution). Our controls sit on top: a winning draw is swapped for a
 * losing round often enough to bring the return down to the shop's target,
 * and a draw paying more than the bank (or the max-win cap) can cover is
 * swapped for a round that fits — never the other way round, so a target
 * above the library's return is simply capped at it.
 */
class PlayNGoLibrary
{
    /** @return array{rounds: int, rtp: float, max_coins: int} the library's size, return (% of stake) and top win */
    public function stats(string $gid): array
    {
        return Cache::remember("playngo:library:{$gid}", 600, function () use ($gid) {
            $q = PlayNGoRound::query()->where('game_key', $gid);
            $rounds = (clone $q)->count();
            $staked = (float) (clone $q)->sum('bet_coins');

            return [
                'rounds' => $rounds,
                'rtp' => $staked > 0 ? 100 * (float) (clone $q)->sum('win_coins') / $staked : 0.0,
                'max_coins' => (int) (clone $q)->max('win_coins'),
            ];
        });
    }

    /**
     * A round for one spin: drawn uniformly, then swapped for a losing round
     * with the probability that brings the library's return to `$rtpTarget`,
     * and for a cheaper round when it pays more than `$maxCoins`.
     */
    public function pick(string $gid, float $rtpTarget, int $maxCoins): PlayNGoRound
    {
        $stats = $this->stats($gid);
        if ($stats['rounds'] === 0) {
            throw new RuntimeException("No recorded rounds for Play'n GO game '{$gid}' — run playngo:sample first");
        }
        // testing aid: a round forced for this game (tinker: Cache::put("playngo:force:<gid>", <id>, 300))
        if ($forced = Cache::pull("playngo:force:{$gid}")) {
            return PlayNGoRound::query()->findOrFail($forced);
        }
        $round = $this->random($gid);
        $keep = $stats['rtp'] > 0 ? min(1.0, $rtpTarget / $stats['rtp']) : 1.0;
        if ($round->win_coins > 0 && mt_rand() / mt_getrandmax() > $keep) {
            $round = $this->random($gid, 0) ?? $round;
        }
        if ($round->win_coins > $maxCoins) {
            $round = $this->random($gid, $maxCoins) ?? $this->random($gid, 0) ?? $round;
        }

        return $round;
    }

    /** A uniformly drawn round, optionally among those paying at most `$maxCoins`. */
    private function random(string $gid, ?int $maxCoins = null): ?PlayNGoRound
    {
        $q = PlayNGoRound::query()->where('game_key', $gid);
        if ($maxCoins !== null) {
            $q->where('win_coins', '<=', $maxCoins);
        }
        $n = (clone $q)->count();

        return $n === 0 ? null : $q->orderBy('id')->offset(random_int(0, $n - 1))->first();
    }
}
