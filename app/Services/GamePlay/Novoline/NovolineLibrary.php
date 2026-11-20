<?php

namespace App\Services\GamePlay\Novoline;

use App\Models\NovolineRound;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Picks the recorded Novoline round a spin is served from.
 *
 * The library's own return is Greentube's (rounds drawn uniformly reproduce its
 * distribution). Our controls sit on top, as for Play'n GO: a winning draw is
 * swapped for a losing round often enough to bring the return down to the
 * shop's target, and a draw paying more than the bank (or the max-win cap)
 * can cover is swapped for one that fits — never the other way round, so a
 * target above the library's return is capped at it.
 */
class NovolineLibrary
{
    /** @return array{rounds: int, rtp: float, max_cents: int, features: int} */
    public function stats(string $game): array
    {
        return Cache::remember("novoline:library:{$game}", 600, function () use ($game) {
            $q = NovolineRound::query()->where('game_key', $game);
            $staked = (float) (clone $q)->sum('bet_cents');

            return [
                'rounds' => (clone $q)->count(),
                'rtp' => $staked > 0 ? 100 * (float) (clone $q)->sum('win_cents') / $staked : 0.0,
                'max_cents' => (int) (clone $q)->max('win_cents'),
                'features' => (clone $q)->where('feature', true)->count(),
            ];
        });
    }

    public function forget(string $game): void
    {
        Cache::forget("novoline:library:{$game}");
    }

    /** A round for one spin — see the class doc. `$maxCents` is at the recorded (level 1) scale. */
    public function pick(string $game, float $rtpTarget, int $maxCents): NovolineRound
    {
        $stats = $this->stats($game);
        if ($stats['rounds'] === 0) {
            throw new RuntimeException("No recorded rounds for Novoline game '{$game}' — run novoline:sample first");
        }
        // testing aid: a round forced for this game (tinker: Cache::put("novoline:force:<game>", <id>, 300))
        if ($forced = Cache::pull("novoline:force:{$game}")) {
            return NovolineRound::query()->findOrFail($forced);
        }
        $round = $this->random($game);
        $keep = $stats['rtp'] > 0 ? min(1.0, $rtpTarget / $stats['rtp']) : 1.0;
        if ($round->win_cents > 0 && mt_rand() / mt_getrandmax() > $keep) {
            $round = $this->random($game, 0) ?? $round;
        }
        if ($round->win_cents > $maxCents) {
            $round = $this->random($game, $maxCents) ?? $this->random($game, 0) ?? $round;
        }

        return $round;
    }

    private function random(string $game, ?int $maxCents = null): ?NovolineRound
    {
        $q = NovolineRound::query()->where('game_key', $game);
        if ($maxCents !== null) {
            $q->where('win_cents', '<=', $maxCents);
        }
        $n = (clone $q)->count();

        if ($n === 0) {
            return null;
        }
        // the id alone: sorting whole rows (their JSON steps) runs MySQL out of sort memory
        $id = $q->orderBy('id')->offset(random_int(0, $n - 1))->value('id');

        return NovolineRound::query()->find($id);
    }
}
