<?php

namespace App\Services\RoyalSpin;

use App\Enums\BankType;
use App\Enums\ClientProtocol;
use App\Enums\Currency;
use App\Enums\GameEngine;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameDesign;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\BundleManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * The admin Game Builder's engine room: turns a {@see GameDesign} (data an
 * admin edits — grid, symbols + pays, features, look & feel, uploaded art)
 * into a playable RoyalSpin game:
 *
 *   math()      → the game_templates columns the server engines read
 *                 (paytable, generated reel strips + paylines, win chances,
 *                 tumble_config for cascade games)
 *   clientMeta()→ the bundle's game.json (skin, theme, symbol names, which
 *                 picture is which, feature text)
 *   publish()   → assemble engine + art into a bundle (BundleManager), upsert
 *                 the template, add the game to shops + the RoyalSpin category
 *
 * Line games run on LineSlotServer, cascade games on CascadeSlotServer — no
 * per-game code. Art comes from an {@see ArtPacks} pack, overridden per item
 * by uploads on the `public` disk.
 */
class GameBuilder
{
    public const array SKINS = [
        'classic' => 'Classic — centred board, rounded gold frame',
        'olympus' => 'Olympus — temple frame, character beside the board, side panel',
    ];

    public const array ROLES = [
        'regular' => 'Regular (pays)',
        'wild' => 'Wild (substitutes)',
        'scatter' => 'Scatter (pays anywhere / triggers free spins)',
        'multiplier' => 'Multiplier orb (cascade only)',
    ];

    public const array FONTS = [
        "Georgia,'Times New Roman',serif" => 'Georgia (classic serif)',
        "'Trebuchet MS','Arial Rounded MT Bold',Verdana,sans-serif" => 'Rounded (candy)',
        "Impact,'Arial Black',sans-serif" => 'Impact (bold)',
        "'Palatino Linotype','Book Antiqua',Palatino,serif" => 'Palatino (ancient)',
        "'Segoe UI',system-ui,sans-serif" => 'Modern sans',
    ];

    /** 1-in-N base odds per volatility: [spin win, feature]. */
    private const array ODDS = [
        'low' => [4, 140],
        'medium' => [5, 160],
        'high' => [4, 200],
    ];

    /** Hand-picked 5x3 lines first (the familiar order); generated lines follow. */
    private const array LINES_5X3 = [
        [1, 1, 1, 1, 1], [0, 0, 0, 0, 0], [2, 2, 2, 2, 2], [0, 1, 2, 1, 0], [2, 1, 0, 1, 2],
        [0, 0, 1, 2, 2], [2, 2, 1, 0, 0], [1, 0, 0, 0, 1], [1, 2, 2, 2, 1], [1, 0, 1, 2, 1],
        [1, 2, 1, 0, 1], [0, 1, 1, 1, 0], [2, 1, 1, 1, 2], [0, 1, 0, 1, 0], [2, 1, 2, 1, 2],
        [1, 1, 0, 1, 1], [1, 1, 2, 1, 1], [0, 0, 2, 0, 0], [2, 2, 0, 2, 2], [0, 2, 2, 2, 0],
    ];

    public function __construct(private readonly ArtPacks $packs, private readonly BundleManager $bundles) {}

    // ================================================================ design data

    /**
     * Symbols, normalised: list indexed by symbol id.
     *
     * @return list<array{name:string, role:string, art:?string, image:?string, pays:list<float>, weight:int, weight_free:?int}>
     */
    public function symbols(GameDesign $d): array
    {
        $out = [];
        foreach (array_values((array) $d->symbols) as $s) {
            $out[] = [
                'name' => trim((string) ($s['name'] ?? '')) ?: 'Symbol '.(count($out) + 1),
                'role' => (string) ($s['role'] ?? 'regular'),
                'art' => isset($s['art']) && $s['art'] !== '' ? (string) $s['art'] : null,
                'image' => self::firstPath($s['image'] ?? null),
                'pays' => self::numbers($s['pays'] ?? []),
                'weight' => max(0, (int) ($s['weight'] ?? 1)),
                'weight_free' => isset($s['weight_free']) && $s['weight_free'] !== '' ? max(0, (int) $s['weight_free']) : null,
            ];
        }

        return $out;
    }

    /** The id of the (first) symbol with this role, or null. */
    public function roleSymbol(GameDesign $d, string $role): ?int
    {
        foreach ($this->symbols($d) as $id => $s) {
            if ($s['role'] === $role) {
                return $id;
            }
        }

        return null;
    }

    /** @return list<int> */
    public function tiers(GameDesign $d): array
    {
        $t = array_map('intval', self::numbers($d->setting('tiers', '8, 10, 12')));

        return $t ?: [8, 10, 12];
    }

    /** @return list<float> */
    public function betOptions(GameDesign $d): array
    {
        return array_values(array_filter(self::numbers($d->setting('bet_options', '1, 2, 5, 10, 20, 50, 100')), fn ($v) => $v > 0));
    }

    // ================================================================ validation

    /** @return list<string> human-readable problems; empty = publishable */
    public function validate(GameDesign $d): array
    {
        $e = [];
        $syms = $this->symbols($d);
        $cascade = $d->isCascade();

        if (! preg_match('/^[A-Za-z][A-Za-z0-9]{2,40}$/', $d->code)) {
            $e[] = 'Code must be 3–41 letters/digits and start with a letter (e.g. OlympusThunderRS).';
        }
        $existing = GameTemplate::where('code', $d->code)->first();
        if ($existing && ($existing->layout['provider'] ?? null) !== 'RoyalSpin') {
            $e[] = "Code {$d->code} already belongs to a provider game — pick another code.";
        }
        $other = GameDesign::where('code', $d->code)->whereKeyNot($d->getKey())->exists();
        if ($other) {
            $e[] = "Another design already uses the code {$d->code}.";
        }
        if (! in_array($d->mechanic, [GameDesign::MECHANIC_LINES, GameDesign::MECHANIC_CASCADE], true)) {
            $e[] = 'Unknown mechanic.';
        }
        if ($d->reel_count < 3 || $d->reel_count > 8) {
            $e[] = 'Reels must be 3–8.';
        }
        if ($d->row_count < ($cascade ? 3 : 1) || $d->row_count > 8) {
            $e[] = $cascade ? 'Rows must be 3–8 for a cascade game.' : 'Rows must be 1–8.';
        }
        if (! isset(self::SKINS[$d->skin])) {
            $e[] = 'Unknown skin.';
        }

        if (count($syms) < 3) {
            $e[] = 'Add at least 3 symbols.';
        }
        $roles = array_count_values(array_column($syms, 'role'));
        foreach (['wild', 'scatter', 'multiplier'] as $r) {
            if (($roles[$r] ?? 0) > 1) {
                $e[] = "Only one {$r} symbol is allowed.";
            }
        }
        if ($cascade && ($roles['wild'] ?? 0) > 0) {
            $e[] = 'Cascade (pay anywhere) games have no wild — change the wild to a regular symbol.';
        }
        if (! $cascade && ($roles['multiplier'] ?? 0) > 0) {
            $e[] = 'Multiplier orbs only work in cascade games.';
        }
        $paying = array_filter($syms, fn ($s) => in_array($s['role'], ['regular', 'wild'], true) && max([0, ...$s['pays']]) > 0);
        if (count($paying) < 2) {
            $e[] = 'At least two regular symbols need a pay value above 0.';
        }
        foreach ($syms as $id => $s) {
            $label = "Symbol #{$id} ({$s['name']})";
            if (! $s['image'] && ! ($d->art_pack && $s['art'] !== null && $this->packs->symbolPath($d->art_pack, $s['art']))) {
                $e[] = "{$label}: pick art from the pack or upload a picture.";
            }
            if ($s['image'] && ! Storage::disk('public')->exists($s['image'])) {
                $e[] = "{$label}: the uploaded picture is missing — upload it again.";
            }
            if ($s['role'] !== 'multiplier' && $s['weight'] <= 0 && ($s['weight_free'] ?? 0) <= 0) {
                $e[] = "{$label}: weight must be above 0 (how often it lands).";
            }
            if (! $cascade && count($s['pays']) > $d->reel_count + 1) {
                $e[] = "{$label}: has ".count($s['pays']).' pay values but the board only has '.$d->reel_count.' reels (values = 0, 1, 2 … '.$d->reel_count.' in a row).';
            }
        }

        if ($cascade) {
            $tiers = $this->tiers($d);
            $sorted = $tiers;
            sort($sorted);
            if ($tiers !== $sorted || $tiers[0] < 3) {
                $e[] = 'Pay tiers must be ascending and start at 3 or more (e.g. "8, 10, 12").';
            }
            if (max($tiers) > $d->reel_count * $d->row_count) {
                $e[] = 'A pay tier is larger than the board ('.($d->reel_count * $d->row_count).' cells).';
            }
            $scatter = $this->roleSymbol($d, 'scatter');
            if ($scatter === null) {
                $e[] = 'A cascade game needs a scatter symbol (it triggers the free spins).';
            }
            if ((int) $d->setting('trigger', 4) < 1 || (int) $d->setting('free_spins', 10) < 1) {
                $e[] = 'Free spins trigger count and number of free spins must be at least 1.';
            }
            if ($this->roleSymbol($d, 'multiplier') !== null && self::numbers($d->setting('multiplier_values', '')) === []) {
                $e[] = 'Multiplier values are empty (e.g. "2, 3, 5, 10, 25, 100").';
            }
            if ((float) $d->setting('buy_feature', 0) > 0 && (float) $d->setting('buy_feature', 0) < 10) {
                $e[] = 'Feature buy price is × total bet — use at least 10 (100 is typical).';
            }
            if ((float) $d->setting('ante_bet', 0) > 0 && ((float) $d->setting('ante_bet') <= 1 || (float) $d->setting('ante_bet') > 3)) {
                $e[] = 'Ante bet factor must be between 1 and 3 (e.g. 1.25).';
            }
        } else {
            $available = count($this->allPaylines($d->reel_count, $d->row_count));
            $lines = $this->paylines($d);
            if ($d->paylines) {
                foreach ($lines as $i => $line) {
                    if (count($line) !== $d->reel_count || max($line) >= $d->row_count || min($line) < 0) {
                        $e[] = 'Custom payline '.($i + 1).' must list '.$d->reel_count.' row numbers between 0 and '.($d->row_count - 1).'.';
                    }
                }
            } elseif ((int) $d->setting('payline_count', 10) > $available) {
                $e[] = "A {$d->reel_count}x{$d->row_count} board has {$available} distinct lines — lower the payline count.";
            }
            if ($lines === []) {
                $e[] = 'At least one payline is needed.';
            }
        }

        if ($this->betOptions($d) === []) {
            $e[] = 'Bet options are empty.';
        }
        if ((float) $d->setting('denomination', 0.01) <= 0) {
            $e[] = 'Denomination must be above 0.';
        }

        return $e;
    }

    // ================================================================ math

    /** @return array<string, mixed> game_templates columns */
    public function math(GameDesign $d): array
    {
        $syms = $this->symbols($d);
        $ids = array_keys($syms);
        $cascade = $d->isCascade();
        $wild = $this->roleSymbol($d, 'wild');
        $scatter = $this->roleSymbol($d, 'scatter');
        $bomb = $this->roleSymbol($d, 'multiplier');
        $volatility = in_array($d->setting('volatility'), ['low', 'medium', 'high'], true) ? $d->setting('volatility') : 'medium';

        $math = [
            'reel_count' => $d->reel_count,
            'row_count' => $d->row_count,
            'symbols' => $ids,
            'symbol_count' => count($ids),
            'wild_symbol' => $wild,
            'scatter_symbol' => $scatter,
            'volatility' => $volatility,
            'win_chances' => $this->winChances($d, $volatility),
            'default_bet_options' => $this->betOptions($d),
            'default_denomination' => (float) $d->setting('denomination', 0.01),
            'free_spins_table' => null,
            'tumble_config' => null,
        ];

        if ($cascade) {
            $lines = max(1, (int) $d->setting('cascade_lines', 20));
            $tiers = $this->tiers($d);
            $paytable = [];
            foreach ($syms as $id => $s) {
                $row = array_fill(0, count($tiers), 0);
                if ($s['role'] === 'regular') {
                    foreach ($tiers as $i => $_) {
                        $row[$i] = round(($s['pays'][$i] ?? 0) * $lines, 4);   // × total bet → bet units
                    }
                }
                $paytable[$id] = $row;
            }
            $scatterPays = [];
            foreach (self::pairs($d->setting('scatter_pays', '')) as $count => $coef) {
                $scatterPays[$count] = round($coef * $lines, 4);
            }
            $freeSpins = max(1, (int) $d->setting('free_spins', 10));

            return array_merge($math, [
                'wild_multiplier' => 1,
                'min_match' => 3,
                'paytable' => $paytable,
                'paylines' => [],
                'reel_strips' => $this->strips($d, $syms, $bomb, (bool) $d->setting('multiplier_in_base', false)),
                'has_bonus' => true,
                'has_free_spins' => true,
                'free_spins_count' => $freeSpins,
                'free_spins_multiplier' => 1,
                'has_gamble' => false,
                'gamble_win_chance' => 2,
                'tumble_config' => [
                    'lines' => $lines,
                    'tiers' => $tiers,
                    'scatter_pays' => $scatterPays ?: [(int) $d->setting('trigger', 4) => $lines],
                    'trigger' => max(1, (int) $d->setting('trigger', 4)),
                    'retrigger' => max(1, (int) $d->setting('retrigger', 3)),
                    'retrigger_spins' => max(0, (int) $d->setting('retrigger_spins', 5)),
                    'free_spins' => $freeSpins,
                    'multiplier_symbol' => $bomb,
                    'multiplier_values' => array_map('intval', self::numbers($d->setting('multiplier_values', '2, 3, 5, 10')) ?: [2]),
                    'multiplier_in_base' => $bomb !== null && (bool) $d->setting('multiplier_in_base', false),
                    'multiplier_accumulate' => $bomb !== null && (bool) $d->setting('multiplier_accumulate', false),
                    'buy_feature' => max(0, (float) $d->setting('buy_feature', 0)),
                    'ante_bet' => max(0, (float) $d->setting('ante_bet', 0)),
                    'ante_bonus_factor' => 2,
                ],
            ]);
        }

        $paytable = [];
        $minMatch = 5;
        foreach ($syms as $id => $s) {
            $row = array_fill(0, $d->reel_count + 1, 0);
            foreach (array_slice($s['pays'], 0, $d->reel_count + 1) as $n => $v) {
                $row[$n] = $v;
                if ($v > 0 && $n >= 2 && $s['role'] !== 'scatter') {
                    $minMatch = min($minMatch, $n);
                }
            }
            $row[0] = 0;
            $paytable[$id] = $row;
        }
        $fsOn = $scatter !== null && (bool) $d->setting('free_spins_enabled', false);
        $fsCount = max(1, (int) $d->setting('free_spins_count', 10));
        $table = null;
        if ($fsOn) {
            $table = array_fill(0, $d->reel_count + 1, 0);
            $pairs = self::pairs($d->setting('free_spins_table', ''));
            for ($n = 3; $n <= $d->reel_count; $n++) {
                $table[$n] = (int) ($pairs[$n] ?? ($pairs !== [] ? max($pairs) : $fsCount));
            }
        }

        return array_merge($math, [
            'wild_multiplier' => max(1, (int) $d->setting('wild_multiplier', 1)),
            'min_match' => min(3, $minMatch),
            'paytable' => $paytable,
            'paylines' => $this->paylines($d),
            'reel_strips' => $this->strips($d, $syms, null, false, $fsOn),
            'has_bonus' => $scatter !== null,
            'has_free_spins' => $fsOn,
            'free_spins_count' => $fsOn ? $fsCount : 0,
            'free_spins_table' => $table,
            'free_spins_multiplier' => max(1, (int) $d->setting('free_spins_multiplier', 1)),
            'has_gamble' => (bool) $d->setting('gamble_enabled', false),
            'gamble_win_chance' => 2,
        ]);
    }

    /** @return array<string, array<string, array<string, int>>> win_chances in the legacy band layout */
    private function winChances(GameDesign $d, string $volatility): array
    {
        [$hit, $feature] = self::ODDS[$volatility];
        $hit = max(1, (int) ($d->setting('hit_every') ?: $hit));
        $feature = max(2, (int) ($d->setting('feature_every') ?: $feature));
        $out = ['spin' => [], 'bonus' => []];
        foreach ([1, 3, 5, 7, 9, 10] as $b) {
            $out['spin']["line{$b}"] = ['74_80' => $hit + 1, '82_88' => $hit, '90_96' => max(1, $hit - 1)];
            $out['bonus']["line{$b}"] = ['74_80' => (int) round($feature * 1.25), '82_88' => (int) round($feature * 1.1), '90_96' => $feature];
        }

        return $out;
    }

    /**
     * Reel strips from the symbol weights: a seeded shuffle (same design →
     * same strips) with runs of 3+ identical symbols broken up.
     *
     * @param  list<array<string, mixed>>  $syms
     * @return array<string, list<int>>
     */
    private function strips(GameDesign $d, array $syms, ?int $bomb, bool $bombInBase, bool $bonusStrips = true): array
    {
        $out = [];
        foreach (['reelStrip' => false, 'reelStripBonus' => true] as $prefix => $free) {
            if ($free && ! $bonusStrips) {
                continue;
            }
            $weights = [];
            foreach ($syms as $id => $s) {
                if ($id === $bomb && ! $free && ! $bombInBase) {
                    continue;
                }
                $w = $free && $s['weight_free'] !== null ? $s['weight_free'] : $s['weight'];
                if ($w > 0) {
                    $weights[$id] = $w;
                }
            }
            for ($r = 0; $r < $d->reel_count; $r++) {
                $out[$prefix.($r + 1)] = $this->strip($weights, crc32($d->code.'|'.$prefix.'|'.$r));
            }
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $weights
     * @return list<int>
     */
    private function strip(array $weights, int $seed): array
    {
        $rng = new Randomizer(new Mt19937($seed));
        $bag = [];
        foreach ($weights as $sym => $n) {
            for ($i = 0; $i < $n; $i++) {
                $bag[] = (int) $sym;
            }
        }
        $bag = $rng->shuffleArray($bag);
        $len = count($bag);
        if ($len > 6) {
            for ($pass = 0; $pass < 4; $pass++) {
                for ($i = 2; $i < $len; $i++) {
                    if ($bag[$i] === $bag[$i - 1] && $bag[$i] === $bag[$i - 2]) {
                        $j = ($i + 3 + $rng->getInt(0, $len - 7)) % $len;
                        [$bag[$i], $bag[$j]] = [$bag[$j], $bag[$i]];
                    }
                }
            }
        }

        return $bag;
    }

    // ================================================================ paylines

    /** @return list<list<int>> the design's paylines (custom, else generated) */
    public function paylines(GameDesign $d): array
    {
        if ($d->isCascade()) {
            return [];
        }
        if (is_array($d->paylines) && $d->paylines !== []) {
            return array_values(array_map(fn ($l) => array_map('intval', (array) $l), $d->paylines));
        }

        return array_slice($this->allPaylines($d->reel_count, $d->row_count), 0, max(1, (int) $d->setting('payline_count', 10)));
    }

    /**
     * Every "smooth" line on a board (adjacent reels at most one row apart),
     * ordered the way players expect: straight rows from the middle out, the
     * V shapes, then the rest by how simple they look.
     *
     * @return list<list<int>>
     */
    public function allPaylines(int $reels, int $rows): array
    {
        $paths = [[]];
        for ($r = 0; $r < $reels; $r++) {
            $next = [];
            foreach ($paths as $p) {
                for ($row = 0; $row < $rows; $row++) {
                    if ($p === [] || abs($row - end($p)) <= 1) {
                        $next[] = [...$p, $row];
                    }
                }
            }
            $paths = $next;
            if (count($paths) > 20000) {
                break;   // huge boards: plenty of lines already
            }
        }

        $mid = ($rows - 1) / 2;
        $straight = range(0, $rows - 1);
        usort($straight, fn ($a, $b) => [abs($a - $mid), $a] <=> [abs($b - $mid), $b]);
        $first = array_map(fn ($row) => array_fill(0, $reels, $row), $straight);
        if ($reels === 5 && $rows === 3) {
            $first = self::LINES_5X3;
        }

        $score = function (array $p): array {
            $turns = 0;
            $dev = 0;
            for ($i = 1; $i < count($p); $i++) {
                $dev += abs($p[$i] - $p[$i - 1]);
                if ($i > 1 && ($p[$i] - $p[$i - 1]) !== ($p[$i - 1] - $p[$i - 2])) {
                    $turns++;
                }
            }
            $sym = $p === array_reverse($p) ? 0 : 1;

            return [$sym, $turns, $dev, implode('', $p)];
        };
        usort($paths, fn ($a, $b) => $score($a) <=> $score($b));

        $seen = [];
        $out = [];
        foreach ([...$first, ...$paths] as $p) {
            $k = implode(',', $p);
            if (count($p) === $reels && ! isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $p;
            }
        }

        return $out;
    }

    // ================================================================ client

    /**
     * The bundle's game.json.
     *
     * @param  array<string, string>  $files  role => bundle-relative path, from assemble()
     * @return array<string, mixed>
     */
    public function clientMeta(GameDesign $d, array $files): array
    {
        $syms = $this->symbols($d);
        $theme = (array) ($d->theme ?? []);
        $pack = $this->packs->get($d->art_pack) ?? ['theme' => []];
        $pt = (array) ($pack['theme'] ?? []);

        $meta = [
            'title' => $d->title,
            'mechanic' => $d->mechanic,
            'skin' => $d->skin,
            'theme' => array_filter([
                'accent' => $theme['accent'] ?? $pt['accent'] ?? '#ffd84a',
                'frame' => self::colors($theme['frame'] ?? null, 3) ?: ($pt['frame'] ?? null),
                'reelBg' => self::colors($theme['reel_bg'] ?? null, 2) ?: ($pt['reelBg'] ?? null),
                'lineColors' => self::colors($theme['line_colors'] ?? null) ?: ($pt['lineColors'] ?? null),
                'font' => $theme['font'] ?? $pt['font'] ?? null,
                'jewel' => $theme['jewel'] ?? null,
            ], fn ($v) => $v !== null && $v !== []),
            'symbols' => [],
            'symbolImages' => [],
            'assets' => [],
            'features' => $this->featureText($d),
        ];
        foreach ($syms as $id => $s) {
            $meta['symbols'][(string) $id] = $s['name'];
            if (isset($files["sym{$id}"])) {
                $meta['symbolImages'][(string) $id] = $files["sym{$id}"];
            }
        }
        foreach (['background', 'background_free', 'logo', 'character', 'music', 'music_free'] as $k) {
            if (isset($files[$k])) {
                $meta['assets'][$k] = $files[$k];
            }
        }
        if (isset($files['orbs'])) {
            $meta['orbs'] = json_decode($files['orbs'], true);
        }
        if ($note = trim((string) $d->setting('free_spins_note', ''))) {
            $meta['freeSpinsNote'] = $note;
        }

        return $meta;
    }

    /** @return list<string> the paytable's FEATURES page, written from the config */
    public function featureText(GameDesign $d): array
    {
        $syms = $this->symbols($d);
        $name = fn (?int $id) => $id === null ? null : mb_strtoupper($syms[$id]['name']);
        $wild = $name($this->roleSymbol($d, 'wild'));
        $scatter = $name($this->roleSymbol($d, 'scatter'));
        $bomb = $name($this->roleSymbol($d, 'multiplier'));
        $out = [];

        if ($d->isCascade()) {
            $tiers = $this->tiers($d);
            $out[] = "Symbols pay ANYWHERE: land {$tiers[0]} or more of the same symbol on the {$d->reel_count}x{$d->row_count} board to win.";
            $out[] = 'TUMBLE: winning symbols disappear, the rest fall down and new ones drop in — keep winning until nothing pays.';
            if ($scatter) {
                $pays = self::pairs($d->setting('scatter_pays', ''));
                $top = $pays === [] ? '' : ' pay up to '.max($pays).'x the total bet and';
                $out[] = "{$scatter} is the SCATTER: ".(int) $d->setting('trigger', 4)." or more anywhere{$top} award ".(int) $d->setting('free_spins', 10).' FREE SPINS. '.(int) $d->setting('retrigger', 3).' or more during free spins award '.(int) $d->setting('retrigger_spins', 5).' more.';
            }
            if ($bomb) {
                $vals = self::numbers($d->setting('multiplier_values', ''));
                $range = $vals ? ' carrying x'.min($vals).' – x'.max($vals) : '';
                $where = $d->setting('multiplier_in_base') ? 'land in the base game and in free spins' : 'land only in free spins';
                $out[] = "{$bomb}s {$where}{$range}. When a tumble sequence ends with a win, all multipliers on the board are added together and multiply the win.";
                if ($d->setting('multiplier_accumulate')) {
                    $out[] = 'In FREE SPINS every multiplier that hits joins the TOTAL MULTIPLIER, which then multiplies every winning tumble sequence until the feature ends.';
                }
            }
            if ((float) $d->setting('buy_feature', 0) > 0) {
                $out[] = 'BUY FREE SPINS: start the feature straight away for '.(float) $d->setting('buy_feature').'x the total bet.';
            }
            if ((float) $d->setting('ante_bet', 0) > 0) {
                $out[] = 'ANTE BET: bet '.(float) $d->setting('ante_bet').'x to double the chance of winning the free spins.';
            }
        } else {
            $lines = count($this->paylines($d));
            $out[] = "{$lines} fixed win lines on a {$d->reel_count}x{$d->row_count} board. Wins pay left to right on adjacent reels.";
            if ($wild) {
                $mult = (int) $d->setting('wild_multiplier', 1);
                $out[] = "{$wild} is WILD — it substitutes for every symbol except the scatter".($mult > 1 ? " and multiplies every win it completes by {$mult}" : '').'.';
            }
            if ($scatter) {
                $out[] = "{$scatter} is a SCATTER — it pays anywhere on the board.";
                if ($d->setting('free_spins_enabled')) {
                    $out[] = '3 or more scatters award FREE SPINS'.((int) $d->setting('free_spins_multiplier', 1) > 1 ? ' — all free-spin wins are multiplied by '.(int) $d->setting('free_spins_multiplier') : '').'.';
                }
            }
            if ($d->setting('gamble_enabled')) {
                $out[] = 'After any win, try the GAMBLE: guess the card colour to double your win (up to 5 times).';
            }
        }

        foreach (preg_split('/\R/', (string) $d->setting('features_extra', '')) ?: [] as $line) {
            if (trim($line) !== '') {
                $out[] = trim($line);
            }
        }

        return $out;
    }

    // ================================================================ publish

    /**
     * Build + publish: bundle, template, per-shop games, category, poster.
     *
     * @param  list<int>  $shopIds  shops to add the game to (empty = every shop)
     * @return array{template: GameTemplate, bundle: string, shops: int}
     */
    public function publish(GameDesign $d, array $shopIds = [], bool $applyBets = false, ?User $by = null): array
    {
        if ($errors = $this->validate($d)) {
            throw new RuntimeException(implode("\n", $errors));
        }

        $math = $this->math($d);
        $build = storage_path('app/royalspin-build/design-'.$d->getKey());
        File::deleteDirectory($build);
        File::ensureDirectoryExists($build);

        try {
            $files = $this->assemble($d, $build);
            $meta = $this->clientMeta($d, $files);
            File::put($build.'/game.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $hash = $this->hashDir($build);
            File::put($build.'/index.html', str_replace('{{BUILD}}', $hash, (string) File::get($build.'/index.html')));

            $category = Category::firstOrCreate(['slug' => 'royalspin'], ['title' => 'RoyalSpin', 'position' => 1]);
            $category->update(['config' => array_merge((array) $category->config, ['client_protocol' => ClientProtocol::Standard->value])]);

            $template = GameTemplate::updateOrCreate(['code' => $d->code], array_merge($math, [
                'title' => $d->title,
                'engine' => GameEngine::Internal,
                'device' => 'both',
                'bank_type' => BankType::Slots,
                'client_protocol' => ClientProtocol::Standard,
                'pricing_currency' => Currency::EUR,
                'poster_path' => $this->poster($d, $build, $files),
                'layout' => ['provider' => 'RoyalSpin', 'mechanic' => $d->mechanic, 'builder_design' => $d->getKey()],
                'is_active' => true,
            ]));

            $active = $template->activeBundle;
            if ($active && str_contains((string) $active->notes, $hash)) {
                $bundleNote = "v{$active->version} (unchanged)";
            } else {
                $bundle = $this->bundles->storeFromDirectory($template, $build, $by, entry: 'index.html', notes: "RoyalSpin Game Builder build {$hash}");
                $bundleNote = "v{$bundle->version} ({$bundle->file_count} files)";
            }
        } finally {
            File::deleteDirectory($build);
        }

        $shops = Shop::query()->when($shopIds !== [], fn ($q) => $q->whereIn('id', $shopIds))->get();
        foreach ($shops as $shop) {
            $game = Game::firstOrNew(['shop_id' => $shop->id, 'template_id' => $template->id]);
            $fresh = ! $game->exists;
            $game->title = $template->title;
            if ($fresh || $applyBets) {
                $game->fill([
                    'bank_type' => BankType::Slots,
                    'bet_options' => $template->default_bet_options,
                    'denomination' => $template->default_denomination,
                    'pricing_currency' => Currency::EUR,
                ]);
            }
            if ($fresh) {
                $game->fill(['reserve_percent' => 2, 'is_visible' => true]);
            }
            $game->save();
            $game->categories()->syncWithoutDetaching([$category->id]);
        }

        $d->forceFill(['template_id' => $template->id, 'published_at' => now(), 'build_hash' => $hash])->save();

        return ['template' => $template, 'bundle' => $bundleNote, 'shops' => $shops->count()];
    }

    /**
     * Copy engine + art + music into $dir; return role => bundle-relative path.
     *
     * @return array<string, string>
     */
    public function assemble(GameDesign $d, string $dir): array
    {
        File::copyDirectory(ArtPacks::root().'/engine', $dir);
        $files = [];
        $pack = $d->art_pack;
        $put = function (string $role, ?string $src, string $base) use (&$files, $dir): void {
            if (! $src || ! is_file($src)) {
                return;
            }
            $rel = $base.'.'.strtolower(pathinfo($src, PATHINFO_EXTENSION) ?: 'png');
            File::ensureDirectoryExists(dirname($dir.'/'.$rel));
            File::copy($src, $dir.'/'.$rel);
            $files[$role] = $rel;
        };
        $upload = fn (?string $path) => $path && Storage::disk('public')->exists($path) ? Storage::disk('public')->path($path) : null;
        $fromPack = fn (?string $key) => $pack ? $this->packs->path($pack, $this->packs->get($pack)[$key] ?? null) : null;
        $assets = (array) ($d->assets ?? []);

        foreach ($this->symbols($d) as $id => $s) {
            $put("sym{$id}", $upload($s['image']) ?? ($pack && $s['art'] !== null ? $this->packs->symbolPath($pack, $s['art']) : null), "img/sym/{$id}");
        }
        foreach (['background' => 'img/bg', 'background_free' => 'img/bg_free', 'music' => 'snd/music', 'music_free' => 'snd/music_free'] as $role => $base) {
            $put($role, $upload(self::firstPath($assets[$role] ?? null)) ?? $fromPack($role), $base);
        }
        if (! isset($files['background_free']) && isset($files['background'])) {
            $files['background_free'] = $files['background'];
        }
        if ($d->setting('show_character', $d->skin === 'olympus')) {
            $put('character', $upload(self::firstPath($assets['character'] ?? null)) ?? $fromPack('character'), 'img/character');
        }

        // logo: an upload, else a generated title logo in the skin's style
        $logo = $upload(self::firstPath($assets['logo'] ?? null));
        if ($logo) {
            $put('logo', $logo, 'img/logo');
        } else {
            $theme = (array) ($d->theme ?? []);
            File::ensureDirectoryExists($dir.'/img');
            File::put($dir.'/img/logo.svg', BuilderArt::logo($d->title, $theme['logo_style'] ?? ($d->skin === 'olympus' ? 'plaque' : 'gold'), $theme['accent'] ?? '#ffd76a'));
            $files['logo'] = 'img/logo.svg';
        }

        // multiplier orbs, one picture per value band (cascade + a multiplier symbol only)
        if ($d->isCascade() && $this->roleSymbol($d, 'multiplier') !== null && $pack) {
            $orbFiles = $this->packs->get($pack)['orbs'] ?? [];
            $bands = [5, 15, 50, null];
            $orbs = [];
            foreach (array_values($orbFiles) as $i => $rel) {
                $src = $this->packs->path($pack, $rel);
                if ($src && $i < count($bands)) {
                    $target = 'img/orb/'.basename($rel);
                    File::ensureDirectoryExists($dir.'/img/orb');
                    File::copy($src, $dir.'/'.$target);
                    $orbs[] = ['upTo' => $i === count($orbFiles) - 1 ? null : $bands[$i], 'file' => $target];
                }
            }
            if ($orbs !== []) {
                $files['orbs'] = (string) json_encode($orbs);
            }
        }

        return $files;
    }

    /** Lobby poster on the public disk: an upload, else composed from the game's art. */
    private function poster(GameDesign $d, string $build, array $files): ?string
    {
        $rel = 'game-posters/'.$d->code;
        $upload = self::firstPath(($d->assets ?? [])['poster'] ?? null);
        $disk = Storage::disk('public');
        foreach (['svg', 'png', 'jpg', 'jpeg', 'webp'] as $ext) {
            $disk->delete($rel.'.'.$ext);
        }

        if ($upload && $disk->exists($upload)) {
            $target = $rel.'.'.strtolower(pathinfo($upload, PATHINFO_EXTENSION));
            $disk->put($target, (string) $disk->get($upload));

            return $target;
        }
        if (! isset($files['background'], $files['logo'])) {
            return null;
        }

        // feature the three best-paying regular symbols
        $syms = $this->symbols($d);
        uasort($syms, fn ($a, $b) => max([0, ...$b['pays']]) <=> max([0, ...$a['pays']]));
        $top = [];
        foreach (array_keys($syms) as $id) {
            if (isset($files["sym{$id}"]) && in_array($syms[$id]['role'], ['regular', 'wild'], true) && count($top) < 3) {
                $top[] = $build.'/'.$files["sym{$id}"];
            }
        }
        $svg = BuilderArt::poster(
            $build.'/'.$files['background'],
            array_reverse($top),
            (string) File::get($build.'/'.$files['logo']),
            isset($files['character']) ? $build.'/'.$files['character'] : null,
        );
        $disk->put($rel.'.svg', $svg);

        return $rel.'.svg';
    }

    private function hashDir(string $dir): string
    {
        $paths = [];
        foreach (File::allFiles($dir) as $f) {
            $paths[str_replace('\\', '/', $f->getRelativePathname())] = $f->getPathname();
        }
        ksort($paths);
        $h = hash_init('sha256');
        foreach ($paths as $rel => $abs) {
            hash_update($h, $rel."\0".hash_file('sha256', $abs)."\n");
        }

        return substr(hash_final($h), 0, 16);
    }

    // ================================================================ import

    /**
     * Turn a shipped RoyalSpin game (resources/games/royalspin/games/<Code>)
     * into an editable design — same code, so publishing it re-skins that game.
     */
    public function importShippedGame(string $code, ?string $asCode = null): GameDesign
    {
        $dir = ArtPacks::root().'/games/'.$code;
        if (! is_file($dir.'/math.json') || ! is_file($dir.'/game.json')) {
            throw new RuntimeException("No shipped RoyalSpin game {$code}.");
        }
        $math = json_decode((string) file_get_contents($dir.'/math.json'), true, flags: JSON_THROW_ON_ERROR);
        $meta = json_decode((string) file_get_contents($dir.'/game.json'), true, flags: JSON_THROW_ON_ERROR);
        $tc = $math['tumble_config'] ?? null;
        $cascade = $tc !== null;
        $lines = (int) ($tc['lines'] ?? 1);

        $count = fn (array $strip) => array_count_values(array_map('intval', $strip));
        $base = $count($math['reel_strips']['reelStrip1'] ?? []);
        $free = isset($math['reel_strips']['reelStripBonus1']) ? $count($math['reel_strips']['reelStripBonus1']) : null;

        $symbols = [];
        foreach ($math['symbols'] as $id) {
            $role = match (true) {
                $id === ($math['wild_symbol'] ?? null) => 'wild',
                $id === ($math['scatter_symbol'] ?? null) => 'scatter',
                $cascade && $id === ($tc['multiplier_symbol'] ?? null) => 'multiplier',
                default => 'regular',
            };
            $pays = array_map('floatval', $math['paytable'][$id] ?? []);
            if ($cascade) {
                $pays = $role === 'regular' ? array_map(fn ($v) => round($v / max(1, $lines), 4), $pays) : [];
            }
            $symbols[] = [
                'name' => $meta['symbols'][(string) $id] ?? 'Symbol '.$id,
                'role' => $role,
                'art' => (string) $id,
                'image' => null,
                'pays' => $pays,
                'weight' => $base[$id] ?? 0,
                'weight_free' => $free !== null && ($free[$id] ?? 0) !== ($base[$id] ?? 0) ? ($free[$id] ?? 0) : null,
            ];
        }

        $settings = [
            'volatility' => $math['volatility'] ?? 'medium',
            'bet_options' => implode(', ', $math['default_bet_options'] ?? [1]),
            'denomination' => $math['default_denomination'] ?? 0.01,
            'hit_every' => $math['win_chances']['spin']['line10']['82_88'] ?? null,
            'feature_every' => isset($math['win_chances']['bonus']['line10']['90_96']) ? $math['win_chances']['bonus']['line10']['90_96'] : null,
        ];
        if ($cascade) {
            $settings += [
                'cascade_lines' => $lines,
                'tiers' => implode(', ', $tc['tiers']),
                'scatter_pays' => implode(', ', array_map(fn ($k, $v) => $k.':'.round($v / max(1, $lines), 4), array_keys($tc['scatter_pays']), $tc['scatter_pays'])),
                'trigger' => $tc['trigger'], 'free_spins' => $tc['free_spins'], 'retrigger' => $tc['retrigger'], 'retrigger_spins' => $tc['retrigger_spins'],
                'multiplier_values' => implode(', ', $tc['multiplier_values'] ?? []),
                'multiplier_in_base' => (bool) ($tc['multiplier_in_base'] ?? false),
                'multiplier_accumulate' => (bool) ($tc['multiplier_accumulate'] ?? false),
                'buy_feature' => $tc['buy_feature'] ?? 0,
                'ante_bet' => $tc['ante_bet'] ?? 0,
            ];
        } else {
            $table = $math['free_spins_table'] ?? null;
            $settings += [
                'payline_count' => count($math['paylines']),
                'wild_multiplier' => $math['wild_multiplier'] ?? 1,
                'free_spins_enabled' => (bool) ($math['has_free_spins'] ?? false),
                'free_spins_count' => $math['free_spins_count'] ?? 10,
                'free_spins_table' => $table ? implode(', ', array_filter(array_map(fn ($n, $v) => $v ? "{$n}:{$v}" : null, array_keys($table), $table))) : '',
                'free_spins_multiplier' => $math['free_spins_multiplier'] ?? 1,
                'gamble_enabled' => (bool) ($math['has_gamble'] ?? false),
            ];
        }
        $t = (array) ($meta['theme'] ?? []);

        return GameDesign::create([
            'code' => $asCode ?: $code,
            'title' => $meta['title'] ?? $math['title'] ?? $code,
            'mechanic' => $cascade ? GameDesign::MECHANIC_CASCADE : GameDesign::MECHANIC_LINES,
            'skin' => 'classic',
            'art_pack' => Str::kebab((string) preg_replace('/RS$/', '', $code)),
            'reel_count' => $math['reel_count'],
            'row_count' => $math['row_count'],
            'symbols' => $symbols,
            'paylines' => $cascade ? null : $math['paylines'],
            'settings' => $settings,
            'theme' => [
                'accent' => $t['accent'] ?? '#ffd84a',
                'frame' => $t['frame'] ?? null,
                'reel_bg' => $t['reelBg'] ?? null,
                'line_colors' => isset($t['lineColors']) ? implode(', ', $t['lineColors']) : null,
                'logo_style' => 'gold',
            ],
        ]);
    }

    // ================================================================ helpers

    /** "1, 2.5 3" | [1, "2.5"] → [1.0, 2.5, 3.0] */
    public static function numbers(mixed $v): array
    {
        if (is_string($v)) {
            $v = preg_split('/[\s,;]+/', trim($v)) ?: [];
        }

        return array_values(array_map('floatval', array_filter((array) $v, fn ($x) => is_numeric($x))));
    }

    /** "4:3, 5:5, 6:100" → [4 => 3.0, 5 => 5.0, 6 => 100.0] */
    public static function pairs(mixed $v): array
    {
        if (is_array($v)) {
            return array_map('floatval', $v);
        }
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim((string) $v)) ?: [] as $pair) {
            if (preg_match('/^(\d+)\s*[:=]\s*([\d.]+)$/', $pair, $m)) {
                $out[(int) $m[1]] = (float) $m[2];
            }
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> */
    private static function colors(mixed $v, ?int $exactly = null): array
    {
        if (is_string($v)) {
            $v = preg_match_all('/#[0-9a-fA-F]{3,8}|rgba?\([^)]*\)/', $v, $m) ? $m[0] : [];
        }
        $list = array_values(array_filter((array) $v, fn ($c) => is_string($c) && preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([\d\s.,%]+\))$/', $c)));
        if ($exactly !== null && count($list) !== $exactly) {
            return [];
        }

        return $list;
    }

    /** FileUpload state may be a string or a one-item array. */
    private static function firstPath(mixed $v): ?string
    {
        if (is_array($v)) {
            $v = reset($v);
        }

        return is_string($v) && $v !== '' ? $v : null;
    }
}
