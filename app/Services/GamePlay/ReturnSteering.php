<?php

namespace App\Services\GamePlay;

use Illuminate\Database\Eloquent\Builder;

/**
 * How a recorded-round library (Apollo, Microgaming, NetEnt, …) steers its
 * return to the shop's RTP target, both ways.
 *
 * A library's own return is the provider's: rounds drawn uniformly reproduce its
 * distribution. A draw is then:
 *
 *   - above the target: a winning draw is swapped for a losing round often
 *     enough to bring the return down to it;
 *   - below the target: a losing draw is swapped for a random (ordinary)
 *     winning round often enough to lift the return to it. The rounds stay as
 *     recorded, only how often each is served changes;
 *   - jackpot-sized (a win over RARE_MULTIPLIER × the stake): a few thousand
 *     recorded rounds can hold one far more often than the game pays one, and
 *     a single such round can be most of the library's return; steering against
 *     it would starve every ordinary win. Those rounds are served only often
 *     enough to add at most RARE_RTP points, the ordinary ones steered to the
 *     rest of the target.
 *
 * The library then swaps a draw paying more than the bank (or the max-win cap)
 * can cover for one that fits.
 */
final class ReturnSteering
{
    /** A win over this many stakes is jackpot-sized. */
    public const int RARE_MULTIPLIER = 1000;

    /** At most this many RTP points come from jackpot-sized rounds. */
    public const float RARE_RTP = 2.0;

    /** Keep the drawn round. */
    public const string KEEP = 'keep';

    /** Swap it for a random losing round. */
    public const string LOSE = 'lose';

    /** Swap it for a random ordinary winning round ({@see ordinaryWins()}). */
    public const string WIN = 'win';

    /**
     * What a library's stats need for steering, from the query of its rounds:
     * the return (% of stake) from jackpot-sized rounds, and the share of
     * rounds with an ordinary win.
     *
     * @param  Builder<*>  $rounds
     * @return array{rare_rtp: float, hit: float}
     */
    public static function stats(Builder $rounds, string $win = 'win_cents', string $bet = 'bet_cents'): array
    {
        $staked = (float) (clone $rounds)->sum($bet);
        $n = (clone $rounds)->count();

        return [
            'rare_rtp' => $staked > 0 ? 100 * (float) self::rare(clone $rounds, $win, $bet)->sum($win) / $staked : 0.0,
            'hit' => $n > 0 ? self::ordinaryWins(clone $rounds, $win, $bet)->count() / $n : 0.0,
        ];
    }

    /**
     * What to do with a drawn round paying `$win` on `$bet` (KEEP, LOSE or WIN).
     *
     * @param  array{rtp: float, rare_rtp?: float, hit?: float}  $stats  the library's (stats cached before these fields: down only)
     */
    public static function decide(array $stats, float $rtpTarget, int|float $win, int|float $bet): string
    {
        $rare = (float) ($stats['rare_rtp'] ?? 0.0);
        $rtp = (float) $stats['rtp'] - $rare;                         // the ordinary rounds' return
        $hit = (float) ($stats['hit'] ?? 0.0);
        $target = $rtpTarget - min($rare, self::RARE_RTP);           // what the ordinary rounds should return
        $roll = mt_rand() / mt_getrandmax();

        if ($rare > 0 && $win > self::RARE_MULTIPLIER * $bet) {
            // jackpot-sized: kept only often enough to add RARE_RTP points
            return $roll > self::RARE_RTP / $rare ? self::LOSE : self::KEEP;
        }
        if ($rtp <= 0) {
            return self::KEEP;
        }
        if ($target < $rtp) {
            // too generous: keep a win with probability target / rtp, else a losing round
            return $win > 0 && $roll > $target / $rtp ? self::LOSE : self::KEEP;
        }
        if ($target > $rtp && $hit > 0 && $hit < 1 && $win <= 0) {
            // too tight: an ordinary win pays rtp / hit on average, so turning a share p of the
            // losing draws into one adds (1 - hit) · p · rtp / hit — p = (target - rtp) · hit / ((1 - hit) · rtp)
            return $roll < ($target - $rtp) * $hit / ((1 - $hit) * $rtp) ? self::WIN : self::KEEP;
        }

        return self::KEEP;
    }

    /**
     * Narrow a round query to ordinary winning rounds — what a WIN swaps in.
     *
     * @template T of Builder
     *
     * @param  T  $q
     * @return T
     */
    public static function ordinaryWins(Builder $q, string $win = 'win_cents', string $bet = 'bet_cents'): Builder
    {
        return $q->where($win, '>', 0)->whereRaw("{$win} <= ? * {$bet}", [self::RARE_MULTIPLIER]);
    }

    /**
     * @param  Builder<*>  $q
     * @return Builder<*>
     */
    private static function rare(Builder $q, string $win, string $bet): Builder
    {
        return $q->whereRaw("{$win} > ? * {$bet}", [self::RARE_MULTIPLIER]);
    }
}
