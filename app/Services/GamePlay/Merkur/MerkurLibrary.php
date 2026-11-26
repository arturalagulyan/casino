<?php

namespace App\Services\GamePlay\Merkur;

use App\Models\MerkurRound;
use App\Services\GamePlay\ReturnSteering;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Picks the recorded Merkur round a spin is served from.
 *
 * The library's own return is Merkur's (rounds drawn uniformly reproduce its
 * distribution). Our controls sit on top: {@see ReturnSteering} steers
 * it to the shop's target both ways, and a draw paying more than the bank (or
 * the max-win cap) can cover is then swapped for one that fits.
 */
class MerkurLibrary
{
    /** @return array{rounds: int, rtp: float, rare_rtp: float, hit: float, max_cents: int, features: int} */
    public function stats(string $game): array
    {
        return Cache::remember("merkur:library:{$game}", 600, function () use ($game) {
            $q = MerkurRound::query()->where('game_key', $game);
            $staked = (float) (clone $q)->sum('bet_cents');

            return [
                'rounds' => (clone $q)->count(),
                'rtp' => $staked > 0 ? 100 * (float) (clone $q)->sum('win_cents') / $staked : 0.0,
                'max_cents' => (int) (clone $q)->max('win_cents'),
                'features' => (clone $q)->where('feature', true)->count(),
            ] + ReturnSteering::stats(clone $q, 'win_cents', 'bet_cents');
        });
    }

    public function forget(string $game): void
    {
        Cache::forget("merkur:library:{$game}");
    }

    /** A round for one spin — see the class doc. `$maxCents` is at the recorded (level 1) scale. */
    public function pick(string $game, float $rtpTarget, int $maxCents): MerkurRound
    {
        $stats = $this->stats($game);
        if ($stats['rounds'] === 0) {
            throw new RuntimeException("No recorded rounds for Merkur game '{$game}' — run merkur:sample first");
        }
        // testing aid: a round forced for this game (tinker: Cache::put("merkur:force:<game>", <id>, 300))
        if ($forced = Cache::pull("merkur:force:{$game}")) {
            return MerkurRound::query()->findOrFail($forced);
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

    private function random(string $game, ?int $maxCents = null, bool $winning = false): ?MerkurRound
    {
        $q = MerkurRound::query()->where('game_key', $game);
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

        return MerkurRound::query()->find($id);
    }
}
