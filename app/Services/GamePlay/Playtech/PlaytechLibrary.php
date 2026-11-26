<?php

namespace App\Services\GamePlay\Playtech;

use App\Models\PlaytechRound;
use App\Services\GamePlay\ReturnSteering;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Picks the recorded Playtech round a spin is served from.
 *
 * The library's own return is Playtech's (rounds drawn uniformly reproduce its
 * distribution). Our controls sit on top: {@see ReturnSteering} steers
 * it to the shop's target both ways, and a draw paying more than the bank (or
 * the max-win cap) can cover is then swapped for one that fits.
 */
class PlaytechLibrary
{
    /** @return array{rounds: int, rtp: float, rare_rtp: float, hit: float, max_cents: int, features: int} */
    public function stats(string $game): array
    {
        return Cache::remember("playtech:library:{$game}", 600, function () use ($game) {
            $q = PlaytechRound::query()->where('game_key', $game);
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
        Cache::forget("playtech:library:{$game}");
    }

    /**
     * Games that carry progress from round to round (Batman Begins: a stage — temple, manor … — and
     * two symbol counts; a count reaching 4 moves the game to another stage, both counts back to 0):
     * tag each round with the state it was played from (`stage`, e.g. "temple|1,3") and the state it
     * left (`next_stage`). Rounds of one demo session follow on in credit; a fresh session starts
     * from the login's state (its first stage, counts 0).
     *
     * @return array<string, int> rounds per stage
     */
    public function annotateStages(string $game): array
    {
        $count = [];
        $prev = null;   // [round, its after-state, its stage, its counts]
        foreach (PlaytechRound::query()->where('game_key', $game)->orderBy('id')->select(['id', 'bet_cents', 'win_cents', 'start_credit', 'steps', 'stage', 'next_stage'])->lazyById(500) as $round) {
            $name = $counts = null;
            foreach ($round->steps as $step) {
                foreach ((array) ($step['m'] ?? []) as $m) {
                    $name ??= is_string($m['data']['featureName'] ?? null) ? $m['data']['featureName'] : null;
                    if (is_array($m['data']['collectableSymbols'] ?? null)) {
                        $counts = array_map(fn ($c) => (int) ($c['count'] ?? 0), $m['data']['collectableSymbols']);
                    }
                }
            }
            if ($name === null) {   // a game without stages
                $prev = null;

                continue;
            }
            $count[$name] = ($count[$name] ?? 0) + 1;
            $followsOn = $prev !== null && $prev[0]->start_credit - $prev[0]->bet_cents + $prev[0]->win_cents === $round->start_credit;
            if ($prev !== null) {
                // a stage completed: the state left is the next round's stage, counts reset
                $after = $prev[1] ?? ($followsOn ? self::state($name, array_fill(0, count($prev[3] ?? [0, 0]), 0)) : null);
                $this->tag($prev[0], $prev[0]->stage, $after);
                $from = $followsOn ? $after : null;
            } else {
                $from = null;
            }
            $from ??= self::state($name, array_fill(0, count($counts ?? [0, 0]), 0));   // a session's first round: from the login
            $round->stage = $from;
            $done = $counts !== null && max($counts) >= 4;
            $prev = [$round, $done ? null : self::state($name, $counts ?? []), $name, $counts];
        }
        if ($prev !== null) {
            $this->tag($prev[0], $prev[0]->stage, $prev[1]);
        }

        return $count;
    }

    /** "temple|1,3" */
    public static function state(string $stage, array $counts): string
    {
        return $stage.'|'.implode(',', $counts);
    }

    private function tag(PlaytechRound $round, ?string $from, ?string $to): void
    {
        PlaytechRound::query()->whereKey($round->id)->update(['stage' => $from, 'next_stage' => $to]);
    }

    /**
     * A round for one spin — see the class doc. `$maxCents` is at the recorded (coin 1) scale;
     * `$stage`: only rounds of the stage the player is on (games that have stages).
     */
    public function pick(string $game, float $rtpTarget, int $maxCents, ?string $stage = null): PlaytechRound
    {
        $stats = $this->stats($game);
        if ($stats['rounds'] === 0) {
            throw new RuntimeException("No recorded rounds for Playtech game '{$game}' — run playtech:sample first");
        }
        // testing aid: a round forced for this game (tinker: Cache::put("playtech:force:<game>", <id>, 300))
        if ($forced = Cache::pull("playtech:force:{$game}")) {
            return PlaytechRound::query()->findOrFail($forced);
        }
        $round = $this->random($game, null, $stage);
        $round = match (ReturnSteering::decide($stats, $rtpTarget, $round->win_cents, $round->bet_cents)) {
            ReturnSteering::LOSE => $this->random($game, 0, $stage) ?? $round,
            ReturnSteering::WIN => $this->random($game, null, $stage, true) ?? $round,
            default => $round,
        };
        if ($round->win_cents > $maxCents) {
            $round = $this->random($game, $maxCents, $stage) ?? $this->random($game, 0, $stage) ?? $round;
        }

        return $round;
    }

    private function random(string $game, ?int $maxCents = null, ?string $stage = null, bool $winning = false): ?PlaytechRound
    {
        $q = PlaytechRound::query()->where('game_key', $game);
        if ($stage !== null) {
            // rounds played from this state; none recorded (e.g. under a max-win cap): the same stage at least
            $exact = (clone $q)->where('stage', $stage);
            $q = (clone $exact)->when($maxCents !== null, fn ($w) => $w->where('win_cents', '<=', $maxCents))->exists()
                ? $exact
                : $q->where('stage', 'like', strtok($stage, '|').'|%');
        }
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

        return PlaytechRound::query()->find($id);
    }
}
