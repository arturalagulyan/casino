<?php

namespace App\Services\GamePlay\NetEnt;

use App\Models\NetEntRound;
use App\Services\GamePlay\ReturnSteering;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Picks the recorded NetEnt round a spin is served from.
 *
 * The library's own return is NetEnt's (rounds drawn uniformly reproduce its
 * distribution). Our controls sit on top: {@see ReturnSteering} steers
 * it to the shop's target both ways, and a draw paying more than the bank (or
 * the max-win cap) can cover is then swapped for one that fits.
 */
class NetEntLibrary
{
    /** @return array{rounds: int, rtp: float, rare_rtp: float, hit: float, max_coins: int, features: int} */
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
            ] + ReturnSteering::stats(clone $q, 'win_coins', 'bet_coins');
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
        $round = match (ReturnSteering::decide($stats, $rtpTarget, $round->win_coins, $round->bet_coins)) {
            ReturnSteering::LOSE => $this->random($game, 0) ?? $round,
            ReturnSteering::WIN => $this->random($game, null, true) ?? $round,
            default => $round,
        };
        if ($round->win_coins > $maxCoins) {
            $round = $this->random($game, $maxCoins) ?? $this->random($game, 0) ?? $round;
        }

        return $round;
    }

    private function random(string $game, ?int $maxCoins = null, bool $winning = false): ?NetEntRound
    {
        $q = NetEntRound::query()->where('game_key', $game);
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

        return NetEntRound::query()->find($id);
    }
}
