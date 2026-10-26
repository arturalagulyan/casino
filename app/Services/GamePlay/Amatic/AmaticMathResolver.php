<?php

namespace App\Services\GamePlay\Amatic;

use App\Enums\Volatility;
use App\Models\Game;
use App\Models\GameTemplate;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameConfig;
use RuntimeException;

/**
 * Decides a fetched Amatic game's maths, checked against Amatic's own
 * free-play server ({@see AmaticLiveClient}):
 *
 *  1. live — real reel strips from the init frame, the client's paytable and
 *     paylines from its engine ({@see AmaticClientMath}), wild/scatter/rules
 *     solved so every sampled spin pays line-for-line what Amatic paid
 *     ({@see AmaticMathSolver}). A legacy package, when there is one, still
 *     supplies the feature settings (free-spin counts, book symbol, ways…).
 *  2. legacy — the legacy `*AM` package alone, if its maths reproduces the
 *     live spins (ways games, or games whose engine tables weren't found).
 *
 * Either way the game-specific frame fields its client reads (2-digit
 * symbols, init/spin tails) are captured from the live frames, so our
 * server sends exactly what that client parses.
 *
 * @phpstan-import-type Init from AmaticFrameDecoder
 * @phpstan-import-type Spin from AmaticFrameDecoder
 */
class AmaticMathResolver
{
    /** Win-list entries Amatic sends for the plain layout regardless of lines (legacy constant). */
    private const int PLAIN_WIN_SLOTS = 0x33;

    /** Extra sampling while the spins still fit several rule sets. */
    private const int EXTRA_ROUNDS = 2;

    private const int EXTRA_SPINS = 60;

    public function __construct(
        private readonly AmaticFrameDecoder $decoder,
        private readonly AmaticClientMath $clientMath,
        private readonly AmaticMathSolver $solver,
        private readonly SlotEngine $engine,
    ) {}

    /**
     * @param  array<string, mixed>|null  $legacy  legacy package template attributes, if any
     * @param  (callable(string): void)|null  $log
     * @return array{attrs: array<string, mixed>, source: string, notes: list<string>}
     */
    public function resolve(string $gameId, string $engineJs, ?array $legacy, int $spins = 40, ?callable $log = null, ?AmaticLiveClient $live = null, bool $bestFit = false): array
    {
        $log ??= fn (string $m) => null;
        $live ??= new AmaticLiveClient;

        try {
            $initFrame = $live->init($gameId);
        } catch (RuntimeException $e) {
            // Amatic won't serve it (free play refused): only the legacy package is left, unverified
            if ($bestFit && $legacy !== null) {
                $log('live server refused the game ('.$e->getMessage().') — legacy package used, UNVERIFIED');

                return ['attrs' => $legacy, 'source' => 'legacy (unverified)', 'notes' => ['UNVERIFIED maths: legacy package — Amatic\'s free play refuses this game, so nothing was checked live']];
            }
            throw $e;
        }
        $init = $this->decoder->init($initFrame) ?? throw new RuntimeException("Can't read Amatic's init frame for {$gameId}");
        $log(sprintf('live: %d reels, %d lines, %d-digit symbols, bets %s', count($init['strips']), $init['lines'], $init['width'], implode(',', $init['bets'])));
        $sample = fn (int $n) => array_values(array_filter(array_map(fn ($f) => $this->decoder->spin($f, $init['ext']), $live->spins($init['lines'], $n))));
        $samples = $sample($spins);
        $log(count($samples).' live spins sampled');

        $notes = [];
        $attrs = null;
        $source = null;
        $hints = ['wild' => $legacy['wild_symbol'] ?? null, 'scatter' => $legacy['scatter_symbol'] ?? null];

        $tables = $this->clientMath->extract($engineJs, count($init['strips']), $init['lines']);
        // Overlay games (Extra Gifts/Chillis…): twice the strips — the reels,
        // then a mostly-blank layer of feature symbols over them
        $overlay = [];
        if ($tables === null && count($init['strips']) >= 6 && count($init['strips']) % 2 === 0) {
            $half = intdiv(count($init['strips']), 2);
            if ($tables = $this->clientMath->extract($engineJs, $half, $init['lines'])) {
                $overlay = array_slice($init['strips'], $half);
                $init['strips'] = array_slice($init['strips'], 0, $half);
                $log("overlay layer of {$half} strips — parked on blanks, its feature is not played");
                $notes[] = 'overlay feature (gifts/chillies) not played';
            }
        }
        // a feature strip after the five reels (Flying Dutchman's ship, Crystal Fruits' multiplier reel): parked the same way
        if ($tables === null && count($init['strips']) > 5 && ($tables = $this->clientMath->extract($engineJs, 5, $init['lines']))) {
            $overlay = array_slice($init['strips'], 5);
            $init['strips'] = array_slice($init['strips'], 0, 5);
            $log(count($overlay).' feature strip(s) after the 5 reels — parked on their commonest symbol, the feature is not played');
            $notes[] = 'feature strip(s) after the reels parked, feature not played';
        }
        // a 3-reel game whose five wire slots repeat strips (Twenty Seven) — the wire pads them again
        if ($tables === null && count($init['strips']) === 5 && ($tables = $this->clientMath->extract($engineJs, 3, $init['lines']))) {
            $init['strips'] = array_slice($init['strips'], 0, 3);
            $init['free_strips'] = array_slice($init['free_strips'], 0, 3);
            $log('3-reel game (the wire repeats strips in the 2 unused slots)');
        }
        // solve with a feature rule; while several rule sets still fit, sample more spins
        $solveUntied = function (array $args) use (&$samples, $sample, $log, &$init, &$tables, $hints): ?array {
            if ($tables === null) {
                return null;
            }
            $solved = $this->solver->solve($init, $samples, $tables, $hints, ...$args);
            for ($round = 0; $solved && $solved['fits'] > 1 && $round < self::EXTRA_ROUNDS; $round++) {
                $samples = array_merge($samples, $sample(self::EXTRA_SPINS));
                $log(count($samples).' live spins sampled ('.$solved['fits'].' rule sets fitted)');
                $solved = $this->solver->solve($init, $samples, $tables, $hints, ...$args) ?? $solved;
            }

            return $solved;
        };
        $solved = $tables ? $this->solver->solve($init, $samples, $tables, $hints) : null;
        // several rule sets still fit (typically: which symbol is the scatter,
        // when no scatter paid yet) — sample more until one is left
        for ($round = 0; $solved && $solved['fits'] > 1 && $round < self::EXTRA_ROUNDS; $round++) {
            $samples = array_merge($samples, $sample(self::EXTRA_SPINS));
            $log(count($samples).' live spins sampled ('.$solved['fits'].' rule sets fitted)');
            $solved = $this->solver->solve($init, $samples, $tables, $hints) ?? $solved;
        }
        // tall symbols with no paytable row pay as one of the paying symbols (Vampires) — sample until one map is left
        if (! $solved && $tables) {
            ['maps' => $maps, 'missed' => $missed] = $this->solver->tallTargets($init, $samples, $tables);
            for ($round = 0; count($maps) > 1 && $round < self::EXTRA_ROUNDS; $round++) {
                $samples = array_merge($samples, $sample(self::EXTRA_SPINS));
                $log(count($samples).' live spins sampled ('.count($maps).' tall-symbol maps fitted)');
                ['maps' => $maps, 'missed' => $missed] = $this->solver->tallTargets($init, $samples, $tables);
            }
            if (count($maps) === 1 && ($solved = $solveUntied(['alias' => $maps[0], 'tolerant' => $missed > 0]))) {
                $log('tall symbols pay as '.json_encode($maps[0]));
                $notes[] = 'tall symbols pay as '.json_encode($maps[0]).' (piece ⇒ symbol)';
            } elseif (count($maps) > 1) {
                $log(count($maps).' tall-symbol maps still fit — not guessing');
            }
        }
        // Every line pays as on Amatic but the extra slot doesn't: it pays a
        // feature we don't play (coin values…) — play the base game without
        // the symbols that have no paytable row
        if (! $solved && $tables && ($solved = $this->solver->solve($init, $samples, $tables, $hints, linesOnly: true))) {
            $pays = $solved['rules']['paytable'];
            $feature = array_values(array_filter(array_keys($pays), fn ($s) => array_sum($pays[$s]) == 0 && $s !== $solved['rules']['wild']));
            $strip = fn (array $s) => array_values(array_filter($s, fn ($x) => ! in_array($x, $feature, true)));
            $init['strips'] = array_map($strip, $init['strips']);
            $init['free_strips'] = array_map($strip, $init['free_strips']);
            $log('feature symbols '.implode(',', $feature).' removed from the reels — their feature is not played');
            $notes[] = 'feature symbols '.implode(',', $feature).' (coins…) removed, feature not played; scatter pays off';
        }
        // a mystery symbol: the tail is one number naming the symbol it turned into — try each symbol as the mystery one
        if (! $solved && $tables) {
            $top = max(array_merge(...$init['strips']));
            $numbered = array_filter($samples, fn ($sp) => ($n = $this->solver->tailNumber((string) ($sp['tail'] ?? ''))) !== null && $n <= $top);
            if (count($numbered) === count($samples)) {
                foreach (array_reverse(range(0, max(array_merge(...$init['strips'])))) as $m) {
                    if ($solved = $this->solver->solve($init, $samples, $tables, $hints, mystery: $m)) {
                        $log("mystery symbol {$m} (turns into the symbol its spin's tail names)");
                        break;
                    }
                }
            }
        }
        // a mystery symbol per reel group: the tail names what it turned into on each reel (Big Panda)
        if (! $solved && $tables && $samples !== []
            && array_filter($samples, fn ($sp) => $this->solver->mysteryReelTail((string) ($sp['tail'] ?? ''), count($init['strips'])) === null) === []) {
            foreach (array_reverse(range(0, max(array_merge(...$init['strips'])))) as $m) {
                if ($solved = $solveUntied(['mystery' => $m, 'mysteryReels' => true])) {
                    $log("mystery symbol {$m}, drawn per reel group ".json_encode($solved['rules']['mystery']['groups']));
                    $notes[] = 'mystery symbol drawn per reel group as seen live: '.json_encode($solved['rules']['mystery']['targets']).' (symbol ⇒ times seen)';
                    break;
                }
            }
        }
        // a giant wild: the tail is one number per cell — a block around each of
        // one symbol (Fortuna's Fruits) — check that the board alone predicts it
        if (! $solved && $tables && ($block = $this->solver->blockRule($init, $samples, $tables['rows']))) {
            if ($solved = $this->solver->solve($init, $samples, $tables, $hints, block: $block)) {
                $log("giant wild: each symbol {$block['symbol']} covers the {$block['size']}×{$block['size']} block around it");
            }
        }
        // cell multipliers: the tail is, per reel, one multiplier digit per cell (Double Fruits)
        if (! $solved && $tables && ($solved = $this->solver->solve($init, $samples, $tables, $hints, cellMult: true))) {
            $log('cell multipliers '.json_encode($solved['rules']['cell_mult']).' (a line pays × its run\'s product)');
        }
        // a board wild multiplier: the tail is every line's multiplier, a power of the wilds on the board (Lucky Double)
        if (! $solved && $tables && ($solved = $this->solver->solve($init, $samples, $tables, $hints, boardMult: true))) {
            $log("every line × {$solved['rules']['wild_board_mult']} per wild on the board");
        }
        // a line's run may start on any reel (Hot Diamonds)
        if (! $solved && $tables && ($solved = $this->solver->solve($init, $samples, $tables, $hints, anyPosition: true))) {
            $log('a line pays its best run starting on any reel');
        }
        // a full wild reel draws a multiplier for every line; the tail names it (Lady Joker)
        if (! $solved && $tables && ($solved = $this->solver->solve($init, $samples, $tables, $hints, reelMult: true))) {
            $log('a full wild reel multiplies every line by '.json_encode($solved['rules']['wild_reel_mult']['values']));
            $notes[] = 'full-wild-reel multipliers drawn as seen live: '.json_encode($solved['rules']['wild_reel_mult']['values']).' (multiplier ⇒ times seen)';
        }
        // every reel draws a wild multiplier each spin; a line pays × the sum of its wilds' (Kitty Bet)
        if (! $solved && $tables && $samples !== [] && $this->solver->reelSumTail((string) ($samples[0]['tail'] ?? ''), count($init['strips'])) !== null
            && ($solved = $solveUntied(['reelSum' => true]))) {
            $log('per-reel wild multipliers '.json_encode($solved['rules']['wild_reel_values']['values']).", summed over a line's wilds");
            $notes[] = 'per-reel wild multipliers drawn as seen live: '.json_encode($solved['rules']['wild_reel_values']['values']).' (value ⇒ times seen)';
        }
        // two wild symbols (Billy's Gang: one on the first reel, one on the third) — play one as the other
        if (! $solved && $tables) {
            foreach ($this->solver->secondWilds($init, $samples, $tables) as $alias) {
                if ($solved = $solveUntied(['alias' => $alias])) {
                    $log('symbol '.array_key_first($alias).' is a second wild (plays as '.reset($alias).')');
                    $notes[] = 'symbol '.array_key_first($alias).' is a second wild, played as symbol '.reset($alias);
                    break;
                }
            }
        }
        // jokers: the symbols with no paytable row are one wild (Lucky Joker Twins: four joker pictures)
        if (! $solved && $tables) {
            $jokers = array_values(array_filter(range(0, max(array_merge(...$init['strips']))), fn ($x) => $x >= count($tables['paytable'])
                && in_array($x, array_merge(...$init['strips']), true)));
            if (count($jokers) >= 2 && ($solved = $solveUntied(['alias' => array_fill_keys(array_slice($jokers, 1), $jokers[0])]))
                && $solved['rules']['wild'] === $jokers[0]) {
                $log('symbols '.implode(',', $jokers).' are one wild');
                $notes[] = 'symbols '.implode(',', $jokers).' are one wild, played as symbol '.$jokers[0];
            } elseif ($solved && $solved['rules']['wild'] !== ($jokers[0] ?? null)) {
                $solved = null;
            }
        }
        // cash symbols: the symbols with no paytable row pay as one paying symbol on lines (Cash & Crab: coins pay as the crab)
        if (! $solved && $tables) {
            $cash = array_values(array_filter(range(0, max(array_merge(...$init['strips']))), fn ($x) => $x >= count($tables['paytable'])
                && in_array($x, array_merge(...$init['strips']), true)));
            foreach ($cash === [] ? [] : array_keys($tables['paytable']) as $as) {
                if (array_sum($tables['paytable'][$as]) > 0
                    && ($solved = $solveUntied(['alias' => array_fill_keys($cash, $as)]))) {
                    $log('cash symbols '.implode(',', $cash)." pay as symbol {$as} on lines");
                    $notes[] = 'cash symbols '.implode(',', $cash)." pay as symbol {$as} on lines; their coin feature is not played";
                    break;
                }
            }
        }
        // random wilds: the tail marks cells that turned wild on a few spins (Fiery Fruits) — try each symbol as the wild
        if (! $solved && $tables && $samples !== []
            && $this->solver->wildMarks((string) ($samples[0]['tail'] ?? ''), count($init['strips']), $tables['rows']) !== null) {
            foreach (range(0, max(array_merge(...$init['strips']))) as $w) {
                if ($solved = $this->solver->solve($init, $samples, $tables, $hints, randomWild: $w)) {
                    $rw = $solved['rules']['random_wilds'];
                    $log("random wilds (symbol {$w}) on {$rw['chance']} of spins, cells ".json_encode($rw['cells']));
                    $notes[] = 'random-wild feature drawn as seen live: chance '.$rw['chance'].', cells '.json_encode($rw['cells']).' (count ⇒ times seen)';
                    break;
                }
            }
        }
        // lines pay both ways (Beauty Fairy)
        if (! $solved && $tables && ($solved = $this->solver->solve($init, $samples, $tables, $hints, bothWays: true))) {
            $log('lines pay both ways');
        }
        // a feature we don't play shows on a few spins (their tails differ from every other spin's):
        // match the base game exactly on the rest
        if (! $solved && $tables && ($solved = $this->solver->solve($init, $samples, $tables, $hints, tolerant: true))) {
            $log(sprintf('%d feature spin(s) skipped (tail %s) — that feature is not played', count($samples) - $solved['samples'], implode(', ', $solved['skipped'] ?? [])));
            $notes[] = 'feature spins (client tail '.implode(', ', $solved['skipped'] ?? []).') not played — base game verified on the other spins';
        }
        // no paylines: a ways game (All Ways family) — one table row per paying symbol
        $ways = null;
        if (! $solved && $tables === null && ($table = $this->clientMath->waysTable($engineJs, count($init['strips']), $init['lines']))) {
            $ways = $this->solver->solveWays($init, $samples, $table);
            $heights = 3;
            $waysCopy = false;
            // rombo games: the reels differ in height (3-4-5-4-3) — try each height array the client declares
            foreach ($ways ? [] : $this->clientMath->reelHeights($engineJs, count($init['strips'])) as $heights) {
                // a full tall wild copies itself onto another reel; the tail marks which (Beauty Warrior) — tried
                // first, as the plain rules would only tolerate those spins as an unplayed feature
                // (kept even before a copy shows: the tails have its format, and more samples follow)
                if ($ways = $this->solver->solveWays($init, $samples, $table, $heights, copyReels: true)) {
                    $waysCopy = true;
                    $log('reel heights '.implode('-', $heights).'; the tail marks wild copies');
                    break;
                }
                if ($ways = $this->solver->solveWays($init, $samples, $table, $heights)) {
                    $log('reel heights '.implode('-', $heights));
                    break;
                }
            }
            // the extra slot pays a feature we don't play (Blazing Coins 243: coin values) — play the ways without the no-row symbols
            $waysLinesOnly = false;
            if (! $ways && ($ways = $this->solver->solveWays($init, $samples, $table, $heights, linesOnly: true))) {
                $waysLinesOnly = true;
            }
            // several rule sets still fit — sample more until one is left
            for ($round = 0; $ways && $ways['fits'] > 1 && $round < self::EXTRA_ROUNDS; $round++) {
                $samples = array_merge($samples, $sample(self::EXTRA_SPINS));
                $log(count($samples).' live spins sampled ('.$ways['fits'].' ways rule sets fitted)');
                $ways = $this->solver->solveWays($init, $samples, $table, $heights, linesOnly: $waysLinesOnly, copyReels: $waysCopy) ?? $ways;
            }
            // once every live spin is decoded: drop the feature symbols from the reels
            if ($ways && $waysLinesOnly) {
                $feature = array_values(array_filter(array_unique(array_merge(...$init['strips'])), fn ($x) => array_sum($table[$x] ?? []) == 0
                    && $x !== $ways['rules']['wild'] && $x !== $ways['rules']['ways']['doubler']));
                sort($feature);
                $strip = fn (array $s) => array_values(array_filter($s, fn ($x) => ! in_array($x, $feature, true)));
                $init['strips'] = array_map($strip, $init['strips']);
                $init['free_strips'] = array_map($strip, $init['free_strips']);
                $log('feature symbols '.implode(',', $feature).' removed from the reels — their feature is not played');
                $notes[] = 'feature symbols '.implode(',', $feature).' (coins…) removed, feature not played';
            }
            if ($ways && isset($ways['skipped'])) {
                $notes[] = 'feature spins (client tail '.implode(', ', $ways['skipped']).') not played — base game verified on the other spins';
            }
        }
        $live->close();
        $client = $this->clientTail($init, $samples, $engineJs, $overlay, (int) ($tables['rows'] ?? 3));

        if ($solved) {
            $r = $solved['rules'];
            $attrs = $this->fromSolved($r, $init, $legacy);
            $source = 'live';
            $log(sprintf(
                'maths verified on %d live spins (%d winning): wild=%s scatter=%s%s%s%s%s',
                $solved['samples'], $solved['winning'], $r['wild'] ?? '-', $r['scatter'] ?? '-',
                $r['wild_multiplier'] > 1 ? " wild×{$r['wild_multiplier']}" : '',
                $r['expanding_wild'] ? ' expanding-wild' : '',
                $r['scatter_min'] === 2 ? ' 2-scatter-pays' : '',
                $r['symbol_alias'] ? ' tall-symbols' : '',
            ));
            if ($solved['fits'] > 1) {
                $notes[] = "{$solved['fits']} rule sets fit the sampled spins (first taken)";
            }
            if ($r['scatter'] !== null && ! $solved['scatter_seen']) {
                $notes[] = 'scatter pays not seen live';
            }
            if ($legacy === null && $r['scatter'] !== null) {
                $notes[] = 'free-spin count assumed (10)';
            }
        } elseif ($ways) {
            $attrs = $this->fromSolved($ways['rules'], $init, $legacy, ways: true);
            $source = 'live';
            $log(sprintf('ways maths verified on %d live spins (%d winning): wild=%s doubler=%s scatter=%s',
                $ways['samples'], $ways['winning'], $ways['rules']['wild'] ?? '-', $ways['rules']['ways']['doubler'] ?? '-', $ways['rules']['scatter'] ?? '-'));
            if ($ways['fits'] > 1) {
                $notes[] = "{$ways['fits']} rule sets fit the sampled spins (first taken)";
            }
        } elseif ($legacy !== null && $this->legacyAgrees($legacy, $init, $samples)) {
            $attrs = $legacy;
            $source = 'legacy';
            $log('legacy maths reproduces the live spins');
        }

        // asked for: the closest rule set, unverified (the games are created hidden)
        if ($attrs === null && $bestFit) {
            $fit = $tables ? $this->solver->bestFit($init, $samples, $tables) : null;
            $waysFit = null;
            // a ways game, on all its strips or without a trailing filler strip (Hot 81: 4 reels)
            foreach ($tables ? [] : array_unique([count($init['strips']), count($init['strips']) - 1]) as $n) {
                if (! ($wt = $this->clientMath->waysTable($engineJs, $n, $init['lines']))) {
                    continue;
                }
                $initN = ['strips' => array_slice($init['strips'], 0, $n), 'free_strips' => array_slice($init['free_strips'], 0, $n)] + $init;
                foreach ([3, ...$this->clientMath->reelHeights($engineJs, $n)] as $h) {
                    $try = $this->solver->bestFitWays($initN, $samples, $wt, $h);
                    if ($try && ($waysFit === null || $try['fit'] > $waysFit['fit'])) {
                        [$waysFit, $init] = [$try, $initN];
                    }
                }
            }
            if ($fit || $waysFit) {
                $b = $fit ?? $waysFit;
                $attrs = $this->fromSolved($b['rules'], $init, $legacy, ways: $fit === null);
                $source = 'best-fit (unverified)';
                $log(sprintf('UNVERIFIED best fit: %d of %d live spins match', $b['fit'], $b['samples']));
                $notes[] = sprintf('UNVERIFIED maths: the closest rule set matches %d of %d live spins — features not modelled', $b['fit'], $b['samples']);
            } elseif ($legacy !== null) {
                $attrs = $legacy;
                $source = 'legacy (unverified)';
                $notes[] = 'UNVERIFIED maths: legacy package, not reproduced on live spins';
            }
        }

        if ($attrs === null) {
            throw new RuntimeException($tables
                ? "No rule set reproduces Amatic's live spins for {$gameId}".($legacy ? ' (and the legacy package doesn\'t either)' : '')
                : "No paytable/paylines found in {$gameId}'s engine".($legacy ? ' and the legacy package doesn\'t reproduce the live spins' : ''));
        }

        if ($client !== null) {
            $attrs['bonus_config'] = array_merge((array) ($attrs['bonus_config'] ?? []), ['client' => $client]);
        }

        return ['attrs' => $attrs, 'source' => $source, 'notes' => $notes];
    }

    /** @return array<string, mixed> */
    private function fromSolved(array $r, array $init, ?array $legacy, bool $ways = false): array
    {
        $math = $ways ? $this->solver->waysTemplate($r) : $this->solver->templateMath($r);
        $strips = [];
        foreach ($init['strips'] as $i => $s) {
            $strips['reelStrip'.($i + 1)] = $s;
        }
        if (count($init['free_strips']) === count($init['strips'])) {
            foreach ($init['free_strips'] as $i => $s) {
                $strips['reelStripBonus'.($i + 1)] = $s;
            }
        }
        $math['reel_strips'] = $strips;

        $base = $legacy ?? [
            'has_bonus' => false, 'bonus_type' => 1, 'scatter_type' => 0,
            'has_gamble' => true, 'gamble_type' => 1, 'gamble_win_chance' => 4,
            'split_screen' => false, 'volatility' => Volatility::High, 'rtp_control_window' => 200,
            'default_denomination' => 1,
            'default_bet_options' => array_map(fn ($c) => round($c / 100, 2), array_values(array_unique($init['bets']))),
        ];
        $legacyBonus = (array) ($legacy['bonus_config'] ?? []);
        unset($legacyBonus['triggers']);
        $hasFree = $r['scatter'] !== null && ($legacy === null || ($legacy['has_free_spins'] ?? false));
        $math['bonus_config'] = array_merge(
            ['gamble' => ['type' => 'red_black', 'steps' => 5]],
            $legacyBonus,
            $math['bonus_config'],
            $hasFree ? ['triggers' => [(string) $r['scatter'] => ['flow' => 'free_spins', 'min' => 3]]] : [],
        );

        return array_merge($base, $math, [
            'bonus_symbol' => null,
            'has_free_spins' => $hasFree,
            'free_spins_count' => $legacy['free_spins_count'] ?? 10,
            'free_spins_table' => $legacy['free_spins_table'] ?? null,
            'free_spins_multiplier' => $legacy['free_spins_multiplier'] ?? 1,
        ]);
    }

    /**
     * The game-specific fields this client reads beyond the shared layout:
     * symbol width, the exact init win-list length, and what Amatic sends
     * after the init win list and after a plain spin's cards. Null when the
     * game uses the plain layout.
     *
     * @param  Init  $init
     * @param  list<Spin>  $samples
     * @return array<string, mixed>|null
     */
    private function clientTail(array $init, array $samples, string $engineJs, array $overlay = [], int $rows = 3): ?array
    {
        $rest = $init['rest'];
        $cut = strcspn($rest, '|#');
        $initTail = substr($rest, 0, $cut);
        // base-game results (Amatic marks them 09, win or not — 03 on older games)
        $tails = array_count_values(array_map(fn ($s) => $s['tail'] ?? '', array_filter($samples, fn ($s) => in_array($s['state'], [3, 9], true))));
        arsort($tails);
        $spinTail = (string) (array_key_first($tails) ?? '');

        if ($initTail === '' && $spinTail === '' && $init['width'] === 1 && $overlay === []) {
            return null;
        }
        // Coin games' booster (`this.Yev=this.Y3r(a);this.Ye8=[this.Y3r(a)/100]`
        // first in the tail): we run no coin feature, so it must cost nothing (×1.00)
        if (preg_match('/this\.[\w$]+=this\.[\w$]+\(a\);this\.[\w$]+=\[this\.[\w$]+\(a\)\/100\]/', $engineJs) && preg_match('/^(\d)/', $initTail)) {
            $initTail = $this->resetBooster($initTail);
        }

        return [
            'digits' => $init['width'],
            'win_slots' => $initTail === '' && $spinTail === '' ? self::PLAIN_WIN_SLOTS : $init['win_slots'],
            'init_tail' => $initTail,
            'spin_tail' => $spinTail,
            'overlay_strips' => $overlay ?: null,
            'overlay_rp' => $overlay ? array_map(fn ($strip) => $this->blankPosition($strip, $rows), $overlay) : null,
        ];
    }

    /**
     * A reel position (middle-row convention, see AmaticFormatter) whose
     * whole window shows the strip's most common symbol — the overlay's blank.
     *
     * @param  list<int>  $strip
     */
    private function blankPosition(array $strip, int $rows): int
    {
        $counts = array_count_values($strip);
        arsort($counts);
        $blank = (int) array_key_first($counts);
        $n = count($strip);
        for ($p = 0; $p < $n; $p++) {
            $ok = true;
            for ($d = 0; $d < $rows; $d++) {
                $ok = $ok && $strip[($p - 1 + $d + $n) % $n] === $blank;
            }
            if ($ok) {
                return $p;
            }
        }

        return 0;
    }

    /** Init tail `<cap><factor>…` (two length-prefixed numbers) → factor 100 (×1.00). */
    private function resetBooster(string $tail): string
    {
        $n1 = (int) $tail[0];
        $cap = substr($tail, 0, 1 + $n1);
        $n2 = (int) ($tail[1 + $n1] ?? 0);
        $rest = substr($tail, 2 + $n1 + $n2);

        return $cap.'264'.$rest;
    }

    /**
     * Does the legacy package pay what Amatic paid? Boards are rebuilt from
     * the live strips; line games compare the total win of each spin.
     *
     * @param  array<string, mixed>  $legacy
     * @param  Init  $init
     * @param  list<Spin>  $samples
     */
    private function legacyAgrees(array $legacy, array $init, array $samples): bool
    {
        $cfg = new GameConfig(new GameTemplate($legacy), new Game(['denomination' => 1]));
        if ($cfg->reelCount() !== count($init['strips'])) {
            return false;
        }
        $rows = $cfg->rowCount();
        $betline = ($init['bets'][0] ?? 1) / 100;
        $checked = 0;
        foreach ($samples as $s) {
            if (! in_array($s['state'], [3, 9], true)) {
                continue;
            }
            $board = [];
            foreach ($init['strips'] as $r => $strip) {
                $n = count($strip);
                for ($d = 0; $d < $rows; $d++) {
                    $board[$r][$d] = $strip[((($s['rp'][$r] ?? 0) - 1 + $d) % $n + $n) % $n];
                }
            }
            try {
                $ev = $this->engine->evaluate($board, $cfg, $betline, $cfg->isWays() ? 1 : $init['lines'], false);
            } catch (\Throwable) {
                return false;   // the legacy tables don't even fit this board
            }
            if ((int) round($ev['win'] * 100) !== $s['win']) {
                return false;
            }
            $checked++;
        }

        return $checked >= 8;
    }
}
