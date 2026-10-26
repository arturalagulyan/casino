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
        // Ways games stake one line but read the 10-line win chances (legacy
        // `GetSpinSettings(…, 10)`); their evaluation ignores $lines.
        $decision = $this->decider->decide($context, $free ? 'bonus' : 'spin', $cfg->isWays() ? 10 : $lines, $stake);

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
            $eval = $this->evaluate($board, $cfg, $betline, $lines, detailed: false, free: $free);
            $trigger = $this->hasFeatureTrigger($cfg, $eval['scatters']);
            $floorNow = $i < self::DROP_FLOOR_AT ? $floor : 0.0;

            if ($wantBonus) {
                if ($trigger && $eval['win'] <= $ceiling) {
                    return [$board, $this->evaluate($board, $cfg, $betline, $lines, free: $free) + ['offsets' => $offsets]];
                }
                $score = ($trigger ? 0 : 1e9) + max(0, $eval['win'] - $ceiling);
            } elseif (! $wantWin) {
                if ($eval['win'] <= 0 && ! $trigger) {
                    return [$board, $this->evaluate($board, $cfg, $betline, $lines, free: $free) + ['offsets' => $offsets]];
                }
                $score = $eval['win'] + ($trigger ? 1e9 : 0);
            } else {
                if (! $trigger && $eval['win'] >= max($floorNow, 0.0001) && $eval['win'] <= $ceiling) {
                    return [$board, $this->evaluate($board, $cfg, $betline, $lines, free: $free) + ['offsets' => $offsets]];
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
        $best = $this->evaluate($bestBoard, $cfg, $betline, $lines, free: $free);

        if (! $wantWin && ($best['win'] > 0 || $this->hasFeatureTrigger($cfg, $best['scatters']))) {
            $bestBoard = $cfg->isWays() ? $this->forceWaysLoser($cfg, $bestBoard, $free) : $this->forceLoser($cfg, $bestBoard, $lines);
            $best = $this->evaluate($bestBoard, $cfg, $betline, $lines, free: $free);
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
            for ($r = 0; $r < $cfg->reelHeight($reel); $r++) {
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
     *                          calls this on every try (up to MAX_TRIES per spin) purely to read
     *                          `win`/`scatters` for its accept/reject check, and discards
     *                          everything else for every try but the one it keeps. `false` skips
     *                          building the line-win/cell breakdown nobody reads for those
     *                          boards — same win total and scatter counts either way, just
     *                          without the wasted array allocations. The one board actually
     *                          returned is always re-evaluated with this left `true`.
     * @return array{win: float, lines: array, scatters: array<int,int>, scatter_cells: array<int,array>}
     */
    public function evaluate(array $board, GameConfig $cfg, float $betline, int $activeLines, bool $detailed = true, bool $free = false, ?array $cellMults = null, ?int $boardMult = null, ?array $wildCells = null, ?array $reelValues = null, ?int $copyReel = null): array
    {
        if ($cfg->isWays()) {
            return $this->evaluateWays($board, $cfg, $betline, $free, $detailed, $copyReel);
        }

        $wild = $cfg->wildSymbol();
        $triggers = $cfg->triggerSymbols();
        $wildMult = $cfg->wildMultiplier() * $cfg->wildPayFactor();
        $reels = $cfg->reelCount();
        $rows = $cfg->rowCount();
        $minMatch = $cfg->minMatch();
        $paytable = $cfg->paytable();
        $scatterMin = $cfg->scatterMinPay();
        $bestOfLine = $cfg->lineEval() === 'best';
        $scatterWildMult = $cfg->scatterWildMultiplier();

        // Cell multipliers are drawn for the landed board (the client is told them in the tail)
        $cellMults ??= $cfg->cellMult() !== null ? $cfg->cellMultipliers($board) : null;
        // every line × the wilds on the landed board (Lucky Double)
        $boardMult ??= $cfg->boardWildMultiplier($board);
        $anyPosition = $cfg->lineAnyPosition();
        $bothWays = $cfg->lineBothWays();
        // per-reel wild multipliers: a line with wilds pays × the sum of its wild reels' draws (Kitty Bet)
        $reelValues ??= $cfg->wildReelDraw($board);

        // Random wilds: the drawn cells turn wild (the client is told which in the tail)
        if ($wild !== null && $cfg->randomWilds() !== null) {
            foreach ($wildCells ?? $cfg->randomWildCells($board) as $r => $column) {
                foreach ($column as $row => $mark) {
                    if ($mark !== null) {
                        $board[$r][$row] = $wild;
                    }
                }
            }
        }

        // Giant wild: each block symbol covers the cells around it (the client is told which in the tail)
        if (($block = $cfg->wildBlock()) !== null) {
            foreach (GameConfig::blockCells($board, $block['symbol'], $block['size']) as $r => $column) {
                foreach ($column as $row => $sym) {
                    if ($sym !== 0) {
                        $board[$r][$row] = $sym;
                    }
                }
            }
        }

        // Mystery symbol: all of them turn into the board's drawn symbol (the client shows the switch)
        if (($mystery = $cfg->mystery()) !== null) {
            $into = (int) $cfg->mysteryTarget($board);
            // reel groups draw their own symbol (Big Panda)
            $perReel = $cfg->mysteryReelTargets($board);
            foreach ($board as $r => $column) {
                foreach ($column as $row => $sym) {
                    if ($sym === $mystery['symbol']) {
                        $board[$r][$row] = $perReel === null ? $into : ($perReel[$r] ?? $sym);
                    }
                }
            }
        }

        // Tall symbols drawn as several piece ids (e.g. a 3-high wild as
        // 11/12/13) count as their one symbol. Like the expansion below, only
        // this evaluation's copy of the board changes.
        if ($alias = $cfg->symbolAliases()) {
            foreach ($board as $r => $column) {
                foreach ($column as $row => $sym) {
                    $board[$r][$row] = $alias[$sym] ?? $sym;
                }
            }
        }

        // Expanding wild (Amatic "Joker" games): a wild fills its whole reel
        // before the lines are read. Only this evaluation's copy changes — the
        // client is sent the landed board and plays the expansion itself.
        if ($wild !== null && $cfg->expandingWild()) {
            foreach ($board as $r => $column) {
                if (in_array($wild, $column, true)) {
                    $board[$r] = array_fill(0, count($column), $wild);
                }
            }
        }
        // "each wild doubles" (Lucky Double) and the classic full-screen bonus
        $wildMode = $cfg->wildMultiplierMode();
        $fullBoardMult = count(array_unique(array_merge(...array_values($board)))) === 1 ? $cfg->fullBoardMultiplier() : 1;
        $mixedPays = $cfg->mixedPays();

        $win = 0.0;
        $lineWins = [];

        foreach (array_slice($cfg->paylines(), 0, $activeLines) as $lineIndex => $rowByReel) {
            $symbols = [];
            for ($reel = 0; $reel < $reels; $reel++) {
                $symbols[] = $board[$reel][($rowByReel[$reel] ?? 0) % $rows] ?? 0;
            }

            $start = 0;
            if ($bestOfLine) {
                [$first, $count, $pay] = $this->bestLinePay($symbols, $wild, $triggers, $paytable, $wildMult, $minMatch, $wildMode, $cfg->wildPaysAs(), $reelValues);
                // runs that start further along the line pay too (the best one counts)
                for ($from = 1; $anyPosition && $from <= $reels - $minMatch; $from++) {
                    $later = $this->bestLinePay(array_slice($symbols, $from), $wild, $triggers, $paytable, $wildMult, $minMatch, $wildMode, $cfg->wildPaysAs(),
                        $reelValues === null ? null : array_slice($reelValues, $from));
                    if ($later[2] > $pay) {
                        [$first, $count, $pay] = $later;
                        $start = $from;
                    }
                }
                // both ways: the line pays its better direction
                if ($bothWays) {
                    $back = $this->bestLinePay(array_reverse($symbols), $wild, $triggers, $paytable, $wildMult, $minMatch, $wildMode, $cfg->wildPaysAs(),
                        $reelValues === null ? null : array_reverse($reelValues));
                    if ($back[2] > $pay) {
                        [$first, $count, $pay] = $back;
                        $start = $reels - $count;
                    }
                }
                $pay *= $betline * $fullBoardMult;
            } else {
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
                $pay = 0.0;
                if ($count >= $minMatch) {
                    $pay = ($paytable[$first][$count] ?? $paytable[$first][$count - 1] ?? 0) * $betline;
                    if ($wilds > 0 && $wilds < $count) {
                        $pay *= max(1, $wildMult);
                    }
                }
            }

            // "any mix" of a symbol group (e.g. any high symbols) pays its own row when that beats the line
            foreach ($mixedPays as $mixed) {
                $run = $runWilds = 0;
                foreach ($symbols as $sym) {
                    if (! in_array($sym, $mixed['symbols'], true) && $sym !== $wild) {
                        break;
                    }
                    $run++;
                    $runWilds += $sym === $wild ? 1 : 0;
                }
                $mixedPay = ($paytable[$mixed['row']][$run] ?? 0) * $betline * $fullBoardMult;
                if ($runWilds > 0 && $runWilds < $run && $cfg->mixedWildMult()) {
                    $mixedPay *= match ($wildMode) {
                        'each' => max(1, $wildMult) ** $runWilds,
                        'extra' => max(1, $wildMult) ** ($runWilds - 1),
                        default => max(1, $wildMult),
                    };
                }
                if ($run >= $minMatch && $mixedPay > $pay) {
                    [$first, $count, $pay] = [$mixed['row'], $run, $mixedPay];
                }
            }

            $pay *= $boardMult;
            // a line pays × the product of its run's cell multipliers
            if ($cellMults !== null && $pay > 0) {
                for ($reel = $start; $reel < $start + $count; $reel++) {
                    $pay *= $cellMults[$reel][($rowByReel[$reel] ?? 0) % $rows] ?? 1;
                }
            }

            if ($count >= $minMatch && $pay > 0) {
                $win += $pay;
                if ($detailed) {
                    $lineWins[] = [
                        'line' => $lineIndex, 'symbol' => $first, 'count' => $count,
                        'amount' => round($pay, 4),
                        'cells' => $this->lineCells($rowByReel, $count, $start),
                    ];
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
            if ($scatters[$sym] >= $scatterMin && $award > 0) {
                $sp = $award * $betline * $activeLines;
                if ($scatterWildMult > 1 && $wild !== null && $this->onBoard($board, $wild)) {
                    $sp *= $scatterWildMult;
                }
                $win += $sp;
                if ($detailed) {
                    $lineWins[] = ['line' => -1, 'symbol' => $sym, 'count' => $scatters[$sym], 'amount' => round($sp, 4), 'cells' => []];
                }
            }
        }

        return ['win' => round($win, 4), 'lines' => $lineWins, 'scatters' => $scatters, 'scatter_cells' => $scatterCells];
    }

    // ---- ways evaluation ---------------------------------------

    /**
     * "Ways pays" (legacy Amatic All Ways family Server.php): per paying
     * symbol, count its cells (wild included) on each reel; a run of
     * adjacent reels from the left pays `paytable[run] × betline × Π counts`
     * (the best run wins). In free spins of `free_any_reel` games the reels
     * needn't be adjacent. Scatters pay `paytable[count] × betline` (one
     * "line"), and every doubler symbol on the board doubles the total.
     *
     * Wins are reported one entry per paying symbol, `line` = its index in
     * `ways.symbols` (the client's win-list slot), scatter as `line` -1.
     *
     * @return array{win: float, lines: array, scatters: array<int,int>, scatter_cells: array<int,array>}
     */
    /** @param  int|null  $copyReel  the reel a full wild copied onto (-1: none; null: draw it) */
    private function evaluateWays(array $board, GameConfig $cfg, float $betline, bool $free, bool $detailed, ?int $copyReel = null): array
    {
        $ways = (array) $cfg->waysConfig();
        $wild = $cfg->wildSymbol();
        $reels = $cfg->reelCount();
        $rows = $cfg->rowCount();
        $paytable = $cfg->paytable();
        $anyReel = $free && $ways['free_any_reel'];
        // a full wild copies itself onto another reel (Beauty Warrior)
        $copyReel ??= $cfg->wildCopyTarget($board);
        // tall symbols drawn as piece ids count as one symbol
        if ($alias = $cfg->symbolAliases()) {
            $board = array_map(fn ($column) => array_map(fn ($sym) => $alias[$sym] ?? $sym, $column), $board);
        }
        if ($copyReel !== null && $copyReel >= 0 && $wild !== null && isset($board[$copyReel])) {
            $board[$copyReel] = array_fill(0, count($board[$copyReel]), $wild);
        }

        $win = 0.0;
        $lineWins = [];

        foreach ($ways['symbols'] as $slot => $sym) {
            if (! isset($paytable[$sym])) {
                continue;
            }
            $counts = [];
            for ($reel = 0; $reel < $reels; $reel++) {
                $n = 0;
                for ($row = 0; $row < $rows; $row++) {
                    $cell = $board[$reel][$row] ?? null;
                    if ($cell === $sym || ($wild !== null && $cell === $wild)) {
                        $n++;
                    }
                }
                $counts[$reel] = $n;
            }

            $best = 0.0;
            $bestReels = [];
            if ($anyReel) {
                $hit = array_keys(array_filter($counts));
                $best = ($paytable[$sym][count($hit)] ?? 0) * $betline * array_product(array_map(fn ($r) => $counts[$r], $hit ?: [0]));
                $bestReels = $hit;
            } else {
                $mult = 1;
                for ($run = 0; $run < $reels && $counts[$run] > 0; $run++) {
                    $mult *= $counts[$run];
                    $pay = ($paytable[$sym][$run + 1] ?? 0) * $betline * $mult;
                    if ($pay > $best) {
                        $best = $pay;
                        $bestReels = range(0, $run);
                    }
                }
            }

            if ($best > 0) {
                $win += $best;
                if ($detailed) {
                    $lineWins[] = [
                        'line' => $slot, 'symbol' => $sym, 'count' => count($bestReels),
                        'amount' => round($best, 4),
                        'cells' => $this->waysCells($board, $bestReels, $sym, $wild, $rows),
                    ];
                }
            }
        }

        $scatters = [];
        $scatterCells = [];
        foreach ($cfg->triggerSymbols() as $sym) {
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
            $award = $scatters[$sym] >= $cfg->waysScatterMin() ? ($paytable[$sym][$scatters[$sym]] ?? 0) * $betline : 0;
            if ($award > 0) {
                $win += $award;
                if ($detailed) {
                    $lineWins[] = ['line' => -1, 'symbol' => $sym, 'count' => $scatters[$sym], 'amount' => round($award, 4), 'cells' => []];
                }
            }
        }

        $doubles = $ways['doubler'] === null ? 0 : $this->countSymbol($board, $ways['doubler']);
        if ($doubles > 0 && $win > 0) {
            $factor = 2 ** $doubles;
            $win *= $factor;
            foreach ($lineWins as &$w) {
                $w['amount'] = round($w['amount'] * $factor, 4);
            }
            unset($w);
        }

        return ['win' => round($win, 4), 'lines' => $lineWins, 'scatters' => $scatters, 'scatter_cells' => $scatterCells];
    }

    /** @return list<int> flat [reel,row, …] of every cell that took part in a ways win */
    private function waysCells(array $board, array $reels, int $sym, ?int $wild, int $rows): array
    {
        $cells = [];
        foreach ($reels as $reel) {
            for ($row = 0; $row < $rows; $row++) {
                $cell = $board[$reel][$row] ?? null;
                if ($cell === $sym || ($wild !== null && $cell === $wild)) {
                    $cells[] = $reel;
                    $cells[] = $row;
                }
            }
        }

        return $cells;
    }

    private function countSymbol(array $board, int $sym): int
    {
        $n = 0;
        foreach ($board as $col) {
            foreach ($col as $cell) {
                $n += $cell === $sym ? 1 : 0;
            }
        }

        return $n;
    }

    /**
     * Ways loser: no symbol may show on the first two reels together (so no
     * left-to-right run pays) — and, for any-reel free spins, on fewer reels
     * than its smallest paying count.
     */
    private function forceWaysLoser(GameConfig $cfg, array $board, bool $free): array
    {
        $ways = (array) $cfg->waysConfig();
        $rows = $cfg->rowCount();
        $wild = $cfg->wildSymbol();
        $fillers = array_values(array_diff($ways['symbols'], [$wild]));

        $breakReel = function (int $reel, array $avoid) use (&$board, $fillers, $wild) {
            $pool = array_values(array_diff($fillers, $avoid));
            for ($row = 0; $row < count($board[$reel] ?? []); $row++) {
                $cell = $board[$reel][$row];
                if (in_array($cell, $avoid, true) || ($wild !== null && $cell === $wild)) {
                    $board[$reel][$row] = $pool === [] ? $cell : $pool[random_int(0, count($pool) - 1)];
                }
            }
        };

        // scatters first (their replacements may land on reel 1), then reel 1's
        // wilds — which would pair with anything — then reel 2 vs reel 1.
        foreach ($cfg->triggerSymbols() as $sym) {
            for ($reel = 0; $reel < $cfg->reelCount(); $reel += 2) {
                $breakReel($reel, [$sym]);
            }
        }
        if ($wild !== null) {
            $breakReel(0, [$wild]);
        }
        $breakReel(1, array_values(array_unique($board[0])));

        if ($free && $ways['free_any_reel']) {
            $paytable = $cfg->paytable();
            foreach ($ways['symbols'] as $sym) {
                $minPay = 0;
                foreach ($paytable[$sym] ?? [] as $count => $pay) {
                    if ($pay > 0) {
                        $minPay = $count;
                        break;
                    }
                }
                for ($reel = $cfg->reelCount() - 1; $minPay > 0 && $reel >= 0; $reel--) {
                    $onReels = count(array_filter($board, fn ($col) => in_array($sym, $col, true)));
                    if ($onReels < $minPay) {
                        break;
                    }
                    $breakReel($reel, [$sym]);
                }
            }
        }

        return $board;
    }

    // ---- helpers ----------------------------------------------

    /** @return list<int> flat [reel,row, …] for the first $count reels */
    private function lineCells(array $rowByReel, int $count, int $start = 0): array
    {
        $cells = [];
        for ($reel = $start; $reel < $start + $count; $reel++) {
            $cells[] = $reel;
            $cells[] = (int) $rowByReel[$reel];
        }

        return $cells;
    }

    /**
     * Legacy Amatic line rule: every symbol on the line (the wild included)
     * pays its longest left-aligned run of itself-or-wild; a run mixing wilds
     * and the symbol is multiplied by the wild multiplier; the line pays the
     * best of them.
     *
     * @param  list<int>  $symbols
     * @param  list<int>  $triggers
     * @return array{0: int, 1: int, 2: float} [symbol, count, pay per betline]
     */
    /** @param  list<int>|null  $reelValues  each cell's reel wild multiplier, in line order: a run with wilds pays × the sum of its wilds' */
    private function bestLinePay(array $symbols, ?int $wild, array $triggers, array $paytable, float $wildMult, int $minMatch, string $wildMode = 'once', ?int $wildPaysAs = null, ?array $reelValues = null): array
    {
        $best = [$symbols[0], 0, 0.0];
        foreach (array_unique($symbols) as $csym) {
            if (in_array($csym, $triggers, true) && $csym !== $wild) {
                continue;
            }
            $count = $wilds = $wildSum = 0;
            foreach ($symbols as $i => $sym) {
                if ($sym !== $csym && $sym !== $wild) {
                    break;
                }
                $count++;
                $wilds += $sym === $wild ? 1 : 0;
                $wildSum += $sym === $wild ? ($reelValues[$i] ?? 1) : 0;
            }
            if ($count < $minMatch) {
                continue;
            }
            // a run of wilds alone pays as another symbol's row where the game says so (Aztec Secret: as the top symbol)
            $pay = (float) ($paytable[$csym === $wild && $wildPaysAs !== null ? $wildPaysAs : $csym][$count] ?? 0);
            if ($reelValues !== null) {
                $pay *= max(1, $wildSum);
            } elseif ($wilds > 0 && $wilds < $count) {
                $pay *= match ($wildMode) {
                    'each' => max(1, $wildMult) ** $wilds,
                    'extra' => max(1, $wildMult) ** ($wilds - 1),
                    default => max(1, $wildMult),
                };
            }
            if ($pay > $best[2]) {
                $best = [$csym, $count, $pay];
            }
        }

        return $best;
    }

    private function onBoard(array $board, int $symbol): bool
    {
        foreach ($board as $column) {
            if (in_array($symbol, $column, true)) {
                return true;
            }
        }

        return false;
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
