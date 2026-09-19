<?php

namespace App\Services\GamePlay\Engine;

use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * The one line-slot engine — every classic reel slot (EGT / Gaminator / Amatic /
 * Playtech / …). Fully DB-driven through {@see GameConfig}: grid, symbols,
 * paytable, paylines, reel strips, wild/scatter behaviour, the minimum paying
 * run, free spins, and the RTP win-chance tables.
 *
 * Faithful to the legacy VanguardLTE engine — it does NOT synthesise wins to a
 * target. {@see SpinDecider} gates each spin (win / bonus / none) from the
 * configured 1-in-N chances plus the self-correcting RTP feedback loop; the
 * engine then spins the *real* reels in a rejection loop until the board matches
 * that gate and fits the bank. The paid amount is whatever the real board
 * evaluates to — so payout frequency and size come from the reel/paytable
 * design, exactly like the original games (rare, mostly small, features carry
 * the RTP).
 *
 * The client protocols (HTTP {@see LineSlotServer}, the socket handlers under
 * App\Services\GamePlay\Protocol) call this and format the {@see SpinResult}.
 */
class SlotEngine
{
    private const int MAX_TRIES = 1200;

    /** After this many failed tries, stop requiring the win-size floor (legacy). */
    private const int DROP_FLOOR_AT = 700;

    public function __construct(private readonly SpinDecider $decider) {}

    public function spin(GameContext $context, float $stake, int $lines, float $betline, bool $free = false): SpinResult
    {
        $cfg = $context->config();
        $decision = $this->decider->decide($context, $free ? 'bonus' : 'spin', $lines, $stake);

        // Win ceiling = what the bank can afford, capped by the shop's single-win
        // cap (which the RTP loop shrinks when the game is ahead); win floor = a
        // random paytable coef, dropped when the game owes money (legacy).
        // budget is the settlement pool (bankAvailable) — PHP_FLOAT_MAX for demo,
        // a real figure for live play, so an empty pool forces a losing board.
        $ceiling = min(
            $decision->budget * $cfg->winDistribution()['budget_frac'],
            $stake * max(0.25, $decision->maxWinMultiplier),   // capMultiplier — shrinks hard when RTP runs ahead
        );
        if ($decision->winScale < 1.0) {
            // RTP loop is clamping — hold single wins right down.
            $ceiling = min($ceiling, $stake * max(1.0, 5 * $decision->winScale));
        }
        $floor = min($this->winFloor($context, $cfg, $stake) * $decision->winScale, $ceiling * 0.5);

        [$board, $eval] = $this->rollToOutcome($cfg, $lines, $betline, $free, $decision, $ceiling, $floor);

        return new SpinResult(
            bet: $free ? 0.0 : $stake,
            win: round(min($eval['win'], $ceiling), 4),
            reels: $board,
            lines: $eval['lines'],
            state: $free ? 'freespin' : 'bet',
            extra: [
                'scatters' => $eval['scatters'],
                'scatter_cells' => $eval['scatter_cells'],
                'decision' => $decision->type,
                'reel_offsets' => $eval['offsets'],
            ],
        );
    }

    /** Double-or-nothing on an amount (the client's gamble step). */
    public function gamble(GameContext $context, float $amount, ?int $guess = null): array
    {
        $cfg = $context->config();
        $won = random_int(1, max(1, $cfg->gambleWinChance())) === 1;

        if ($context->bankAvailable() < $amount * 2) {
            $won = false;   // can't cover the double payout → forced loss (legacy)
        }

        return ['won' => $won, 'before' => $amount, 'after' => $won ? $amount * 2 : 0.0, 'delta' => $won ? $amount : -$amount];
    }

    // ---- the rejection loop ---------------------------------------

    /**
     * Spin the real reels until the board matches the gated outcome + bank.
     * Legacy Server.php's `for ($i = 0; $i <= 2000; $i++)` loop.
     *
     * @return array{0: array, 1: array{win: float, lines: array, scatters: array<int,int>, scatter_cells: array<int,array>, offsets: ?array<int,int>}}
     */
    private function rollToOutcome(GameConfig $cfg, int $lines, float $betline, bool $free, SpinDecision $decision, float $ceiling, float $floor): array
    {
        $wantWin = $decision->isWin();
        $wantBonus = $decision->type === 'bonus';

        $bestBoard = null;
        $bestScore = INF;

        // Legacy Server.php: spin real reels and take the FIRST board that
        // matches the gated outcome and fits the bank — the win is whatever
        // those reels naturally pay. (No "keep the smallest of N" — that made
        // wins feel artificially stingy.)
        $bestOffsets = null;

        for ($i = 0; $i < self::MAX_TRIES; $i++) {
            // Bonus strips only come in once we're actually inside the free-spin
            // round ($free — legacy GetReelStrips() swaps them in only when
            // $slotEvent == 'freespin'). The *triggering* spin that lands the
            // scatter is still ordinary play on the base strips; some games
            // (e.g. AmazonsBattleEGT) strip the scatter out of their bonus
            // reels entirely — to stop it retriggering during free spins —
            // which made the feature mathematically unreachable when this
            // used to switch to bonus strips as soon as a 'bonus' outcome was
            // merely being *decided*, not yet entered.
            $board = $this->spinReels($cfg, $free, $wantBonus, $offsets);
            // Cheap pass: this loop only ever reads win/scatters to accept,
            // reject or score a try — the line/cell breakdown `evaluate()`
            // can build is wasted work for the up to MAX_TRIES-1 boards this
            // spin doesn't keep. The one board it does keep gets a full,
            // detailed re-evaluate below before this returns.
            $eval = $this->evaluate($board, $cfg, $betline, $lines, detailed: false);
            $trigger = $this->hasFeatureTrigger($cfg, $eval['scatters']);
            $floorNow = $i < self::DROP_FLOOR_AT ? $floor : 0.0;

            if ($wantBonus) {
                if ($trigger && $eval['win'] <= $ceiling) {
                    return [$board, $this->evaluate($board, $cfg, $betline, $lines) + ['offsets' => $offsets]];
                }
                $score = ($trigger ? 0 : 1e9) + max(0, $eval['win'] - $ceiling);
            } elseif (! $wantWin) {
                if ($eval['win'] <= 0 && ! $trigger) {
                    return [$board, $this->evaluate($board, $cfg, $betline, $lines) + ['offsets' => $offsets]];
                }
                $score = $eval['win'] + ($trigger ? 1e9 : 0);
            } else {
                if (! $trigger && $eval['win'] >= max($floorNow, 0.0001) && $eval['win'] <= $ceiling) {
                    return [$board, $this->evaluate($board, $cfg, $betline, $lines) + ['offsets' => $offsets]];
                }
                $score = ($trigger ? 1e9 : 0)
                    + ($eval['win'] > $ceiling ? $eval['win'] - $ceiling : 0)
                    + ($eval['win'] < $floorNow ? $floorNow - $eval['win'] : 0)
                    + ($eval['win'] <= 0 ? 1e6 : 0);
            }

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestBoard = $board;
                $bestOffsets = $offsets;
            }
        }

        // Never found a match — fall back to the closest board, forced clean for a loser.
        $bestBoard ??= $this->spinReels($cfg, $free, false, $bestOffsets);
        $best = $this->evaluate($bestBoard, $cfg, $betline, $lines);

        if (! $wantWin && ($best['win'] > 0 || $this->hasFeatureTrigger($cfg, $best['scatters']))) {
            $bestBoard = $this->forceLoser($cfg, $bestBoard, $lines);
            $best = $this->evaluate($bestBoard, $cfg, $betline, $lines);
            // forceLoser mutates cells directly — the strip offsets no longer
            // reconstruct this exact board. Rare fallback path (MAX_TRIES
            // exhausted); only affects protocols that transmit raw reel
            // positions (Playtech) instead of the resolved symbol grid.
        }

        return [$bestBoard, $best + ['offsets' => $bestOffsets]];
    }

    /**
     * Legacy GetRandomPay: shuffle every positive paytable coefficient, take
     * one, and grant it as this spin's floor *only* if the book can afford it
     * (`stat_in < stat_out + coef * AllBet` zeroes it out — legacy returns the
     * bare coefficient and the caller multiplies by `allbet` separately, but
     * it's the same figure). There's no per-line scaling here — legacy
     * multiplies the coefficient by the *whole* round stake, not stake/lines,
     * so a 5000x jackpot-tier entry only ever clears the bar once the shop is
     * meaningfully ahead, and otherwise this correctly comes back 0 rather
     * than a disproportionate fraction of it.
     */
    private function winFloor(GameContext $context, GameConfig $cfg, float $stake): float
    {
        $coefs = [];
        foreach ($cfg->paytable() as $row) {
            foreach ((array) $row as $v) {
                if ($v > 0) {
                    $coefs[] = (float) $v;
                }
            }
        }
        if ($coefs === []) {
            return 0.0;
        }

        $pick = $coefs[array_rand($coefs)] * $stake;
        $game = $context->game;

        return (float) $game->total_bet < ((float) $game->total_win + $pick) ? 0.0 : $pick;
    }

    private function hasFeatureTrigger(GameConfig $cfg, array $scatters): bool
    {
        foreach ($cfg->triggerSymbols() as $sym) {
            if (($scatters[$sym] ?? 0) >= $cfg->bonusFlowFor($sym)['min']) {
                return true;
            }
        }

        return false;
    }

    private function forceLoser(GameConfig $cfg, array $board, int $lines): array
    {
        $rows = $cfg->rowCount();
        $mid = max(1, min($cfg->minMatch(), $cfg->reelCount()) - 1);

        foreach (array_slice($cfg->paylines(), 0, $lines) as $rowByReel) {
            $board[$mid][$rowByReel[$mid] % $rows] = $this->otherSymbol($board[0][$rowByReel[0] % $rows], $cfg);
        }
        // also break any scatter cluster
        foreach ($cfg->triggerSymbols() as $sym) {
            for ($reel = 0; $reel < $cfg->reelCount(); $reel += 2) {
                for ($row = 0; $row < $rows; $row++) {
                    if (($board[$reel][$row] ?? null) === $sym) {
                        $board[$reel][$row] = $this->otherSymbol($sym, $cfg);
                    }
                }
            }
        }

        return $board;
    }

    // ---- real reels ---------------------------------------------

    /**
     * One honest spin from the configured strips (random position per reel).
     *
     * @param  array<int,int>|null  $offsets  set to the chosen strip start index per
     *                                        reel (row 0's position) — the raw
     *                                        position Playtech's wire protocol
     *                                        transmits instead of a symbol grid.
     */
    public function spinReels(GameConfig $cfg, bool $bonusStrips, bool $forceScatter = false, ?array &$offsets = null): array
    {
        $strips = $cfg->reelStrips($bonusStrips);
        $rows = $cfg->rowCount();
        $scatter = $cfg->scatterSymbol();
        $board = [];
        $offsets = [];

        foreach ($strips as $reel => $strip) {
            $n = max(1, count($strip));

            // For a bonus outcome, land the scatter on the reels it can appear on.
            $at = $forceScatter && $scatter !== null && $reel % 2 === 0 && in_array($scatter, $strip, true)
                ? $this->scatterAnchor($strip, $scatter, $rows)
                : random_int(0, $n - 1);

            $col = [];
            for ($r = 0; $r < $rows; $r++) {
                $col[$r] = (int) $strip[($at + $r) % $n];
            }
            $board[$reel] = $col;
            $offsets[$reel] = $at;
        }

        return $board;
    }

    /** A strip position that puts $symbol somewhere in the visible window. */
    private function scatterAnchor(array $strip, int $symbol, int $rows): int
    {
        $positions = array_keys($strip, $symbol, true);
        if ($positions === []) {
            return random_int(0, max(0, count($strip) - 1));
        }
        $hit = $positions[array_rand($positions)];

        return ($hit - random_int(0, $rows - 1) + count($strip)) % count($strip);
    }

    // ---- payline evaluation ------------------------------------

    /**
     * Left-to-right: wild substitutes (and may multiply a win), a run of
     * >= minMatch pays per the paytable, trigger symbols counted anywhere.
     *
     * @param  bool  $detailed  {@see rollToOutcome()}'s rejection-sampling loop
     *     calls this on every try (up to MAX_TRIES per spin) purely to read
     *     `win`/`scatters` for its accept/reject check, and discards
     *     everything else for every try but the one it keeps. `false` skips
     *     building the line-win/cell breakdown nobody reads for those
     *     boards — same win total and scatter counts either way, just
     *     without the wasted array allocations. The one board actually
     *     returned is always re-evaluated with this left `true`.
     * @return array{win: float, lines: array, scatters: array<int,int>, scatter_cells: array<int,array>}
     */
    public function evaluate(array $board, GameConfig $cfg, float $betline, int $activeLines, bool $detailed = true): array
    {
        $wild = $cfg->wildSymbol();
        $triggers = $cfg->triggerSymbols();
        $wildMult = $cfg->wildMultiplier();
        $reels = $cfg->reelCount();
        $rows = $cfg->rowCount();
        $minMatch = $cfg->minMatch();
        $paytable = $cfg->paytable();

        $win = 0.0;
        $lineWins = [];

        foreach (array_slice($cfg->paylines(), 0, $activeLines) as $lineIndex => $rowByReel) {
            $symbols = [];
            for ($reel = 0; $reel < $reels; $reel++) {
                $symbols[] = $board[$reel][$rowByReel[$reel] % $rows] ?? 0;
            }

            $first = $symbols[0] === $wild ? ($this->firstNonWild($symbols, $wild) ?? $wild) : $symbols[0];
            if (in_array($first, $triggers, true)) {
                continue;
            }

            $count = 0;
            $wilds = 0;
            foreach ($symbols as $sym) {
                if ($sym === $first || ($wild !== null && $sym === $wild)) {
                    $count++;
                    $wilds += ($wild !== null && $sym === $wild) ? 1 : 0;
                } else {
                    break;
                }
            }

            if ($count >= $minMatch) {
                $pay = ($paytable[$first][$count] ?? $paytable[$first][$count - 1] ?? 0) * $betline;
                if ($wilds > 0 && $wilds < $count) {
                    $pay *= max(1, $wildMult);
                }
                if ($pay > 0) {
                    $win += $pay;
                    if ($detailed) {
                        $lineWins[] = [
                            'line' => $lineIndex, 'symbol' => $first, 'count' => $count,
                            'amount' => round($pay, 4),
                            'cells' => $this->lineCells($rowByReel, $count),
                        ];
                    }
                }
            }
        }

        $scatters = [];
        $scatterCells = [];
        foreach ($triggers as $sym) {
            $scatters[$sym] = 0;
            $scatterCells[$sym] = [];
            for ($reel = 0; $reel < $reels; $reel++) {
                for ($row = 0; $row < $rows; $row++) {
                    if (($board[$reel][$row] ?? null) === $sym) {
                        $scatters[$sym]++;
                        if ($detailed) {
                            $scatterCells[$sym][] = [$reel, $row];
                        }
                    }
                }
            }
            $award = $paytable[$sym][$scatters[$sym]] ?? $paytable[$sym][$scatters[$sym] - 1] ?? 0;
            if ($scatters[$sym] >= 3 && $award > 0) {
                $sp = $award * $betline * $activeLines;
                $win += $sp;
                if ($detailed) {
                    $lineWins[] = ['line' => -1, 'symbol' => $sym, 'count' => $scatters[$sym], 'amount' => round($sp, 4), 'cells' => []];
                }
            }
        }

        return ['win' => round($win, 4), 'lines' => $lineWins, 'scatters' => $scatters, 'scatter_cells' => $scatterCells];
    }

    // ---- helpers ----------------------------------------------

    /** @return list<int> flat [reel,row, …] for the first $count reels */
    private function lineCells(array $rowByReel, int $count): array
    {
        $cells = [];
        for ($reel = 0; $reel < $count; $reel++) {
            $cells[] = $reel;
            $cells[] = (int) $rowByReel[$reel];
        }

        return $cells;
    }

    private function firstNonWild(array $symbols, ?int $wild): ?int
    {
        foreach ($symbols as $s) {
            if ($s !== $wild) {
                return $s;
            }
        }

        return null;
    }

    private function otherSymbol(int $not, GameConfig $cfg): int
    {
        $symbols = array_values(array_diff($cfg->symbols(), array_merge([$not, $cfg->wildSymbol()], $cfg->triggerSymbols())));

        return $symbols === [] ? $not : $symbols[random_int(0, count($symbols) - 1)];
    }
}
