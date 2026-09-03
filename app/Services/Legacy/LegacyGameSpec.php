<?php

namespace App\Services\Legacy;

use App\Models\GameTemplate;
use Illuminate\Support\Str;

/**
 * Best-effort reader for a legacy VanguardLTE game package
 * (`app/Games/<Code>/{SlotSettings.php,reels.txt,Server.php}`), turning the
 * hard-coded PHP into the DB-driven engine spec {@see GameTemplate}.
 *
 * The legacy files use symbol *names* (SYM_0, P_1, A, SCAT, …); everything here
 * is normalised to the integer indices our engine uses, keyed off the game's
 * own Paytable key order. Anything it can't parse is simply left out — the
 * generic ClassicSlot defaults then fill in.
 */
class LegacyGameSpec
{
    /** symbol name => 0-based index, in Paytable declaration order. */
    private array $symbolIndex = [];

    private function __construct(private readonly string $dir) {}

    public static function fromDir(string $dir): ?self
    {
        return is_dir($dir) ? new self($dir) : null;
    }

    /**
     * @return array<string, mixed> partial game_templates attributes
     */
    public function extract(): array
    {
        $settings = $this->read('SlotSettings.php');
        $server = $this->read('Server.php');

        if ($settings === null) {
            return [];
        }

        $paytableByName = $this->paytable($settings);
        $this->symbolIndex = array_flip(array_keys($paytableByName));

        $wild = $this->symbolFor(['WILD', 'SYM_WILD', 'W'], $settings, 'wild');
        $scatter = $this->symbolFor(['SCAT', 'SCATTER', 'SYM_SCATTER', 'BONUS', 'SYM_BONUS'], $settings, 'scatter');

        $reels = $this->reelStrips();
        $rows = $this->intProp('slotReelRows', $settings) ?? 3;
        $reelCount = $reels ? count(array_filter(
            array_keys($reels),
            fn ($k) => Str::startsWith($k, 'reelStrip') && ! Str::contains($k, 'Bonus'),
        )) : 5;

        $spec = array_filter([
            'reel_count' => $reelCount ?: 5,
            'row_count' => $rows,
            'symbol_count' => count($this->symbolIndex) ?: null,
            'symbols' => $this->symbolIndex ? array_values($this->symbolIndex) : null,
            'wild_symbol' => $wild,
            'scatter_symbol' => $scatter,
            'wild_multiplier' => $this->intProp('slotWildMpl', $settings),
            'min_match' => $this->minMatch($paytableByName),
            'has_free_spins' => $this->boolProp('slotFreeCount', $settings) || Str::contains($settings, 'freespin'),
            'free_spins_count' => $this->intProp('slotFreeCount', $settings),
            'gamble_type' => $this->intProp('GambleType', $settings),
            'has_gamble' => $this->boolProp('slotGamble', $settings, true),
            'has_bonus' => Str::contains($settings, ['slotBonus = true', "slotBonus'] = true", 'isBonusStart']),
            'paytable' => $this->reindexPaytable($paytableByName) ?: null,
            'reel_strips' => $this->reindexReels($reels) ?: null,
            'paylines' => $server ? $this->paylines($server, $rows) : null,
        ], fn ($v) => $v !== null && $v !== [] && $v !== false);

        return $spec;
    }

    // ---- paytable -------------------------------------------------

    /** @return array<string, list<float>> symbol name => payout per match count */
    private function paytable(string $php): array
    {
        // $this->Paytable['SYM_0'] = [ 0, 0, 5, 20, 100 ];   (multi-line)
        preg_match_all(
            "/Paytable\\[\\s*'?([A-Za-z0-9_]+)'?\\s*\\]\\s*=\\s*\\[([^\\]]*)\\]/s",
            $php,
            $m,
            PREG_SET_ORDER,
        );

        $out = [];
        foreach ($m as [$_, $sym, $body]) {
            $nums = array_map(
                'floatval',
                array_values(array_filter(
                    array_map('trim', explode(',', $body)),
                    fn ($v) => $v !== '' && is_numeric($v),
                )),
            );
            if ($nums !== []) {
                $out[$sym] = $nums;
            }
        }

        return $out;
    }

    /** @param array<string, list<float>> $paytable */
    private function reindexPaytable(array $paytable): array
    {
        $out = [];
        foreach ($paytable as $name => $row) {
            $out[$this->symbolIndex[$name] ?? count($out)] = $row;
        }

        return $out;
    }

    /** Smallest paying run across all symbols (EGT pays 2, most pay 3). */
    private function minMatch(array $paytable): int
    {
        $min = 5;
        foreach ($paytable as $row) {
            foreach ($row as $count => $pay) {
                if ($pay > 0) {
                    $min = min($min, max(2, (int) $count));
                    break;
                }
            }
        }

        return $min;
    }

    // ---- reels --------------------------------------------------

    /** @return array<string, list<int|string>> raw reelStrip name => symbol-name list */
    private function reelStrips(): array
    {
        $txt = $this->read('reels.txt');
        if ($txt === null) {
            return [];
        }

        $out = [];
        foreach (preg_split('/\R/', $txt) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$name, $body] = array_map('trim', explode('=', $line, 2));
            if (! Str::startsWith($name, 'reelStrip') || $body === '') {
                continue;
            }
            $out[$name] = array_map('trim', explode(',', $body));
        }

        return $out;
    }

    /** @param array<string, list<int|string>> $reels */
    private function reindexReels(array $reels): array
    {
        $out = [];
        foreach ($reels as $name => $strip) {
            $mapped = array_map(fn ($s) => $this->symbolIndex[$s] ?? (is_numeric($s) ? (int) $s : 0), $strip);
            if ($mapped !== []) {
                $out[$name] = $mapped;
            }
        }

        return $out;
    }

    // ---- paylines ----------------------------------------------

    /**
     * `$linesId[0] = [ 2, 2, 2, 2, 2 ];` in Server.php → 0-based row per reel.
     *
     * @return list<list<int>>|null
     */
    private function paylines(string $server, int $rows): ?array
    {
        preg_match_all(
            '/\$linesId\[\s*\d+\s*\]\s*=\s*\[([0-9,\s]+)\]/s',
            $server,
            $m,
        );

        $lines = [];
        foreach ($m[1] as $body) {
            $row = array_values(array_map(
                fn ($v) => max(0, (int) trim($v) - 1),
                array_filter(explode(',', $body), fn ($v) => trim($v) !== ''),
            ));
            if (count($row) >= 3) {
                $lines[] = $row;
            }
        }

        return $lines ?: null;
    }

    // ---- helpers ----------------------------------------------

    private function symbolFor(array $names, string $php, string $kind): ?int
    {
        foreach ($names as $n) {
            if (isset($this->symbolIndex[$n])) {
                return $this->symbolIndex[$n];
            }
        }

        // $this->SymbolWild = 'P_1';  /  $this->slotWildSymbol = 8;
        $prop = $kind === 'wild' ? 'SymbolWild|slotWildSymbol|WildSymbol' : 'SymbolScatter|slotScatterSymbol|ScatterSymbol';
        if (preg_match("/(?:{$prop})\\s*=\\s*'?([A-Za-z0-9_]+)'?/", $php, $mm)) {
            $v = $mm[1];

            return $this->symbolIndex[$v] ?? (is_numeric($v) ? (int) $v : null);
        }

        return null;
    }

    private function intProp(string $prop, string $php): ?int
    {
        return preg_match("/->{$prop}\\s*=\\s*(\\d+)/", $php, $m) ? (int) $m[1] : null;
    }

    private function boolProp(string $prop, string $php, bool $default = false): bool
    {
        if (preg_match("/->{$prop}\\s*=\\s*(true|false|\\d+)/", $php, $m)) {
            return $m[1] === 'true' || (is_numeric($m[1]) && (int) $m[1] > 0);
        }

        return $default;
    }

    private function read(string $file): ?string
    {
        $path = rtrim($this->dir, '/').'/'.$file;

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    // ---- raw SlotSettings config (for the live slotEvent protocol) -----

    /**
     * The bits of the legacy `SlotSettings.php` the `slotEvent` front-end reads
     * that the DB import didn't capture — symbol *names* and the cosmetic /
     * feature config. Parsed straight off the mirror, no DB round-trip.
     *
     * @return array{
     *   symbol_names: list<string>,
     *   wild: ?string, scatter: ?string,
     *   slot_reels_config: ?array, key_controller: ?array,
     *   slot_scatter_free_count: ?array, line: ?array, game_line: ?array,
     *   num_float: ?int, scale_mode: ?int, gamble_type: ?int,
     *   slot_free_count: ?int, slot_free_mpl: ?int, slot_wild_mpl: ?int,
     *   slot_bonus_type: ?int, slot_scatter_type: ?int,
     *   slot_bonus: bool, slot_gamble: bool, split_screen: bool,
     *   slot_view_state: ?string, slot_exit_url: string
     * }
     */
    public function rawConfig(): array
    {
        $php = $this->read('SlotSettings.php') ?? '';
        $names = array_keys($this->paytable($php));

        return [
            'symbol_names' => array_map('strval', $names),
            'wild' => $this->firstIn($names, ['WILD', 'SYM_WILD', 'W', 'P_1', 'WD']),
            'scatter' => $this->firstIn($names, ['SCAT', 'SCATTER', 'SYM_SCATTER', 'BONUS', 'SYM_BONUS', 'BON']),
            'slot_reels_config' => $this->arrayLiteral($php, 'slotReelsConfig'),
            'key_controller' => $this->arrayLiteral($php, 'keyController'),
            'slot_scatter_free_count' => $this->arrayLiteral($php, 'slotScatterFreeCount'),
            'line' => $this->arrayLiteral($php, 'Line'),
            'game_line' => $this->arrayLiteral($php, 'gameLine'),
            'num_float' => $this->intProp('numFloat', $php),
            'scale_mode' => $this->intProp('scaleMode', $php),
            'gamble_type' => $this->intProp('GambleType', $php),
            'slot_free_count' => $this->intProp('slotFreeCount', $php),
            'slot_free_mpl' => $this->intProp('slotFreeMpl', $php),
            'slot_wild_mpl' => $this->intProp('slotWildMpl', $php),
            'slot_bonus_type' => $this->intProp('slotBonusType', $php),
            'slot_scatter_type' => $this->intProp('slotScatterType', $php),
            'slot_bonus' => $this->boolProp('slotBonus', $php),
            'slot_gamble' => $this->boolProp('slotGamble', $php, true),
            'split_screen' => $this->boolProp('splitScreen', $php),
            'slot_view_state' => $this->stringProp('slotViewState', $php),
            'slot_exit_url' => $this->stringProp('slotExitUrl', $php) ?? '/',
        ];
    }

    /** @param list<string> $haystack */
    private function firstIn(array $haystack, array $needles): ?string
    {
        foreach ($needles as $n) {
            if (in_array($n, $haystack, true)) {
                return $n;
            }
        }

        return null;
    }

    private function stringProp(string $prop, string $php): ?string
    {
        return preg_match("/->{$prop}\\s*=\\s*'([^']*)'/", $php, $m) ? $m[1] : null;
    }

    /**
     * Read a `$this->Prop = [ … ];` literal (may span lines, may nest one level,
     * may use `'a' => 'b'`). Best-effort JSON conversion; null if unparsable.
     */
    private function arrayLiteral(string $php, string $prop): ?array
    {
        if (! preg_match("/->{$prop}\\s*=\\s*\\[/", $php, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $m[0][1] + strlen($m[0][0]) - 1;   // index of the opening '['
        $depth = 0;
        $end = null;
        for ($i = $start, $n = strlen($php); $i < $n; $i++) {
            $c = $php[$i];
            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                if (--$depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        if ($end === null) {
            return null;
        }

        $body = substr($php, $start, $end - $start + 1);
        $json = preg_replace(
            ['/\'/', '/\s*=>\s*/', '/,(\s*[\]\}])/', '/\bnull\b/i', '/\btrue\b/i', '/\bfalse\b/i'],
            ['"', ':', '$1', 'null', 'true', 'false'],
            $body,
        ) ?? $body;
        // associative literal -> object
        if (str_contains($json, '":')) {
            $json = '{'.substr($json, 1, -1).'}';
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
