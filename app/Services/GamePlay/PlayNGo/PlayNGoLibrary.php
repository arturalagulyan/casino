<?php

namespace App\Services\GamePlay\PlayNGo;

use App\Models\PlayNGoRound;
use App\Services\GamePlay\ReturnSteering;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Picks the recorded Play'n GO round a spin is served from.
 *
 * The library's own return is Play'n GO's (rounds drawn uniformly reproduce
 * its distribution). Our controls sit on top: {@see ReturnSteering} steers
 * it to the shop's target both ways, and a draw paying more than the bank (or
 * the max-win cap) can cover is then swapped for one that fits.
 */
class PlayNGoLibrary
{
    /** @return array{rounds: int, rtp: float, rare_rtp: float, hit: float, max_coins: int} the library's size, return (% of stake) and top win */
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
            ] + ReturnSteering::stats(clone $q, 'win_coins', 'bet_coins');
        });
    }

    /**
     * A round for one spin: drawn uniformly, then steered to `$rtpTarget`
     * ({@see ReturnSteering}), and swapped for a cheaper round when it pays
     * more than `$maxCoins`.
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
        $round = match (ReturnSteering::decide($stats, $rtpTarget, $round->win_coins, $round->bet_coins)) {
            ReturnSteering::LOSE => $this->random($gid, 0) ?? $round,
            ReturnSteering::WIN => $this->random($gid, null, true) ?? $round,
            default => $round,
        };
        if ($round->win_coins > $maxCoins) {
            $round = $this->random($gid, $maxCoins) ?? $this->random($gid, 0) ?? $round;
        }

        return $round;
    }

    /** A uniformly drawn round, optionally among those paying at most `$maxCoins`. */
    private function random(string $gid, ?int $maxCoins = null, bool $winning = false): ?PlayNGoRound
    {
        $q = PlayNGoRound::query()->where('game_key', $gid);
        if ($maxCoins !== null) {
            $q->where('win_coins', '<=', $maxCoins);
        }
        if ($winning) {
            ReturnSteering::ordinaryWins($q, 'win_coins', 'bet_coins');
        }
        $n = (clone $q)->count();

        if ($n === 0) {
            return null;
        }
        // the id alone: sorting whole rows (their JSON steps) runs MySQL out of sort memory
        $id = $q->orderBy('id')->offset(random_int(0, $n - 1))->value('id');

        return PlayNGoRound::query()->find($id);
    }
}
