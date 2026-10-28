<?php

namespace App\Services\GamePlay;

use App\Enums\BonusFlow;
use App\Enums\ClientProtocol;
use App\Enums\Currency;
use App\Enums\Volatility;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameTemplate;
use Illuminate\Support\Collection;

/**
 * The full engine spec for a running game, resolved once: the template's shared
 * "group" config with this shop's per-game overrides applied on top.
 *
 * Ports every knob the legacy VanguardLTE\Games\*\SlotSettings hardcoded, plus
 * the legacy admin game-edit form (percent, rezerv, gamebank, jpg, chanceFirepot,
 * lines_percent_config, bet, denomination) — all now DB-driven.
 */
class GameConfig
{
    /**
     * Legacy RTP bands (w_games random_keys) — win-chance keys.
     *
     * @var list<string>
     */
    public const array RTP_BANDS = ['74_80', '82_88', '90_96'];

    /**
     * Legacy line-count buckets for the win-chance tables.
     *
     * @var list<int>
     */
    public const array LINE_BUCKETS = [1, 3, 5, 7, 9, 10];

    /** @var array<string, mixed>|null merged config from the game's categories, by position */
    private ?array $categoryConfig = null;

    /** @var array<string, mixed> memoised accessor results (hot path: the spin loop) */
    private array $memo = [];

    public function __construct(
        public readonly GameTemplate $template,
        public readonly Game $game,
        /** The player's currency for this session — scales the denomination off `pricingCurrency()`. Null = no scaling (return the priced values as-is). */
        public readonly ?Currency $currency = null,
    ) {}

    /** Memoise an accessor's result (the spin loop calls them thousands of times). */
    private function once(string $key, callable $compute): mixed
    {
        return $this->memo[$key] ??= $compute();
    }

    // ---- inheritance -----------------------------------------------

    /**
     * Config shared by the game's categories ("Egt", "Pragmatic", "Slots"…),
     * lowest position first so later ones win. Games inherit these unless the
     * template or the game itself sets the same key.
     *
     * @return array<string, mixed>
     */
    public function categoryConfig(): array
    {
        if ($this->categoryConfig !== null) {
            return $this->categoryConfig;
        }

        /** @var Collection<int, Category> $categories */
        $categories = ($this->game->relationLoaded('categories')
            ? $this->game->categories
            : $this->game->categories()->get())->sortBy('position');

        $merged = [];
        foreach ($categories as $category) {
            $config = $category->config;
            if (is_array($config)) {
                $merged = array_replace_recursive($merged, $config);
            }
        }

        return $this->categoryConfig = $merged;
    }

    /**
     * Resolve an inheritable setting: game override → template column →
     * category config → default.
     */
    public function inherited(string $key, mixed $default = null): mixed
    {
        return $this->game->getAttribute($key)
            ?? $this->template->getAttribute($key)
            ?? data_get($this->categoryConfig(), $key)
            ?? $default;
    }

    // ---- grid --------------------------------------------------------

    public function reelCount(): int
    {
        return $this->once('reelCount', fn () => (int) ($this->template->reel_count ?: 5));
    }

    public function rowCount(): int
    {
        return $this->once('rowCount', fn () => (int) ($this->template->row_count ?: 3));
    }

    /**
     * Each reel's height when they differ (`bonus_config.reel_heights`, e.g.
     * [3, 4, 5, 4, 3] — rombo ways games); `row_count` is then the tallest.
     */
    public function reelHeight(int $reel): int
    {
        $heights = $this->bonusConfig()['reel_heights'] ?? null;

        return is_array($heights) && isset($heights[$reel]) ? (int) $heights[$reel] : $this->rowCount();
    }

    public function symbolCount(): int
    {
        return $this->once('symbolCount', fn () => (int) ($this->template->symbol_count ?: 9));
    }

    /**
     * The playable symbol ids (legacy SlotSettings::SymbolGame).
     *
     * @return list<int>
     */
    public function symbols(): array
    {
        return $this->once('symbols', function () {
            $symbols = $this->template->symbols;

            return $symbols
                ? array_values(array_map('intval', $symbols))
                : range(0, $this->symbolCount() - 1);
        });
    }

    /** @return list<list<int>> row index per reel */
    public function paylines(): array
    {
        return $this->once('paylines', fn () => $this->template->paylines ?: $this->defaultPaylines());
    }

    /** Ways games stake a single "line" (legacy `$lines = 1`). */
    public function lineCount(): int
    {
        return $this->isWays() ? 1 : count($this->paylines());
    }

    // ---- symbols ----------------------------------------------------

    public function wildSymbol(): ?int
    {
        return $this->once('wildSymbol', fn () => $this->template->wild_symbol !== null ? (int) $this->template->wild_symbol : null);
    }

    public function scatterSymbol(): ?int
    {
        return $this->once('scatterSymbol', fn () => $this->template->scatter_symbol !== null ? (int) $this->template->scatter_symbol : null);
    }

    public function bonusSymbol(): ?int
    {
        return $this->once('bonusSymbol', fn () => $this->template->bonus_symbol !== null ? (int) $this->template->bonus_symbol : null);
    }

    public function wildMultiplier(): int
    {
        return $this->once('wildMultiplier', fn () => (int) ($this->game->wild_multiplier ?? $this->template->wild_multiplier ?: 1));
    }

    /** Smallest paying run left-to-right (legacy games mostly 3; EGT "Action Money" pays 2). */
    public function minMatch(): int
    {
        return $this->once('minMatch', fn () => max(2, (int) ($this->inherited('min_match') ?: 3)));
    }

    /**
     * Every symbol that triggers a feature (scatter + bonus), in trigger order.
     *
     * @return list<int>
     */
    public function triggerSymbols(): array
    {
        return $this->once('triggerSymbols', fn () => array_values(array_filter(
            [$this->scatterSymbol(), $this->bonusSymbol()],
            fn ($s) => $s !== null,
        )));
    }

    public function clientProtocol(): ClientProtocol
    {
        $value = $this->template->client_protocol
            ?? data_get($this->categoryConfig(), 'client_protocol');

        return $value instanceof ClientProtocol
            ? $value
            : (ClientProtocol::tryFrom((string) $value) ?? ClientProtocol::Standard);
    }

    // ---- bonus flows (bonus_config JSON) --------------------------

    /**
     * @return array<string, mixed> category defaults ← template ← game override
     *
     * Memoised: {@see SlotEngine::hasFeatureTrigger()} calls
     * {@see bonusFlowFor()} — and so this — on every rejection-sampling try
     * while searching for a matching board (up to 1,200 per spin), and none
     * of the three sources merged here change mid-simulation.
     */
    public function bonusConfig(): array
    {
        return $this->once('bonusConfig', fn () => array_replace_recursive(
            (array) data_get($this->categoryConfig(), 'bonus_config', []),
            (array) ($this->template->bonus_config ?? []),
            (array) ($this->game->getAttribute('bonus_config') ?? []),
        ));
    }

    /**
     * Symbols a "Book"-style game may pick as its free-spin special symbol
     * (`bonus_config.free_symbol`: a list of symbol ids, or `true` for every
     * symbol but the wild/scatter). Empty = the game has no such symbol.
     *
     * @return list<int>
     */
    public function freeSymbolCandidates(): array
    {
        $setting = $this->bonusConfig()['free_symbol'] ?? null;

        if (is_array($setting)) {
            return array_values(array_map('intval', $setting));
        }

        return $setting
            ? array_values(array_diff($this->symbols(), array_filter([$this->wildSymbol(), $this->scatterSymbol()], fn ($s) => $s !== null)))
            : [];
    }

    /**
     * "Ways pays" games (Amatic's All Ways / Enchanted Cleopatra family) have
     * no paylines: a symbol pays when it shows anywhere on adjacent reels from
     * the left, × the number of matching cells on each of those reels.
     *
     * bonus_config.ways = {
     *   "symbols":  [0, 1, …],  paying symbols, in the order the client lists their wins
     *   "doubler":  10,         each one on the board doubles the whole win (null = none)
     *   "free_any_reel": true,  free spins pay a symbol on any reels, not just adjacent ones
     * }
     */
    /**
     * How a payline is read (`bonus_config.line_eval`): "first" (default) =
     * the first non-wild symbol's run; "best" = legacy Amatic, every symbol's
     * run (wild included) and the line pays the best.
     */
    public function lineEval(): string
    {
        return (string) ($this->bonusConfig()['line_eval'] ?? 'first');
    }

    /**
     * How a run's wilds apply the wild multiplier (`bonus_config.wild_mult_mode`):
     * "once" (default) — ×m when any wild substitutes; "each" — ×m per wild
     * (two doubling wilds = ×4, Lucky Double); "extra" — ×m per wild after the
     * first (Book of … : one book ×1, two ×2).
     */
    public function wildMultiplierMode(): string
    {
        $mode = $this->bonusConfig()['wild_mult_mode'] ?? (($this->bonusConfig()['wild_mult_each'] ?? false) ? 'each' : 'once');

        return in_array($mode, ['once', 'each', 'extra'], true) ? $mode : 'once';
    }

    /** Line wins × this when every cell shows the same symbol (`bonus_config.full_board_mult`, classic full-screen bonus). */
    public function fullBoardMultiplier(): int
    {
        return max(1, (int) ($this->bonusConfig()['full_board_mult'] ?? 1));
    }

    /** A landed wild fills its reel before lines are read (`bonus_config.expanding_wild`). */
    public function expandingWild(): bool
    {
        return (bool) ($this->bonusConfig()['expanding_wild'] ?? false);
    }

    /** Scatter wins × this when a wild is anywhere on the board (`bonus_config.scatter_wild_mult`, legacy `$scatMpl`). */
    public function scatterWildMultiplier(): int
    {
        return max(1, (int) ($this->bonusConfig()['scatter_wild_mult'] ?? 1));
    }

    /**
     * Piece ids of tall symbols → the symbol they count as
     * (`bonus_config.symbol_alias`, e.g. {"11": 13, "12": 13}).
     *
     * @return array<int, int>
     */
    public function symbolAliases(): array
    {
        $out = [];
        foreach ((array) ($this->bonusConfig()['symbol_alias'] ?? []) as $from => $to) {
            $out[(int) $from] = (int) $to;
        }

        return $out;
    }

    /**
     * "Any mix" pays (`bonus_config.mixed_pays`, e.g. [{"symbols": [0,1,2,3], "row": 12}]):
     * a line whose leading run is any mix of these symbols (wilds joining)
     * also pays paytable row `row` for the run's length — the line pays the better.
     *
     * @return list<array{symbols: list<int>, row: int}>
     */
    public function mixedPays(): array
    {
        $out = [];
        foreach ((array) ($this->bonusConfig()['mixed_pays'] ?? []) as $m) {
            $out[] = ['symbols' => array_map('intval', (array) ($m['symbols'] ?? [])), 'row' => (int) ($m['row'] ?? -1)];
        }

        return $out;
    }

    /**
     * A mystery symbol (`bonus_config.mystery` = {"symbol": 14, "targets": {"0": 5, "3": 25, …}}):
     * every one on the board turns into the same symbol, drawn from `targets`
     * (symbol => weight) — the client is told which in the spin's tail.
     *
     * With `groups` (e.g. [[1, 2], [3, 4]]) each group of reels draws its own
     * symbol, and the tail is the reel count, one digit per reel (0 off the
     * groups) and `suffix` (Big Panda).
     *
     * @return array{symbol: int, targets: array<int, float>, groups: list<list<int>>, suffix: string}|null
     */
    public function mystery(): ?array
    {
        $m = $this->bonusConfig()['mystery'] ?? null;
        if (! is_array($m) || ! isset($m['symbol']) || empty($m['targets'])) {
            return null;
        }
        $targets = [];
        foreach ((array) $m['targets'] as $sym => $w) {
            $targets[(int) $sym] = (float) $w;
        }

        return ['symbol' => (int) $m['symbol'], 'targets' => $targets,
            'groups' => array_values(array_map(fn ($g) => array_map('intval', (array) $g), (array) ($m['groups'] ?? []))),
            'suffix' => (string) ($m['suffix'] ?? '')];
    }

    /**
     * What the mystery symbol becomes on each reel of this board when reel
     * groups draw their own (null without groups); reels off the groups keep it.
     *
     * @return list<int|null>|null
     */
    public function mysteryReelTargets(array $board): ?array
    {
        $m = $this->mystery();
        if ($m === null || $m['groups'] === []) {
            return null;
        }
        $out = array_fill(0, count($board), null);
        foreach ($m['groups'] as $g => $reels) {
            $x = (crc32('group'.$g.'|'.json_encode($board)) % 1000000) / 1000000 * array_sum($m['targets']);
            $pick = (int) array_key_last($m['targets']);
            foreach ($m['targets'] as $sym => $w) {
                if (($x -= $w) < 0) {
                    $pick = $sym;
                    break;
                }
            }
            foreach ($reels as $r) {
                $out[$r] = $pick;
            }
        }

        return $out;
    }

    /**
     * What the mystery symbol becomes on this board: a weighted pick seeded by
     * the board itself, so the paying engine and the frame's tail always agree.
     */
    public function mysteryTarget(array $board): ?int
    {
        $m = $this->mystery();
        if ($m === null) {
            return null;
        }
        $x = (crc32((string) json_encode($board)) % 1000000) / 1000000 * array_sum($m['targets']);
        foreach ($m['targets'] as $sym => $w) {
            if (($x -= $w) < 0) {
                return $sym;
            }
        }

        return (int) array_key_last($m['targets']);
    }

    /**
     * A giant wild (`bonus_config.wild_block` = {"symbol": 7, "size": 3}): each
     * such symbol on the board is the centre of a size × size block of it,
     * clipped at the board's edges (Fortuna's Fruits).
     *
     * @return array{symbol: int, size: int}|null
     */
    public function wildBlock(): ?array
    {
        $b = $this->bonusConfig()['wild_block'] ?? null;
        if (! is_array($b) || ! isset($b['symbol'])) {
            return null;
        }

        return ['symbol' => (int) $b['symbol'], 'size' => max(1, (int) ($b['size'] ?? 3))];
    }

    /**
     * Which cells a giant-symbol block covers: the board's shape, `$symbol`
     * on every covered cell and 0 elsewhere (the client is sent exactly this).
     *
     * @return list<list<int>>
     */
    public static function blockCells(array $board, int $symbol, int $size): array
    {
        $board = array_map(fn ($col) => array_values($col), array_values($board));
        $cells = array_map(fn ($col) => array_fill(0, count($col), 0), $board);
        $half = intdiv($size, 2);
        foreach ($board as $r => $col) {
            foreach ($col as $row => $sym) {
                if ($sym !== $symbol) {
                    continue;
                }
                for ($dr = -$half; $dr <= $half; $dr++) {
                    for ($dy = -$half; $dy <= $half; $dy++) {
                        if (isset($cells[$r + $dr][$row + $dy])) {
                            $cells[$r + $dr][$row + $dy] = $symbol;
                        }
                    }
                }
            }
        }

        return $cells;
    }

    /**
     * Cell multipliers (`bonus_config.cell_mult` = {"symbols": [1, 2…], "values": {"1": 77, "2": 23}}):
     * each cell holding one of `symbols` carries a multiplier drawn from
     * `values` (multiplier => weight); a line pays × the product of its run's
     * cells (Double Fruits). Other cells are × 1.
     *
     * @return array{symbols: list<int>, values: array<int, float>}|null
     */
    public function cellMult(): ?array
    {
        $m = $this->bonusConfig()['cell_mult'] ?? null;
        if (! is_array($m) || empty($m['symbols']) || empty($m['values'])) {
            return null;
        }
        $values = [];
        foreach ((array) $m['values'] as $mult => $w) {
            $values[(int) $mult] = (float) $w;
        }

        return ['symbols' => array_map('intval', (array) $m['symbols']), 'values' => $values];
    }

    /**
     * Every cell's multiplier on this board (the board's shape; 1 = none).
     * Drawn from a hash of the board, so the paying engine and the frame's
     * tail always agree.
     *
     * @return list<list<int>>
     */
    public function cellMultipliers(array $board): array
    {
        $m = $this->cellMult();
        $seed = (string) json_encode($board);
        $out = [];
        foreach (array_values($board) as $r => $col) {
            foreach (array_values($col) as $row => $sym) {
                $mult = 1;
                if ($m !== null && in_array($sym, $m['symbols'], true)) {
                    $x = (crc32($seed.'|'.$r.'|'.$row) % 1000000) / 1000000 * array_sum($m['values']);
                    foreach ($m['values'] as $v => $w) {
                        if (($x -= $w) < 0) {
                            $mult = $v;
                            break;
                        }
                    }
                }
                $out[$r][$row] = $mult;
            }
        }

        return $out;
    }

    /**
     * Every line win × this to the power of the wilds anywhere on the board
     * (`bonus_config.wild_board_mult`, e.g. 2: one wild ×2, two ×4 — Lucky Double).
     */
    public function wildBoardMult(): ?int
    {
        $v = $this->bonusConfig()['wild_board_mult'] ?? null;

        return $v === null ? null : max(1, (int) $v);
    }

    /**
     * A reel landing all wild draws one multiplier for every line
     * (`bonus_config.wild_reel_mult` = {"values": {"2": 5, "3": 3, "5": 2}},
     * multiplier => weight — Lady Joker); one draw however many reels fill.
     *
     * @return array<int, float>|null
     */
    public function wildReelMult(): ?array
    {
        $values = $this->bonusConfig()['wild_reel_mult']['values'] ?? null;
        if (! is_array($values) || $values === []) {
            return null;
        }
        $out = [];
        foreach ($values as $mult => $w) {
            $out[(int) $mult] = (float) $w;
        }

        return $out;
    }

    /**
     * Every reel draws a wild multiplier each spin and a line with wilds pays
     * × the sum of its wild reels' draws (`bonus_config.wild_reel_values` =
     * {"values": {"2": 136, "3": 164}, "prefix": "…", "suffix": "…"}, value =>
     * weight — Kitty Bet); the tail is prefix, the reel count and one digit per reel, suffix.
     *
     * @return array{values: array<int, float>, prefix: string, suffix: string}|null
     */
    public function wildReelValues(): ?array
    {
        $r = $this->bonusConfig()['wild_reel_values'] ?? null;
        if (! is_array($r) || empty($r['values'])) {
            return null;
        }
        $values = [];
        foreach ((array) $r['values'] as $v => $w) {
            $values[(int) $v] = (float) $w;
        }

        return ['values' => $values, 'prefix' => (string) ($r['prefix'] ?? ''), 'suffix' => (string) ($r['suffix'] ?? '')];
    }

    /**
     * This board's per-reel wild multipliers (null when the game has none),
     * drawn from a hash of the board so the paying engine and the tail agree.
     *
     * @return list<int>|null
     */
    public function wildReelDraw(array $board): ?array
    {
        $rv = $this->wildReelValues();
        if ($rv === null) {
            return null;
        }
        $seed = (string) json_encode(array_map(fn ($col) => array_values($col), array_values($board)));
        $out = [];
        foreach (array_keys(array_values($board)) as $r) {
            $x = (crc32('reelv'.$r.'|'.$seed) % 1000000) / 1000000 * array_sum($rv['values']);
            $pick = (int) array_key_last($rv['values']);
            foreach ($rv['values'] as $v => $w) {
                if (($x -= $w) < 0) {
                    $pick = $v;
                    break;
                }
            }
            $out[] = $pick;
        }

        return $out;
    }

    /** This board's full-wild-reel multiplier (1 without a full wild reel), drawn from a hash of the board. */
    public function wildReelMultiplier(array $board): int
    {
        $values = $this->wildReelMult();
        $wild = $this->wildSymbol();
        if ($values === null || $wild === null) {
            return 1;
        }
        $full = array_filter($board, fn ($col) => $col !== [] && array_unique($col) === [array_values($col)[0]] && array_values($col)[0] === $wild);
        if ($full === []) {
            return 1;
        }
        $x = (crc32('reel|'.json_encode($board)) % 1000000) / 1000000 * array_sum($values);
        foreach ($values as $mult => $w) {
            if (($x -= $w) < 0) {
                return $mult;
            }
        }

        return (int) array_key_last($values);
    }

    /**
     * Random wilds (`bonus_config.random_wilds` = {"chance": 0.078, "cells": {"5": 3, …},
     * "marks": {"7": 33, …}, "prefix": "01"}): on `chance` of spins, a number of
     * cells drawn from `cells` (count => weight) turn wild; the client is told
     * each one's mark (a hex digit drawn from `marks`) — Fiery Fruits.
     *
     * @return array{chance: float, cells: array<int, float>, marks: array<string, float>, prefix: string}|null
     */
    public function randomWilds(): ?array
    {
        $r = $this->bonusConfig()['random_wilds'] ?? null;
        if (! is_array($r) || empty($r['cells']) || empty($r['marks'])) {
            return null;
        }
        $weights = fn (array $w) => array_map('floatval', $w);

        return ['chance' => (float) ($r['chance'] ?? 0), 'cells' => $weights((array) $r['cells']),
            'marks' => $weights((array) $r['marks']), 'prefix' => (string) ($r['prefix'] ?? '')];
    }

    /**
     * This board's random-wild marks: the board's shape, the mark digit on
     * each cell turned wild and null elsewhere — drawn from a hash of the
     * board, so the paying engine and the frame's tail always agree.
     *
     * @return list<list<string|null>>
     */
    public function randomWildCells(array $board): array
    {
        $board = array_map(fn ($col) => array_values($col), array_values($board));
        $out = array_map(fn ($col) => array_fill(0, count($col), null), $board);
        $rw = $this->randomWilds();
        $seed = (string) json_encode($board);
        $roll = fn (string $salt) => (crc32($salt.'|'.$seed) % 1000000) / 1000000;
        $pick = function (array $weights, float $x) {
            $x *= array_sum($weights);
            foreach ($weights as $k => $w) {
                if (($x -= $w) < 0) {
                    return $k;
                }
            }

            return array_key_last($weights);
        };
        if ($rw === null || $roll('chance') >= $rw['chance']) {
            return $out;
        }
        $free = [];
        foreach ($board as $r => $col) {
            foreach (array_keys($col) as $row) {
                $free[] = [$r, $row];
            }
        }
        $count = min(count($free), (int) $pick($rw['cells'], $roll('count')));
        for ($i = 0; $i < $count; $i++) {
            $at = (int) floor($roll('cell'.$i) * count($free));
            [$r, $row] = $free[$at];
            array_splice($free, $at, 1);
            $out[$r][$row] = (string) $pick($rw['marks'], $roll('mark'.$i));
        }

        return $out;
    }

    /** The board's total wild multiplier (1 when the game has none). */
    public function boardWildMultiplier(array $board): int
    {
        $base = $this->wildBoardMult();
        $wild = $this->wildSymbol();
        if ($base === null || $wild === null) {
            return $this->wildReelMultiplier($board);
        }
        $n = 0;
        foreach ($board as $col) {
            $n += count(array_keys($col, $wild, true));
        }

        return $base ** $n;
    }

    /**
     * A fractional factor on top of the (whole-number) wild multiplier
     * (`bonus_config.wild_pay_factor`, e.g. 1.5 — Hot Fruits 27).
     */
    public function wildPayFactor(): float
    {
        return (float) ($this->bonusConfig()['wild_pay_factor'] ?? 1);
    }

    /** Lines also pay right to left (`bonus_config.line_both_ways`, Beauty Fairy): each line pays its better direction. */
    public function lineBothWays(): bool
    {
        return (bool) ($this->bonusConfig()['line_both_ways'] ?? false);
    }

    /** A line's run may start on any reel, not only the first (`bonus_config.line_any_position`, Hot Diamonds). */
    public function lineAnyPosition(): bool
    {
        return (bool) ($this->bonusConfig()['line_any_position'] ?? false);
    }

    /** A run of wilds alone pays this symbol's row instead of the wild's own (`bonus_config.wild_pays_as`). */
    public function wildPaysAs(): ?int
    {
        $v = $this->bonusConfig()['wild_pays_as'] ?? null;

        return $v === null ? null : (int) $v;
    }

    /** Fewest scatters that pay (`bonus_config.scatter_min`, default 3; some Amatic games pay 2). */
    public function scatterMinPay(): int
    {
        return max(1, (int) ($this->bonusConfig()['scatter_min'] ?? 3));
    }

    /**
     * A wild filling one of these reels copies itself onto another of them
     * (`bonus_config.wild_reel_copy` = {"reels": [1, 2, 3]}, Beauty Warrior).
     *
     * @return list<int>|null
     */
    public function wildReelCopy(): ?array
    {
        $reels = $this->bonusConfig()['wild_reel_copy']['reels'] ?? null;

        return is_array($reels) && count($reels) >= 2 ? array_map('intval', array_values($reels)) : null;
    }

    /**
     * The reel a full wild copies itself onto on this board (null: none),
     * drawn from a hash of the board so the paying engine and the tail agree.
     */
    public function wildCopyTarget(array $board): ?int
    {
        $reels = $this->wildReelCopy();
        $wild = $this->wildSymbol();
        if ($reels === null || $wild === null) {
            return null;
        }
        $alias = $this->symbolAliases();
        $full = array_values(array_filter($reels, fn ($r) => ($board[$r] ?? []) !== []
            && array_filter($board[$r], fn ($x) => ($alias[$x] ?? $x) !== $wild) === []));
        if ($full === []) {
            return null;
        }
        $others = array_values(array_diff($reels, $full));
        if ($others === []) {
            return null;
        }

        return $others[crc32('copy|'.json_encode($board)) % count($others)];
    }

    /** A wild in an "any mix" run multiplies it like a line (`bonus_config.mixed_wild_mult`, Lucky Zodiac). */
    public function mixedWildMult(): bool
    {
        return (bool) ($this->bonusConfig()['mixed_wild_mult'] ?? false);
    }

    /** A ways game's fewest paying scatters: the paytable decides unless `scatter_min` is set (All Ways Hotter Fruits: 3). */
    public function waysScatterMin(): int
    {
        return max(1, (int) ($this->bonusConfig()['scatter_min'] ?? 1));
    }

    /**
     * A game-specific Amatic client frame layout (`bonus_config.client_layout`),
     * e.g. "coins" for the Blazing Coins family: 2-hex-digit reel symbols and
     * a coin-booster / coin-value tail on every frame.
     */
    public function clientLayout(): ?string
    {
        $layout = $this->bonusConfig()['client_layout'] ?? null;

        return is_string($layout) && $layout !== '' ? $layout : null;
    }

    /**
     * How this game's Amatic client reads frames beyond the shared layout
     * (`bonus_config.client`, captured from Amatic's own server at import):
     * reel-symbol hex digits, the init win-list length, and the game-specific
     * fields its parser reads after the init win list / after a spin's cards.
     * Null tails = the plain layout.
     *
     * Overlay games (a second strip set over the reels) also carry those strips
     * and the parked positions to send.
     *
     * Bonus-buy games carry the prices their init tail offers (× total bet)
     * and the cap on a buy's cost (cents, 0 = none).
     *
     * @return array{digits: int, win_slots: ?int, init_tail: ?string, spin_tail: ?string, overlay_strips: list<list<int>>, overlay_rp: list<int>, buy: list<int>, buy_cap: int}
     */
    public function amaticClient(): array
    {
        $c = (array) ($this->bonusConfig()['client'] ?? []);

        return [
            'digits' => (int) ($c['digits'] ?? ($this->clientLayout() === 'coins' ? 2 : 1)),
            'win_slots' => isset($c['win_slots']) ? (int) $c['win_slots'] : null,
            'init_tail' => isset($c['init_tail']) ? (string) $c['init_tail'] : null,
            'spin_tail' => isset($c['spin_tail']) ? (string) $c['spin_tail'] : null,
            'overlay_strips' => array_values(array_map(fn ($s) => array_map('intval', (array) $s), (array) ($c['overlay_strips'] ?? []))),
            'overlay_rp' => array_values(array_map('intval', (array) ($c['overlay_rp'] ?? []))),
            'buy' => array_values(array_map('intval', (array) ($c['buy'] ?? []))),
            'buy_cap' => (int) ($c['buy_cap'] ?? 0),
        ];
    }

    public function isWays(): bool
    {
        return $this->waysConfig() !== null;
    }

    /** @return array{symbols: list<int>, doubler: ?int, free_any_reel: bool}|null */
    public function waysConfig(): ?array
    {
        return $this->once('waysConfig', function () {
            $ways = $this->bonusConfig()['ways'] ?? null;
            if (! is_array($ways)) {
                return null;
            }
            $paying = array_values(array_diff($this->symbols(), $this->triggerSymbols()));

            return [
                'symbols' => array_values(array_map('intval', $ways['symbols'] ?? $paying)),
                'doubler' => isset($ways['doubler']) ? (int) $ways['doubler'] : null,
                'free_any_reel' => (bool) ($ways['free_any_reel'] ?? false),
            ];
        });
    }

    /** Client-side config passed to the front-end: category ← template ← game. */
    public function layout(): array
    {
        return array_replace_recursive(
            (array) data_get($this->categoryConfig(), 'layout', []),
            (array) ($this->template->layout ?? []),
            (array) ($this->game->getAttribute('layout') ?? []),
        );
    }

    /**
     * Which mechanic a given trigger symbol runs, and its params.
     *
     * bonus_config = {
     *   "triggers": { "<sym>": { "flow": "<BonusFlow>", "min": 3 }, … },
     *   "<flow key>": { …params… }
     * }
     *
     * @return array{flow: BonusFlow, min: int, params: array<string, mixed>}
     */
    public function bonusFlowFor(int $symbol): array
    {
        $cfg = $this->bonusConfig();
        $trigger = $cfg['triggers'][(string) $symbol] ?? $cfg['triggers'][$symbol] ?? null;

        if (! $trigger) {
            // Sensible default: the scatter grants free spins, anything else pays only.
            $flow = $symbol === $this->scatterSymbol() && $this->hasFreeSpins()
                ? BonusFlow::FreeSpins
                : BonusFlow::None;

            return ['flow' => $flow, 'min' => 3, 'params' => []];
        }

        $flow = BonusFlow::tryFrom($trigger['flow'] ?? 'none') ?? BonusFlow::None;

        return [
            'flow' => $flow,
            'min' => (int) ($trigger['min'] ?? 3),
            'params' => is_array($cfg[$flow->value] ?? null) ? $cfg[$flow->value] : [],
        ];
    }

    /** Gamble params from bonus_config.gamble (type, steps) with sane defaults. */
    public function gambleConfig(): array
    {
        return array_merge(
            ['type' => 'red_black', 'steps' => 5],
            is_array($this->bonusConfig()['gamble'] ?? null) ? $this->bonusConfig()['gamble'] : [],
        );
    }

    // ---- paytable -------------------------------------------------

    /** @return array<int, list<float>> symbol => payout per match count (index = count-1), × betline */
    public function paytable(): array
    {
        return $this->once('paytable', function () {
            $table = $this->template->paytable ?: $this->defaultPaytable();

            return collect($table)->mapWithKeys(fn ($row, $k) => [(int) $k => array_map('floatval', $row)])->all();
        });
    }

    public function payout(int $symbol, int $count): float
    {
        return $this->paytable()[$symbol][$count - 1] ?? 0.0;
    }

    // ---- reels ---------------------------------------------------

    /** @return array<int, list<int>> reel index (0-based) => symbol strip */
    public function reelStrips(bool $bonus = false): array
    {
        $strips = $this->template->reel_strips ?: [];
        $prefix = $bonus ? 'reelStripBonus' : 'reelStrip';
        $out = [];

        for ($i = 1; $i <= $this->reelCount(); $i++) {
            $strip = $strips[$prefix.$i] ?? ($strips['reelStrip'.$i] ?? null);
            $out[$i - 1] = $strip
                ? array_map('intval', $strip)
                : $this->randomStrip();
        }

        return $out;
    }

    public function hasReelStrips(): bool
    {
        return ! empty($this->template->reel_strips);
    }

    // ---- bonus / free spins / gamble --------------------------------

    public function hasBonus(): bool
    {
        return (bool) $this->template->has_bonus;
    }

    public function bonusType(): int
    {
        return (int) $this->template->bonus_type;
    }

    public function scatterType(): int
    {
        return (int) $this->template->scatter_type;
    }

    public function hasFreeSpins(): bool
    {
        return (bool) $this->template->has_free_spins;
    }

    /** Fixed free-spin grant when the game has no per-scatter table. */
    public function freeSpinsCount(): int
    {
        return (int) ($this->game->free_spins_count ?? $this->template->free_spins_count ?: 10);
    }

    /**
     * Free spins to award for a scatter count (legacy slotFreeCount array,
     * e.g. [0,0,0,10,10,10] → 3 scatters grants 10). Falls back to the fixed
     * grant when no table is configured.
     */
    public function freeSpinsFor(int $scatterCount): int
    {
        $table = $this->game->free_spins_table ?? $this->template->free_spins_table;

        if (! $table) {
            return $this->freeSpinsCount();
        }

        $values = array_values(array_map('intval', $table));
        $index = min(max($scatterCount, 0), count($values) - 1);

        return $values[$index] ?: $this->freeSpinsCount();
    }

    public function freeSpinsMultiplier(): int
    {
        return (int) ($this->template->free_spins_multiplier ?: 1);
    }

    public function hasGamble(): bool
    {
        return (bool) $this->template->has_gamble;
    }

    public function gambleType(): int
    {
        return (int) $this->template->gamble_type;
    }

    /** 1/N chance the gamble step wins (legacy w_games.rezerv). */
    public function gambleWinChance(): int
    {
        return (int) ($this->game->reserve_percent ?: $this->template->gamble_win_chance ?: 4);
    }

    public function splitScreen(): bool
    {
        return (bool) $this->template->split_screen;
    }

    public function volatility(): Volatility
    {
        return $this->template->volatility ?? Volatility::Medium;
    }

    /**
     * Win-size curve params (LineSlotServer). Template / game override merged
     * onto the volatility defaults so partial overrides work.
     *
     * @return array<string, float>
     */
    public function winDistribution(): array
    {
        return array_merge(
            $this->volatility()->shape(),
            $this->template->win_distribution ?? [],
            $this->game->win_distribution ?? [],
        );
    }

    // ---- scatter-pays / tumble family (TumbleEngine) ---------------

    /**
     * Extra config for the modern Pragmatic "gs2c" tumble/scatter-pays family
     * — the multiplier-bomb symbol + its value pool, the scatter's OWN
     * count-indexed payout table (separate from the main `paytable` — the
     * scatter symbol's row there is always zero; its trigger payout comes
     * from this table instead, legacy `scatters=` field), and the scatter
     * counts that trigger / retrigger free spins. Doesn't fit the line-slot
     * columns.
     *
     * @return array{multiplier_symbol:?int, multiplier_values:list<int>, lines:int, needaddfs:int, addfs:int, free_spins:int, scatter_paytable:list<float>, raw:list<string>}
     */
    public function tumbleConfig(): array
    {
        $c = $this->template->tumble_config ?? [];

        return [
            'multiplier_symbol' => isset($c['multiplier_symbol']) ? (int) $c['multiplier_symbol'] : null,
            'multiplier_values' => array_map('intval', $c['multiplier_values'] ?? [2]),
            'lines' => (int) ($c['lines'] ?? 20),
            'needaddfs' => (int) ($c['needaddfs'] ?? 3),
            'addfs' => (int) ($c['addfs'] ?? 5),
            'free_spins' => (int) ($c['free_spins'] ?? $this->freeSpinsCount()),
            'scatter_paytable' => array_map('floatval', $c['scatter_paytable'] ?? []),
            // The legacy `init.php`'s raw "key=value" lines, verbatim — it's
            // already the exact doInit spec the real client expects; replayed
            // near-as-is instead of reconstructed from the parsed fields above.
            'raw' => array_map('strval', $c['raw'] ?? []),
        ];
    }

    // ---- cascade / scatter-pays family (CascadeEngine) -------------

    /**
     * Config for the platform's own cascade engine ({@see Engine\CascadeEngine},
     * the RoyalSpin "tumble" games on the standard JSON protocol). Shares the
     * `tumble_config` column with the Pragmatic gs2c family but reads its own
     * keys: pay tiers (a symbol pays once `count >= tiers[i]`, paytable row
     * index = tier), the scatter's count => coefficient table, the free-spin
     * trigger / retrigger counts and the multiplier-bomb value pool.
     *
     * "Olympus" family extras (all off by default, so older games are unchanged):
     *   multiplier_in_base    bombs also land (and multiply) in the base game
     *   multiplier_accumulate free spins keep a running total multiplier — every
     *                         winning round adds its bombs to it, and the total
     *                         multiplies every winning round from then on
     *   buy_feature           cost of buying the free spins, × the stake (0 = off)
     *   ante_bet              stake factor of the ante bet (e.g. 1.25; 0 = off)
     *   ante_bonus_factor     how much more often the feature triggers under ante
     *   wild_symbol           a wild that counts toward every paying symbol (and
     *                         tumbles away with the win it helped)
     *
     * @return array{lines:int, tiers:list<int>, multiplier_symbol:?int, multiplier_values:list<int>, scatter_pays:array<int,float>, trigger:int, retrigger:int, retrigger_spins:int, free_spins:int, multiplier_in_base:bool, multiplier_accumulate:bool, buy_feature:float, ante_bet:float, ante_bonus_factor:float, wild_symbol:?int}
     */
    public function cascadeConfig(): array
    {
        return $this->once('cascadeConfig', function () {
            $c = $this->template->tumble_config ?? [];
            $pays = [];
            foreach ((array) ($c['scatter_pays'] ?? []) as $count => $coef) {
                $pays[(int) $count] = (float) $coef;
            }

            return [
                'lines' => max(1, (int) ($c['lines'] ?? 20)),
                'tiers' => array_values(array_map('intval', $c['tiers'] ?? [8, 10, 12])),
                'multiplier_symbol' => isset($c['multiplier_symbol']) ? (int) $c['multiplier_symbol'] : null,
                'multiplier_values' => array_values(array_map('intval', $c['multiplier_values'] ?? [2, 3, 5, 10])),
                'scatter_pays' => $pays,
                'trigger' => (int) ($c['trigger'] ?? 4),
                'retrigger' => (int) ($c['retrigger'] ?? 3),
                'retrigger_spins' => (int) ($c['retrigger_spins'] ?? 5),
                'free_spins' => (int) ($c['free_spins'] ?? $this->freeSpinsCount()),
                'multiplier_in_base' => (bool) ($c['multiplier_in_base'] ?? false),
                'multiplier_accumulate' => (bool) ($c['multiplier_accumulate'] ?? false),
                'buy_feature' => max(0.0, (float) ($c['buy_feature'] ?? 0)),
                'ante_bet' => max(0.0, (float) ($c['ante_bet'] ?? 0)),
                'ante_bonus_factor' => max(1.0, (float) ($c['ante_bonus_factor'] ?? 2)),
                'wild_symbol' => isset($c['wild_symbol']) ? (int) $c['wild_symbol'] : null,
            ];
        });
    }

    // ---- classic-payline family (PaylineEngine) --------------------

    /**
     * Extra config for the modern Pragmatic "gs2c" classic-payline family —
     * the money/coin symbol + its weighted value pool, the mystery-scatter
     * symbol range, and the scatter-count -> free-spins/multiplier tables
     * (index 0 = 5 scatters, descending). Doesn't fit the line-slot columns.
     *
     * @return array{money_symbol:?int, money_values:list<int>, mystery_symbol_min:int, mystery_symbol_max:int, fs_by_scatter_count:list<int>, fsmul_by_scatter_count:list<int>, needaddfs:int, addfs:int, raw:list<string>}
     */
    public function paylineConfig(): array
    {
        $c = $this->template->payline_config ?? [];

        return [
            'money_symbol' => isset($c['money_symbol']) ? (int) $c['money_symbol'] : null,
            'money_values' => array_map('intval', $c['money_values'] ?? [0]),
            'mystery_symbol_min' => (int) ($c['mystery_symbol_min'] ?? 3),
            'mystery_symbol_max' => (int) ($c['mystery_symbol_max'] ?? 11),
            'fs_by_scatter_count' => array_map('intval', $c['fs_by_scatter_count'] ?? [0, 0, 0, 0, 0]),
            'fsmul_by_scatter_count' => array_map('intval', $c['fsmul_by_scatter_count'] ?? [1, 1, 1, 1, 1]),
            'needaddfs' => (int) ($c['needaddfs'] ?? 3),
            'addfs' => (int) ($c['addfs'] ?? 5),
            'raw' => array_map('strval', $c['raw'] ?? []),
        ];
    }

    // ---- RTP feedback loop (legacy GetSpinSettings) ----------------

    /** Spins between RTP self-corrections (legacy RtpControlCount, default 200). */
    public function rtpControlWindow(): int
    {
        return (int) ($this->template->rtp_control_window ?: 200);
    }

    /**
     * Feedback-loop knobs used while a game's actual RTP is running hot.
     *
     * @return array{cold_spin_chance:int, cold_bonus_chance:int, correction_max_win:int, clamp_spins:array{0:int,1:int}}
     */
    public function rtpControl(): array
    {
        $c = $this->template->rtp_control ?? [];

        return [
            'cold_spin_chance' => (int) ($c['cold_spin_chance'] ?? 20),
            'cold_bonus_chance' => (int) ($c['cold_bonus_chance'] ?? 5000),
            'correction_max_win' => (int) ($c['correction_max_win'] ?? 5),
            'clamp_spins' => [
                (int) ($c['clamp_spins'][0] ?? 25),
                (int) ($c['clamp_spins'][1] ?? 50),
            ],
        ];
    }

    // ---- RTP win-chance tables (legacy lines_percent_config) --------

    /**
     * 1/N chance of a win, for the given event ('spin'|'bonus'), active line
     * count and the shop's target RTP. Falls back through
     * game override → template config → volatility default.
     */
    public function winChance(string $event, int $lineCount, float $shopRtp): int
    {
        $tables = $this->game->win_chances ?: $this->template->win_chances ?: $this->defaultWinChances();

        $bucket = $this->lineBucket($lineCount);
        $band = $this->rtpBand($shopRtp);

        $value = $tables[$event]["line{$bucket}"][$band]
            ?? $tables[$event]["line{$bucket}"]
            ?? null;

        if (is_array($value)) {
            $value = reset($value);
        }

        return max(1, (int) ($value ?: ($event === 'bonus' ? 220 : 8)));
    }

    // ---- firepots (legacy chanceFirepot* / fireCount*) --------------

    /** @return list<array{chance:int,count:int}> */
    public function firepots(): array
    {
        $chances = $this->game->jackpot_chances ?: [];
        $out = [];

        foreach ([1, 2, 3] as $i) {
            $chance = (int) ($chances["chance{$i}"] ?? $chances["chanceFirepot{$i}"] ?? 0);
            if ($chance > 0) {
                $out[] = ['chance' => $chance, 'count' => (int) ($chances["count{$i}"] ?? $chances["fireCount{$i}"] ?? 0)];
            }
        }

        return $out;
    }

    // ---- bet / denomination ---------------------------------------

    public function betOptions(): array
    {
        $opts = $this->game->bet_options
            ?: $this->template->default_bet_options
            ?: [10, 20, 50, 100, 200];

        return array_values(array_map('floatval', $opts));
    }

    /** Priced denomination, scaled into the session currency (see CurrencyScaler). */
    public function denomination(): float
    {
        return $this->once('denomination', fn () => app(CurrencyScaler::class)->denomination(
            $this->baseDenomination(),
            $this->pricingCurrency(),
            $this->currency,
        ));
    }

    /** Denomination as configured, in the game's pricing currency (no FX applied). */
    public function baseDenomination(): float
    {
        return (float) ($this->game->denomination ?: $this->template->default_denomination ?: 1);
    }

    /** The currency `bet_options` / `denomination` are priced in: game → template → EUR. */
    public function pricingCurrency(): Currency
    {
        $value = $this->game->getAttribute('pricing_currency')
            ?? $this->template->getAttribute('pricing_currency');

        return $value instanceof Currency
            ? $value
            : (Currency::tryFrom((string) $value) ?? Currency::default());
    }

    // ---- serialisation for the client ---------------------------

    /** @return array<string, mixed> */
    public function toClientArray(): array
    {
        return [
            'reels' => $this->reelCount(),
            'rows' => $this->rowCount(),
            'symbols' => $this->symbols(),
            'paylines' => $this->paylines(),
            'paytable' => $this->paytable(),
            'wild' => $this->wildSymbol(),
            'scatter' => $this->scatterSymbol(),
            'bonus' => $this->bonusSymbol(),
            'wild_multiplier' => $this->wildMultiplier(),
            'has_bonus' => $this->hasBonus(),
            'has_free_spins' => $this->hasFreeSpins(),
            'free_spins_count' => $this->freeSpinsCount(),
            'free_spins_table' => $this->template->free_spins_table,
            'free_spins_multiplier' => $this->freeSpinsMultiplier(),
            'has_gamble' => $this->hasGamble(),
            'gamble_type' => $this->gambleType(),
            'split_screen' => $this->splitScreen(),
            'volatility' => $this->volatility()->value,
            'layout' => $this->layout(),
        ];
    }

    // ---- defaults ------------------------------------------------

    /** @return array<int, list<float>> */
    public function defaultPaytable(): array
    {
        $symbols = $this->symbols();
        $count = max(1, count($symbols) - 1);
        $out = [];

        foreach ($symbols as $i => $s) {
            $tier = 1 + $i / $count;                                 // 1..2
            $wild = $s === $this->wildSymbol();
            $out[$s] = $wild
                ? [0, 0, 0, 0, 0, 0]
                : [0, 0, round(2 * $tier), round(8 * $tier), round(25 * $tier), round(60 * $tier)];
        }

        return $out;
    }

    public function defaultPaylines(): array
    {
        $rows = $this->rowCount();
        $reels = $this->reelCount();
        $lines = [];

        for ($r = 0; $r < $rows; $r++) {
            $lines[] = array_fill(0, $reels, $r);                    // straight lines
        }
        if ($rows >= 3) {
            $lines[] = array_map(fn ($i) => $i % 2 === 0 ? 0 : $rows - 1, range(0, $reels - 1)); // zigzag
            $lines[] = array_map(fn ($i) => $i % 2 === 0 ? $rows - 1 : 0, range(0, $reels - 1));
        }

        return array_slice(array_merge($lines, array_map(
            fn () => array_map(fn () => random_int(0, $rows - 1), range(1, $reels)),
            range(1, 10)
        )), 0, 10);
    }

    /** @return array<string, array<string, array<string, int>>> */
    public function defaultWinChances(): array
    {
        $shape = $this->volatility()->shape();
        $baseSpin = (int) round(8 / $shape['hit_bonus']);
        $out = ['spin' => [], 'bonus' => []];

        foreach (self::LINE_BUCKETS as $bucket) {
            foreach (self::RTP_BANDS as $i => $band) {
                $out['spin']["line{$bucket}"][$band] = max(3, $baseSpin - $i * 2);
                $out['bonus']["line{$bucket}"][$band] = max(60, 260 - $i * 60);
            }
        }

        return $out;
    }

    /** @return list<int> */
    private function randomStrip(): array
    {
        $symbols = $this->symbols();

        return array_map(fn () => $symbols[array_rand($symbols)], range(1, 24));
    }

    private function lineBucket(int $lineCount): int
    {
        foreach (array_reverse(self::LINE_BUCKETS) as $b) {
            if ($lineCount >= $b) {
                return $b;
            }
        }

        return 1;
    }

    private function rtpBand(float $rtp): string
    {
        return match (true) {
            $rtp <= 80 => '74_80',
            $rtp <= 88 => '82_88',
            default => '90_96',
        };
    }
}
