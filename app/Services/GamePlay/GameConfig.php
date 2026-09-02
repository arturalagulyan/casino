<?php

namespace App\Services\GamePlay;

use App\Enums\BonusFlow;
use App\Enums\ClientProtocol;
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
        return (int) ($this->template->reel_count ?: 5);
    }

    public function rowCount(): int
    {
        return (int) ($this->template->row_count ?: 3);
    }

    public function symbolCount(): int
    {
        return (int) ($this->template->symbol_count ?: 9);
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

    public function lineCount(): int
    {
        return count($this->paylines());
    }

    // ---- symbols ----------------------------------------------------

    public function wildSymbol(): ?int
    {
        return $this->template->wild_symbol !== null ? (int) $this->template->wild_symbol : null;
    }

    public function scatterSymbol(): ?int
    {
        return $this->template->scatter_symbol !== null ? (int) $this->template->scatter_symbol : null;
    }

    public function bonusSymbol(): ?int
    {
        return $this->template->bonus_symbol !== null ? (int) $this->template->bonus_symbol : null;
    }

    public function wildMultiplier(): int
    {
        return (int) ($this->game->wild_multiplier ?? $this->template->wild_multiplier ?: 1);
    }

    /** Smallest paying run left-to-right (legacy games mostly 3; EGT "Action Money" pays 2). */
    public function minMatch(): int
    {
        return max(2, (int) ($this->inherited('min_match') ?: 3));
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

    /** @return array<string, mixed> category defaults ← template ← game override */
    public function bonusConfig(): array
    {
        return array_replace_recursive(
            (array) data_get($this->categoryConfig(), 'bonus_config', []),
            (array) ($this->template->bonus_config ?? []),
            (array) ($this->game->getAttribute('bonus_config') ?? []),
        );
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

    public function denomination(): float
    {
        return (float) ($this->game->denomination ?: $this->template->default_denomination ?: 1);
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
