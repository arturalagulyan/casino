<?php

namespace App\Services\GamePlay\Amatic;

use App\Models\Game;
use App\Models\GameTemplate;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameConfig;

/**
 * Settles a line game's base maths against Amatic's own server.
 *
 * Inputs: the live init (real reel strips + line count), live spins (reel
 * positions + the per-line win list in credits) and the client's own
 * paytable/paylines ({@see AmaticClientMath}). What the client doesn't
 * declare findably — which symbol is wild and scatter, how the paytable rows
 * map to symbols, wild ×2, expanding wilds, 2-scatter pays — is searched:
 * a candidate rule set is kept only if it reproduces every sampled spin
 * line-for-line. The winner is re-checked through the real {@see SlotEngine}
 * so the imported template pays exactly what was verified.
 *
 * @phpstan-import-type Init from AmaticFrameDecoder
 * @phpstan-import-type Spin from AmaticFrameDecoder
 * @phpstan-import-type ClientMath from AmaticClientMath
 */
class AmaticMathSolver
{
    private const int MAX_FITS = 40;

    /** Longest one rule search may run (a big symbol set × mixed groups explodes); what fitted by then is kept. */
    private const int SEARCH_SECONDS = 60;

    /** Fewest winning samples a rule set is verified on. */
    private const int MIN_WINNING = 5;

    /** The solve in progress lets a line's run start on any reel. */
    private bool $anyPosition = false;

    /** The solve in progress pays lines right to left as well. */
    private bool $bothWays = false;

    public function __construct(private readonly SlotEngine $engine) {}

    /**
     * @param  Init  $init
     * @param  list<Spin>  $spins
     * @param  ClientMath  $client
     * @param  array{wild?: ?int, scatter?: ?int}  $hints  legacy package's wild/scatter, used to break ties
     * @param  bool  $linesOnly  ignore the last win slot (for games whose extra slot pays a feature we don't play, e.g. coin values)
     * @param  array<int, int>|null  $alias  play these symbols as others on lines (cash symbols paying as a symbol), instead of the tall-symbol search
     * @param  bool  $tolerant  allow a few spins (≤ 5 %, ≤ 3) to miss when they all carry a client tail no fitting spin has —
     *                          a feature we don't play; the base game is still matched exactly on every other spin
     * @return array{rules: array<string, mixed>, fits: int, samples: int, winning: int, scatter_seen: bool, skipped?: list<string>}|null
     */
    public function solve(array $init, array $spins, array $client, array $hints = [], bool $linesOnly = false, bool $tolerant = false, ?int $mystery = null, ?array $block = null, bool $cellMult = false, bool $boardMult = false, bool $anyPosition = false, bool $bothWays = false, bool $reelMult = false, ?int $randomWild = null, ?array $alias = null, bool $reelSum = false, bool $mysteryReels = false): ?array
    {
        $this->anyPosition = $anyPosition;
        $this->bothWays = $bothWays;
        $reels = count($init['strips']);
        $rows = $client['rows'];
        $lines = $init['lines'];
        $symbols = range(0, max(array_merge(...$init['strips'])));
        $samples = [];
        foreach ($spins as $sp) {
            if (in_array($sp['state'], [3, 5, 9], true) && $sp['line_wins'] !== null) {
                $board = $this->board($init['strips'], $sp['rp'], $rows);
                // a mystery symbol turns into the symbol its spin's tail names
                if ($mystery !== null && $mysteryReels) {
                    // per reel: the tail names what it turned into on each reel
                    if (($perReel = $this->mysteryReelTail((string) ($sp['tail'] ?? ''), $reels)) === null) {
                        return null;
                    }
                    foreach ($board as $r => $col) {
                        $board[$r] = array_map(fn ($x) => $x === $mystery ? $perReel['symbols'][$r] : $x, $col);
                    }
                } elseif ($mystery !== null) {
                    $into = $this->tailNumber((string) ($sp['tail'] ?? ''));
                    if ($into === null) {
                        return null;
                    }
                    $board = array_map(fn ($col) => array_map(fn ($x) => $x === $mystery ? $into : $x, $col), $board);
                }
                // a giant wild covers the cells around each block symbol (the engine is checked on the landed board)
                $landed = $board;
                // random wilds: the cells the tail marks turned wild
                $marks = null;
                if ($randomWild !== null) {
                    if (($marks = $this->wildMarks((string) ($sp['tail'] ?? ''), $reels, $rows)) === null) {
                        return null;
                    }
                    foreach ($marks as $r => $column) {
                        foreach ($column as $row => $mark) {
                            $board[$r][$row] = $mark !== null ? $randomWild : $board[$r][$row];
                        }
                    }
                }
                if ($block !== null) {
                    foreach (GameConfig::blockCells($board, $block['symbol'], $block['size']) as $r => $column) {
                        foreach ($column as $row => $sym) {
                            $board[$r][$row] = $sym !== 0 ? $sym : $board[$r][$row];
                        }
                    }
                }
                $mults = null;
                if ($cellMult && ($mults = $this->cellMults((string) ($sp['tail'] ?? ''), $reels, $rows)) === null) {
                    return null;
                }
                $samples[] = ['board' => $board, 'landed' => $landed, 'wins' => array_pad(array_slice($sp['line_wins'], 0, $lines + 1), $lines + 1, 0),
                    'tail' => (string) json_encode($sp['tail'] ?? ''), 'mults' => $mults, 'bm' => 1, 'marks' => $marks];
                // the tail is the whole board's multiplier, two hex digits
                if ($boardMult) {
                    $t = (string) ($sp['tail'] ?? '');
                    if (strlen($t) !== 2 || ! ctype_xdigit($t) || hexdec($t) < 1) {
                        return null;
                    }
                    $samples[array_key_last($samples)]['bm'] = (int) hexdec($t);
                }
                // the tail names every reel's wild multiplier this spin
                if ($reelSum) {
                    if (($rs = $this->reelSumTail((string) ($sp['tail'] ?? ''), $reels)) === null) {
                        return null;
                    }
                    $samples[array_key_last($samples)]['rsum'] = $rs['values'];
                }
                // the tail is one number: the multiplier a full wild reel drew (1 = none)
                if ($reelMult) {
                    if (($m = $this->tailNumber((string) ($sp['tail'] ?? ''))) === null || $m < 1) {
                        return null;
                    }
                    $samples[array_key_last($samples)]['bm'] = $m;
                }
            }
        }
        if (count($samples) < 8) {
            return null;
        }
        if ($linesOnly) {
            foreach ($samples as &$sample) {
                $sample['wins'][$lines] = 0;
            }
            unset($sample);
        }
        $winning = count(array_filter($samples, fn ($s) => array_sum($s['wins']) > 0));
        // too few wins to tell any rule apart (Dia Muertos: every spin a feature)
        if ($winning < self::MIN_WINNING) {
            return null;
        }

        // candidate symbol → paytable-row maps: identity, plus any declared map that fits
        $rowsN = count($client['paytable']);
        $maps = [array_map(fn ($s) => $s < $rowsN ? $s : null, $symbols)];
        foreach ($client['maps'] as $m) {
            if (count($m) >= 3 && max($m) < $rowsN) {
                $maps[] = array_map(fn ($s) => $m[$s] ?? null, $symbols);
            }
        }

        // scatter candidates: where the scatter slot paid, the scatter showed ≥ 2 times
        $paidScatter = array_filter($samples, fn ($s) => $s['wins'][$lines] > 0);
        $scatters = [null, ...$symbols];
        foreach ($paidScatter as $s) {
            $scatters = array_values(array_filter($scatters, fn ($c) => $c !== null && $this->countOnBoard($s['board'], $c) >= 2));
        }
        if ($linesOnly) {
            $scatters = [null];
        }
        $wilds = $randomWild !== null ? [$randomWild] : $this->ordered([null, ...$symbols], $hints['wild'] ?? null);
        // after the hinted wild, try first the wilds most samples fit with on their own, so a big search reaches the right one in time
        if ($randomWild === null) {
            $plain = array_map(fn ($s) => $s < $rowsN ? [0, ...$client['paytable'][$s]] : array_fill(0, $reels + 1, 0), $symbols);
            $lineSamples = array_map(function ($s) use ($lines) {
                $s['wins'][$lines] = 0;

                return $s;
            }, $samples);
            $score = [];
            foreach ($symbols as $w) {
                $score[$w] = 0;
                foreach ([[1, 'once'], [2, 'once'], [2, 'each'], [3, 'once']] as [$wm, $each]) {
                    $score[$w] = max($score[$w], count(array_filter($lineSamples, fn ($one) => $this->fitsAll([$one], $plain, $client['paylines'], $lines, $w, null, $wm, false, 3, 1, $each))));
                }
            }
            $rest = array_values(array_filter($wilds, fn ($w) => $w !== null));
            usort($rest, fn ($a, $b) => $score[$b] <=> $score[$a]);
            $wilds = $this->ordered([null, ...$rest], $hints['wild'] ?? null);
        }
        $scatters = $this->ordered($scatters, $hints['scatter'] ?? null);
        $wildRules = [];
        foreach ([1, 2, 3, 1.5] as $wm) {
            foreach ([false, true] as $expand) {
                foreach ([1, 2] as $swm) {
                    foreach (is_int($wm) && $wm > 1 ? ['once', 'each', 'extra'] : ['once'] as $each) {
                        $wildRules[] = [$wm, $expand, $swm, $each];
                    }
                }
            }
        }
        // the full-screen bonus only shows on a sampled full screen
        $fullBoards = array_filter($samples, fn ($s) => count(array_unique(array_merge(...array_values($s['board'])))) === 1);
        $fullOptions = $fullBoards === [] ? [1] : [1, 2];
        // "any mix" pays: a run of adjacent symbols sharing one pay row (any mix of them pays it),
        // or a paytable row no strip symbol uses, paid by a mix of a range of symbols
        $mixedOptions = [null];
        for ($a = 0; $a < count($symbols); $a++) {
            $b = $a;
            while ($b + 1 < min(count($symbols), $rowsN) && $a < $rowsN && $client['paytable'][$b + 1] === $client['paytable'][$a] && array_sum($client['paytable'][$a]) > 0) {
                $b++;
            }
            if ($b > $a) {
                $mixedOptions[] = ['symbols' => range($a, $b), 'row' => $a];
                $a = $b;
            }
        }
        // one spare paying row: a mix of a range of symbols pays it (several spare rows are grouped below instead)
        $spareRows = count($symbols) >= $rowsN ? 0 : count(array_filter(array_slice($client['paytable'], count($symbols)), fn ($r) => array_sum($r) > 0));
        for ($row = count($symbols); $spareRows === 1 && $row < $rowsN; $row++) {
            if (array_sum($client['paytable'][$row]) > 0) {
                for ($a = 0; $a < count($symbols) - 1; $a++) {
                    for ($len = 2; $len <= 6 && $a + $len <= count($symbols); $len++) {
                        $mixedOptions[] = ['symbols' => range($a, $a + $len - 1), 'row' => $row];
                    }
                }
            }
        }
        // several such rows paid together, one per class of symbols alike mod their count
        // (Lucky Zodiac: any 5 signs of one element — symbols 4/8/12, 1/5/9, …; symbols from..to-1)
        $spare = count($symbols) >= $rowsN ? [] : array_values(array_filter(range(count($symbols), $rowsN - 1), fn ($row) => array_sum($client['paytable'][$row]) > 0));
        if (count($spare) >= 2) {
            foreach ([0, 1] as $from) {
                for ($to = $from + 2 * count($spare); $to <= count($symbols); $to++) {
                    $groups = [];
                    foreach ($spare as $k => $row) {
                        $groups[] = ['symbols' => array_values(array_filter(range($from, $to - 1), fn ($x) => ($x - $from) % count($spare) === $k)), 'row' => $row];
                    }
                    // a wild in the mix multiplies it as on a line (the usual Amatic rule) — else it only substitutes
                    $mixedOptions[] = ['groups' => $groups, 'wild_mult' => true];
                    $mixedOptions[] = ['groups' => $groups];
                }
            }
        }
        // the grouped options are few and specific: try them right after "none"
        $rank = fn (?array $m) => $m === null ? 0 : (isset($m['groups']) ? 1 : 2);
        usort($mixedOptions, fn ($x, $y) => $rank($x) <=> $rank($y));

        $fits = [];
        $deadline = microtime(true) + self::SEARCH_SECONDS;
        foreach ($alias !== null ? [$alias] : $this->aliasOptions($init['strips']) as $alias) {
            $aliased = $alias === [] ? $samples : array_map(fn ($s) => ['board' => array_map(fn ($col) => array_map(fn ($x) => $alias[$x] ?? $x, $col), $s['board'])] + $s, $samples);
            foreach ($maps as $mi => $map) {
                $pay = array_map(fn ($row) => $row === null ? array_fill(0, $reels + 1, 0) : [0, ...$client['paytable'][$row]], $map);
                foreach ($wilds as $wild) {
                    foreach ($wild === null ? [[1, false, 1, 'once']] : $wildRules as [$wm, $expand, $swm, $each]) {
                        foreach ($fullOptions as $fb) {
                            foreach ($scatters as $scatter) {
                                if (microtime(true) > $deadline) {
                                    break 6;
                                }
                                foreach ($scatter === null ? [3] : [3, 2] as $scMin) {
                                    foreach ($mixedOptions as $mixed) {
                                        $mixedPay = $pay;
                                        foreach ($this->mixedGroups($mixed) as $g) {
                                            $mixedPay += [$g['row'] => [0, ...$client['paytable'][$g['row']]]];
                                        }
                                        foreach ($wild === null || $wild === 0 ? [null] : [null, 0] as $wildAs) {
                                            $missed = $tolerant
                                                ? $this->featureMisses($aliased, fn (array $one) => $this->fitsAll($one, $mixedPay, $client['paylines'], $lines, $wild, $scatter, $wm, $expand, $scMin, $swm, $each, $fb, $mixed, $wildAs))
                                                : ($this->fitsAll($aliased, $mixedPay, $client['paylines'], $lines, $wild, $scatter, $wm, $expand, $scMin, $swm, $each, $fb, $mixed, $wildAs) ? [] : null);
                                            if ($missed !== null) {
                                                $fits[] = compact('alias', 'mi', 'wild', 'wm', 'expand', 'swm', 'each', 'fb', 'scatter', 'scMin', 'mixed', 'wildAs', 'missed');
                                                if (count($fits) >= self::MAX_FITS) {
                                                    break 9;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        if ($fits === []) {
            return null;
        }

        $f = $fits[0];
        $paytable = array_map(fn ($row) => $row === null ? array_fill(0, $reels + 1, 0) : [0, ...$client['paytable'][$row]], $maps[$f['mi']]);
        foreach ($this->mixedGroups($f['mixed']) as $g) {
            $paytable[$g['row']] = [0, ...$client['paytable'][$g['row']]];
        }
        $rules = [
            'reels' => $reels, 'rows' => $rows, 'lines' => $lines, 'symbols' => $symbols,
            'paytable' => $paytable, 'paylines' => $client['paylines'],
            // a fractional wild multiplier (Hot Fruits 27: × 1.5) is kept as a factor beside a whole × 1
            'wild' => $f['wild'], 'wild_multiplier' => is_int($f['wm']) ? $f['wm'] : 1, 'expanding_wild' => $f['expand'],
            'wild_pay_factor' => is_int($f['wm']) ? null : $f['wm'],
            'scatter_wild_mult' => $f['swm'], 'symbol_alias' => $f['alias'],
            'wild_mult_each' => $f['each'], 'full_board_mult' => $f['fb'],
            'scatter' => $f['scatter'], 'scatter_min' => $f['scMin'],
            'mixed_pays' => $this->mixedGroups($f['mixed']),
            'mixed_wild_mult' => ($f['mixed']['wild_mult'] ?? false) ?: null,
            'wild_pays_as' => $f['wildAs'],
            'mystery' => $mystery === null ? null : ($mysteryReels ? $this->mysteryReelRule($mystery, $init['strips'], $spins, $reels)
                : ['symbol' => $mystery, 'targets' => $this->mysteryTargets($spins)]),
            'wild_block' => $block,
            'cell_mult' => $cellMult ? $this->cellMultRule($samples) : null,
            'wild_board_mult' => $boardMult ? $this->boardMultBase($samples, $f['wild']) : null,
            'wild_reel_mult' => $reelMult ? $this->reelMultRule($samples, $f['wild']) : null,
            'random_wilds' => $randomWild !== null ? $this->randomWildRule($samples, $spins) : null,
            'wild_reel_values' => $reelSum ? $this->reelSumRule($samples, $spins, $reels) : null,
            'line_any_position' => $anyPosition ?: null,
            'line_both_ways' => $bothWays ?: null,
        ];
        if (($boardMult && $rules['wild_board_mult'] === null) || ($reelMult && $rules['wild_reel_mult'] === null)
            || ($randomWild !== null && $rules['random_wilds'] === null) || ($reelSum && $rules['wild_reel_values'] === null)) {
            return null;
        }
        $kept = array_values(array_diff_key($samples, array_flip($f['missed'])));
        if (! $this->engineAgrees($rules, $kept)) {
            return null;
        }
        $skipped = array_values(array_unique(array_map(fn ($i) => $samples[$i]['tail'], $f['missed'])));

        return ['rules' => $rules, 'fits' => count($fits), 'samples' => count($kept), 'winning' => $winning, 'scatter_seen' => $paidScatter !== []]
            + ($skipped !== [] ? ['skipped' => $skipped] : []);
    }

    /**
     * A tail of number fields (`<length><hex>` each), else null.
     *
     * @return list<int>|null
     */
    public function tailNumbers(string $tail): ?array
    {
        $out = [];
        for ($i = 0; $i < strlen($tail);) {
            $len = ctype_xdigit($tail[$i]) ? (int) hexdec($tail[$i]) : -1;
            $field = substr($tail, $i + 1, max(0, $len));
            if ($len < 1 || strlen($field) !== $len || ! ctype_xdigit($field)) {
                return null;
            }
            $out[] = (int) hexdec($field);
            $i += 1 + $len;
        }

        return $out;
    }

    /**
     * A giant-wild rule the live tails prove: every spin's tail is one number
     * per cell, all non-zero ones the same symbol, and a block of 3 (or 2…5)
     * around each of that symbol on the board gives exactly those cells.
     *
     * @return array{symbol: int, size: int}|null
     */
    public function blockRule(array $init, array $spins, int $rows): ?array
    {
        $boards = [];
        $symbol = null;
        foreach ($spins as $sp) {
            if (! in_array($sp['state'], [3, 5, 9], true) || $sp['line_wins'] === null) {
                continue;
            }
            $vals = $this->tailNumbers((string) ($sp['tail'] ?? ''));
            if ($vals === null || count($vals) !== count($init['strips']) * $rows) {
                return null;
            }
            foreach (array_filter($vals) as $v) {
                if ($symbol !== null && $v !== $symbol) {
                    return null;
                }
                $symbol = $v;
            }
            $boards[] = [$this->board($init['strips'], $sp['rp'], $rows), $vals];
        }
        if ($symbol === null || count($boards) < 8) {
            return null;
        }
        foreach ([3, 2, 4, 5] as $size) {
            foreach ($boards as [$board, $vals]) {
                if (array_merge(...GameConfig::blockCells($board, $symbol, $size)) !== $vals) {
                    continue 2;
                }
            }

            return ['symbol' => $symbol, 'size' => $size];
        }

        return null;
    }

    /**
     * Per-cell multipliers from a tail of, per reel, the row count then one
     * digit per cell ("13" "121" …), else null.
     *
     * @return list<list<int>>|null
     */
    public function cellMults(string $tail, int $reels, int $rows): ?array
    {
        $per = 2 + $rows;
        if (strlen($tail) !== $reels * $per || ! ctype_xdigit($tail)) {
            return null;
        }
        $out = [];
        foreach (str_split($tail, $per) as $chunk) {
            if (substr($chunk, 0, 2) !== '1'.dechex($rows)) {
                return null;
            }
            $out[] = array_map('hexdec', str_split(substr($chunk, 2)));
        }

        return $out;
    }

    /**
     * The multiplier rule the live spins show: which symbols carried one
     * above × 1, and how often each multiplier landed on those symbols.
     *
     * @return array{symbols: list<int>, values: array<int, int>}
     */
    private function cellMultRule(array $samples): array
    {
        $seen = [];
        foreach ($samples as $s) {
            foreach ($s['landed'] as $r => $col) {
                foreach ($col as $row => $sym) {
                    $seen[$sym][] = $s['mults'][$r][$row] ?? 1;
                }
            }
        }
        $symbols = array_keys(array_filter($seen, fn ($m) => max($m) > 1));
        sort($symbols);
        $values = array_count_values(array_merge(...array_map(fn ($sym) => $seen[$sym], $symbols)));
        ksort($values);

        return ['symbols' => $symbols, 'values' => $values];
    }

    /**
     * The full-wild-reel rule the samples show: a multiplier above 1 exactly
     * when a reel landed all wild — and how often each one was drawn; else null.
     *
     * @return array{values: array<int, int>}|null
     */
    private function reelMultRule(array $samples, ?int $wild): ?array
    {
        if ($wild === null) {
            return null;
        }
        $values = [];
        foreach ($samples as $s) {
            $full = array_filter($s['landed'], fn ($col) => array_unique($col) === [$wild]);
            if (($full !== []) !== ($s['bm'] > 1)) {
                return null;
            }
            if ($full !== []) {
                $values[$s['bm']] = ($values[$s['bm']] ?? 0) + 1;
            }
        }
        ksort($values);

        return $values === [] ? null : ['values' => $values];
    }

    /**
     * Random-wild marks from a tail of a 2-character prefix, then per reel the
     * row count and one hex digit per cell ("f" = none), else null.
     *
     * @return list<list<string|null>>|null
     */
    public function wildMarks(string $tail, int $reels, int $rows): ?array
    {
        $per = 2 + $rows;
        if (strlen($tail) !== 2 + $reels * $per || ! ctype_xdigit($tail)) {
            return null;
        }
        $out = [];
        foreach (str_split(substr($tail, 2), $per) as $chunk) {
            if (substr($chunk, 0, 2) !== '1'.dechex($rows)) {
                return null;
            }
            $out[] = array_map(fn ($d) => $d === 'f' ? null : $d, str_split(substr($chunk, 2)));
        }

        return $out;
    }

    /**
     * The random-wild rule the live spins show: how often it fired, how many
     * cells it turned and which marks it drew; null when it never fired.
     *
     * @return array{chance: float, cells: array<int, int>, marks: array<string, int>, prefix: string}|null
     */
    private function randomWildRule(array $samples, array $spins): ?array
    {
        $cells = $marks = [];
        $fired = 0;
        foreach ($samples as $s) {
            $drawn = array_values(array_filter(array_merge(...$s['marks']), fn ($m) => $m !== null));
            if ($drawn !== []) {
                $fired++;
                $cells[count($drawn)] = ($cells[count($drawn)] ?? 0) + 1;
                foreach ($drawn as $m) {
                    $marks[$m] = ($marks[$m] ?? 0) + 1;
                }
            }
        }
        if ($fired === 0) {
            return null;
        }
        ksort($cells);
        ksort($marks);

        return ['chance' => round($fired / count($samples), 4), 'cells' => $cells, 'marks' => $marks,
            'prefix' => substr((string) ($spins[0]['tail'] ?? ''), 0, 2)];
    }

    /**
     * A per-reel wild-multiplier tail: groups of a count (`1<hex>`) and that
     * many digits; the group as long as the reels with no zero digit is the
     * draw — split into what comes before it, the draws and what follows; else null.
     *
     * @return array{prefix: string, values: list<int>, suffix: string}|null
     */
    public function reelSumTail(string $tail, int $reels): ?array
    {
        for ($i = 0; $i + 1 < strlen($tail);) {
            if ($tail[$i] !== '1' || ! ctype_xdigit($tail[$i + 1])) {
                return null;
            }
            $n = (int) hexdec($tail[$i + 1]);
            $digits = substr($tail, $i + 2, $n);
            if (strlen($digits) !== $n || ! ctype_digit($digits)) {
                return null;
            }
            if ($n === $reels && ! str_contains($digits, '0')) {
                return ['prefix' => substr($tail, 0, $i), 'values' => array_map('intval', str_split($digits)), 'suffix' => substr($tail, $i + 2 + $n)];
            }
            $i += 2 + $n;
        }

        return null;
    }

    /**
     * The per-reel wild-multiplier rule the samples show: how often each value
     * was drawn, and the tail around the draw (from the commonest shape); else null.
     *
     * @return array{values: array<int, int>, prefix: string, suffix: string}|null
     */
    private function reelSumRule(array $samples, array $spins, int $reels): ?array
    {
        $values = $shapes = [];
        foreach ($samples as $s) {
            foreach ($s['rsum'] ?? [] as $v) {
                $values[$v] = ($values[$v] ?? 0) + 1;
            }
        }
        foreach ($spins as $sp) {
            if (($t = $this->reelSumTail((string) ($sp['tail'] ?? ''), $reels)) !== null) {
                $key = $t['prefix'].'|'.$t['suffix'];
                $shapes[$key] = ($shapes[$key] ?? 0) + 1;
            }
        }
        if (count($values) < 2 || $shapes === []) {
            return null;
        }
        ksort($values);
        arsort($shapes);
        [$prefix, $suffix] = explode('|', (string) array_key_first($shapes));

        return ['values' => $values, 'prefix' => $prefix, 'suffix' => $suffix];
    }

    /** The base b with every sample's multiplier = b ^ (wilds on its board), else null. */
    private function boardMultBase(array $samples, ?int $wild): ?int
    {
        if ($wild === null) {
            return null;
        }
        $base = null;
        foreach ($samples as $s) {
            $n = $this->countOnBoard($s['landed'], $wild);
            if ($n > 0 && $base === null) {
                $base = (int) round($s['bm'] ** (1 / $n));
            }
        }
        foreach ($samples as $s) {
            if ($base === null || $base < 2 || $base ** $this->countOnBoard($s['landed'], $wild) !== $s['bm']) {
                return null;
            }
        }

        return $base;
    }

    /**
     * A per-reel mystery tail: the reel count (`1<hex>`), one hex digit per
     * reel, then whatever follows; else null.
     *
     * @return array{symbols: list<int>, suffix: string}|null
     */
    public function mysteryReelTail(string $tail, int $reels): ?array
    {
        if (! str_starts_with($tail, '1'.dechex($reels)) || strlen($tail) < 2 + $reels || ! ctype_xdigit(substr($tail, 2, $reels))) {
            return null;
        }

        return ['symbols' => array_map('hexdec', str_split(substr($tail, 2, $reels))), 'suffix' => substr($tail, 2 + $reels)];
    }

    /**
     * The per-reel mystery rule the live spins show: the reels that always
     * named the same symbol form a group (reels whose mystery never lands
     * are left out), how often each symbol was drawn and the tail's suffix.
     *
     * @param  list<list<int>>  $strips
     * @return array{symbol: int, targets: array<int, int>, groups: list<list<int>>, suffix: string}|null
     */
    private function mysteryReelRule(int $mystery, array $strips, array $spins, int $reels): ?array
    {
        $draws = $suffixes = [];
        foreach ($spins as $sp) {
            if (($t = $this->mysteryReelTail((string) ($sp['tail'] ?? ''), $reels)) !== null) {
                $draws[] = $t['symbols'];
                $suffixes[$t['suffix']] = ($suffixes[$t['suffix']] ?? 0) + 1;
            }
        }
        $groups = [];
        foreach (range(0, $reels - 1) as $r) {
            if (! in_array($mystery, $strips[$r], true)) {
                continue;
            }
            foreach ($groups as $g => $members) {
                if (array_filter($draws, fn ($d) => $d[$r] !== $d[$members[0]]) === []) {
                    $groups[$g][] = $r;

                    continue 2;
                }
            }
            $groups[] = [$r];
        }
        if ($groups === [] || $draws === []) {
            return null;
        }
        $targets = [];
        foreach ($draws as $d) {
            foreach ($groups as $members) {
                $targets[$d[$members[0]]] = ($targets[$d[$members[0]]] ?? 0) + 1;
            }
        }
        ksort($targets);
        arsort($suffixes);

        return ['symbol' => $mystery, 'targets' => $targets, 'groups' => $groups, 'suffix' => (string) array_key_first($suffixes)];
    }

    /** A tail that is one number field (`<length><hex>`, e.g. "13" = 3), else null. */
    public function tailNumber(string $tail): ?int
    {
        if ($tail === '' || ! ctype_xdigit($tail) || strlen($tail) !== 1 + (int) hexdec($tail[0])) {
            return null;
        }

        return (int) hexdec(substr($tail, 1));
    }

    /**
     * How often the mystery symbol turned into each symbol on the live spins.
     *
     * @return array<int, int>
     */
    private function mysteryTargets(array $spins): array
    {
        $out = [];
        foreach ($spins as $sp) {
            $v = $this->tailNumber((string) ($sp['tail'] ?? ''));
            if ($v !== null) {
                $out[$v] = ($out[$v] ?? 0) + 1;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Which samples miss under a rule set — when few enough (≤ 5 %, ≤ 3) and
     * every one carries a client tail no fitting sample has (a feature showing);
     * else null.
     *
     * @param  callable(list<array<string, mixed>>): bool  $fits
     * @return list<int>|null
     */
    private function featureMisses(array $samples, callable $fits): ?array
    {
        $limit = min(3, (int) floor(count($samples) * 0.05));
        $missed = [];
        foreach ($samples as $i => $s) {
            if (! $fits([$s])) {
                $missed[] = $i;
                if (count($missed) > $limit) {
                    return null;
                }
            }
        }
        if ($missed === []) {
            return [];
        }
        $fitTails = [];
        foreach ($samples as $i => $s) {
            if (! in_array($i, $missed, true)) {
                $fitTails[$s['tail']] = true;
            }
        }
        foreach ($missed as $i) {
            if (isset($fitTails[$samples[$i]['tail']])) {
                return null;
            }
        }

        return $missed;
    }

    /**
     * A "ways" game (All Ways family): no paylines — the live "lines" field is
     * the paying-symbol count and the win list has one slot per symbol (+ the
     * scatter). The paytable is the client's table with one row per symbol;
     * wild / doubler / scatter are searched like the line rules, evaluated by
     * the real engine's ways rule.
     *
     * @param  Init  $init
     * @param  list<Spin>  $spins
     * @param  list<list<int|float>>  $table
     * @return array{rules: array<string, mixed>, fits: int, samples: int, winning: int, scatter_seen: bool}|null
     */
    /** @param  int|list<int>  $rows  the rows, or each reel's height */
    public function solveWays(array $init, array $spins, array $table, int|array $rows = 3, bool $linesOnly = false, bool $copyReels = false): ?array
    {
        $paying = $init['lines'];
        if (count($table) < $paying || $init['win_slots'] < $paying) {
            return null;
        }
        $symbols = range(0, max(array_merge(...$init['strips'])));
        $samples = [];
        foreach ($spins as $sp) {
            if (in_array($sp['state'], [3, 5, 9], true) && $sp['line_wins'] !== null) {
                $samples[] = ['board' => $this->board($init['strips'], $sp['rp'], $rows), 'wins' => array_pad(array_slice($sp['line_wins'], 0, $init['win_slots']), $init['win_slots'], 0),
                    'tail' => (string) ($sp['tail'] ?? '')];
                // the tail marks the reel a full wild copied onto (00; every other reel 01)
                if ($copyReels) {
                    $t = (string) ($sp['tail'] ?? '');
                    if (strlen($t) !== 2 * count($init['strips']) || ! preg_match('/^(?:0[01])+$/', $t)) {
                        return null;
                    }
                    // two full wilds at once: a rarer case we leave out of the check
                    if (substr_count($t, '00') > 1) {
                        array_pop($samples);

                        continue;
                    }
                    $at = strpos($t, '00');
                    $samples[array_key_last($samples)]['copy'] = $at === false ? -1 : intdiv($at, 2);
                }
                // the slots past the paying symbols pay a feature we don't play (coin values) — ignore them
                if ($linesOnly) {
                    $samples[array_key_last($samples)]['wins'] = array_pad(array_slice($samples[array_key_last($samples)]['wins'], 0, $paying), $init['win_slots'], 0);
                }
            }
        }
        if (count($samples) < 8 || count(array_filter($samples, fn ($s) => array_sum($s['wins']) > 0)) < self::MIN_WINNING) {
            return null;
        }
        $reels = count($init['strips']);
        // tall symbols drawn as piece ids: each chain as one symbol, or every chain as the same one (tall wilds of several sizes)
        $chains = $this->chains($init['strips']);
        $aliasOptions = $this->aliasOptions($init['strips']);
        if (count($chains) > 1) {
            $aliasOptions[] = array_fill_keys(array_values(array_diff(array_merge(...$chains), [$chains[0][0]])), $chains[0][0]);
        }
        $paytable = array_map(fn ($s) => isset($table[$s]) ? [0, ...$table[$s]] : array_fill(0, $reels + 1, 0), $symbols);
        $fits = [];
        // every spin must fit; failing that, all but a few (≤ 5 %, ≤ 3) whose tails no fitting spin has — a feature we don't play
        // the last "paying" symbol may be the scatter, paid in its own slot (Lucky Fishing)
        $payingOptions = [$paying, ...(array_sum($table[$paying - 1] ?? []) > 0 ? [$paying - 1] : [])];

        foreach ([0, min(3, (int) floor(count($samples) * 0.05))] as $budget) {
            foreach ($payingOptions as $paying) {
                foreach ($aliasOptions as $alias) {
                    $aliased = $alias === [] ? $samples : array_map(fn ($s) => ['board' => array_map(fn ($col) => array_map(fn ($x) => $alias[$x] ?? $x, $col), $s['board'])] + $s, $samples);
                    foreach ([null, ...array_slice($symbols, $paying)] as $doubler) {
                        foreach ([null, ...$symbols] as $wild) {
                            foreach ($init['win_slots'] > $paying && ! $linesOnly ? array_slice($symbols, $paying) : [null] as $scatter) {
                                if ($scatter !== null && ($scatter === $wild || $scatter === $doubler)) {
                                    continue;
                                }
                                foreach ($scatter === null ? [null] : [null, 3] as $scMin) {
                                    $rules = ['reels' => $reels, 'rows' => $rows, 'lines' => $paying, 'symbols' => $symbols, 'paytable' => $paytable,
                                        'paylines' => null, 'wild' => $wild, 'scatter' => $scatter, 'scatter_min' => $scMin, 'symbol_alias' => $alias,
                                        'ways' => ['symbols' => range(0, $paying - 1), 'doubler' => $doubler, 'free_any_reel' => false],
                                        'wild_reel_copy' => $copyReels ? $this->copyReels($samples) : null];
                                    if (($missed = $this->waysMisses($rules, $aliased, $init['win_slots'], $budget)) !== null) {
                                        $fits[] = ['rules' => $rules, 'missed' => $missed];
                                    }
                                }
                            }
                        }
                    }
                    if ($fits !== []) {
                        break 3;
                    }
                }
            }
        }
        if ($fits === []) {
            return null;
        }
        $paying = $fits[0]['rules']['lines'];
        $missed = $fits[0]['missed'];
        $kept = array_values(array_diff_key($samples, array_flip($missed)));

        return ['rules' => $fits[0]['rules'], 'fits' => count($fits), 'samples' => count($kept),
            'winning' => count(array_filter($kept, fn ($s) => array_sum($s['wins']) > 0)),
            'scatter_seen' => (bool) array_filter($kept, fn ($s) => ($s['wins'][$paying] ?? 0) > 0)]
            + ($missed !== [] ? ['skipped' => array_values(array_unique(array_map(fn ($i) => $samples[$i]['tail'], $missed)))] : []);
    }

    /**
     * The samples a ways rule set misses — none, or up to `$budget` whose
     * tails no matching sample has (a feature showing); else null.
     *
     * @return list<int>|null
     */
    /**
     * The reels a full wild copies between: every reel a copy landed on or came from.
     *
     * @return array{reels: list<int>}
     */
    private function copyReels(array $samples): array
    {
        $reels = [];
        foreach ($samples as $s) {
            if (($s['copy'] ?? -1) >= 0) {
                $reels[] = $s['copy'];
            }
        }
        $reels = array_values(array_unique($reels));
        sort($reels);

        return ['reels' => $reels];
    }

    private function waysMisses(array $rules, array $samples, int $slots, int $budget = 0, bool $anyTails = false): ?array
    {
        $cfg = new GameConfig(new GameTemplate($this->waysTemplate($rules)), new Game(['denomination' => 1]));
        $missed = [];
        foreach ($samples as $i => $s) {
            $ev = $this->engine->evaluate($s['board'], $cfg, 1.0, 1, copyReel: $s['copy'] ?? null);
            $ours = array_fill(0, $slots, 0.0);
            foreach ($ev['lines'] as $w) {
                $ours[$w['line'] < 0 ? $rules['lines'] : $w['line']] += $w['amount'];
            }
            if (array_map(fn ($x) => (int) round($x), $ours) !== $s['wins']) {
                $missed[] = $i;
                if (count($missed) > $budget) {
                    return null;
                }
            }
        }
        $fitTails = array_map(fn ($i) => $samples[$i]['tail'] ?? '', array_diff(array_keys($samples), $missed));
        foreach ($anyTails ? [] : $missed as $i) {
            if (in_array($samples[$i]['tail'], $fitTails, true)) {
                return null;
            }
        }

        return $missed;
    }

    /**
     * Template columns for a solved ways game (strips added by the caller).
     *
     * @return array<string, mixed>
     */
    public function waysTemplate(array $rules): array
    {
        return [
            'reel_count' => $rules['reels'], 'row_count' => is_array($rules['rows']) ? max($rules['rows']) : $rules['rows'],
            'symbols' => $rules['symbols'], 'symbol_count' => count($rules['symbols']),
            'wild_symbol' => $rules['wild'], 'scatter_symbol' => $rules['scatter'], 'wild_multiplier' => 1, 'min_match' => 1,
            'paytable' => $rules['paytable'], 'paylines' => null, 'reel_strips' => [],
            'bonus_config' => ['ways' => $rules['ways']] + (is_array($rules['rows']) ? ['reel_heights' => $rules['rows']] : [])
                + (($rules['symbol_alias'] ?? []) !== [] ? ['symbol_alias' => $rules['symbol_alias']] : [])
                + (($rules['scatter_min'] ?? null) !== null ? ['scatter_min' => $rules['scatter_min']] : [])
                + (($rules['wild_reel_copy'] ?? null) !== null ? ['wild_reel_copy' => $rules['wild_reel_copy']] : []),
        ];
    }

    /** Row d of reel r is strip[rp-1+d] — rp is the middle row (legacy GetReelStrips), as the client rebuilds it. */
    /** @param  int|list<int>  $rows  the rows, or each reel's height (rombo games: 3-4-5-4-3) */
    private function board(array $strips, array $rp, int|array $rows): array
    {
        $board = [];
        foreach ($strips as $r => $strip) {
            $n = count($strip);
            for ($d = 0; $d < (is_array($rows) ? $rows[$r] : $rows); $d++) {
                $board[$r][$d] = $strip[((($rp[$r] ?? 0) - 1 + $d) % $n + $n) % $n];
            }
        }

        return $board;
    }

    private function countOnBoard(array $board, int $symbol): int
    {
        $n = 0;
        foreach ($board as $col) {
            foreach ($col as $s) {
                $n += $s === $symbol ? 1 : 0;
            }
        }

        return $n;
    }

    /** @param list<?int> $list */
    private function ordered(array $list, ?int $first): array
    {
        if ($first !== null && in_array($first, $list, true)) {
            $list = [$first, ...array_values(array_filter($list, fn ($x) => $x !== $first))];
        }

        return $list;
    }

    /**
     * Tall symbols drawn as consecutive piece ids show up in the strips as
     * links a→b where a is always followed by b and b always preceded by a.
     * Options: no aliasing, or every piece of a chain counts as its last /
     * first piece.
     *
     * @param  list<list<int>>  $strips
     * @return list<array<int, int>>
     */
    private function aliasOptions(array $strips): array
    {
        $chains = $this->chains($strips);
        if ($chains === []) {
            return [[]];
        }
        $toLast = $toFirst = [];
        foreach ($chains as $chain) {
            foreach ($chain as $piece) {
                if ($piece !== end($chain)) {
                    $toLast[$piece] = end($chain);
                }
                if ($piece !== $chain[0]) {
                    $toFirst[$piece] = $chain[0];
                }
            }
        }

        return [[], $toLast, $toFirst];
    }

    /**
     * Tall symbols drawn as consecutive piece ids: links a→b where a is always
     * followed by b and b always preceded by a, joined into chains.
     *
     * @param  list<list<int>>  $strips
     * @return list<list<int>>
     */
    private function chains(array $strips): array
    {
        $next = $prev = [];
        foreach ($strips as $strip) {
            $n = count($strip);
            foreach ($strip as $i => $s) {
                $t = $strip[($i + 1) % $n];
                $next[$s][$t] = true;
                $prev[$t][$s] = true;
            }
        }
        $link = [];
        foreach ($next as $a => $succ) {
            if (count($succ) === 1 && ($b = array_key_first($succ)) !== $a && count($prev[$b] ?? []) === 1) {
                $link[$a] = $b;
            }
        }
        $chains = [];
        foreach ($link as $a => $b) {
            if (in_array($a, $link, true)) {
                continue;   // not a chain head
            }
            $chain = [$a];
            while (isset($link[end($chain)]) && ! in_array($link[end($chain)], $chain, true)) {
                $chain[] = $link[end($chain)];
            }
            $chains[] = $chain;
        }

        return $chains;
    }

    /**
     * Tall symbols with no paytable row of their own that pay as a paying
     * symbol (Vampires: each 4-high vampire as one of the top symbols): every
     * chain → symbol (or no pay) map under which the plain lines fit the most
     * samples — all of them, or all but a few (≤ 5 %, ≤ 3: a feature we don't
     * play) — with the wild and scatter that fit best without them.
     *
     * @param  Init  $init
     * @param  list<Spin>  $spins
     * @param  ClientMath  $client
     * @return array{maps: list<array<int, int>>, missed: int}
     */
    public function tallTargets(array $init, array $spins, array $client): array
    {
        $rowsN = count($client['paytable']);
        // a chain headed by a paying symbol (Wild Hearts: 0 then pieces 14, 15, 16) pays as its head
        $fixed = [];
        foreach ($this->chains($init['strips']) as $chain) {
            if ($chain[0] < $rowsN && min(array_slice($chain, 1)) >= $rowsN) {
                $fixed += array_fill_keys(array_slice($chain, 1), $chain[0]);
            }
        }
        $chains = array_values(array_filter($this->chains($init['strips']), fn ($c) => min($c) >= $rowsN));
        if (($chains === [] && $fixed === []) || count($chains) > 4) {
            return ['maps' => [], 'missed' => 0];
        }
        $reels = count($init['strips']);
        $lines = $init['lines'];
        $symbols = range(0, max(array_merge(...$init['strips'])));
        $pay = array_map(fn ($s) => isset($client['paytable'][$s]) ? [0, ...$client['paytable'][$s]] : array_fill(0, $reels + 1, 0), $symbols);
        $samples = [];
        foreach ($spins as $sp) {
            if (in_array($sp['state'], [3, 5, 9], true) && $sp['line_wins'] !== null) {
                $samples[] = ['board' => $this->board($init['strips'], $sp['rp'], $client['rows']), 'wins' => array_pad(array_slice($sp['line_wins'], 0, $lines + 1), $lines + 1, 0)];
            }
        }
        $fitCount = fn (array $alias, ?int $wild, ?int $scatter) => count(array_filter($samples, fn ($s) => $this->fitsAll(
            [['board' => array_map(fn ($col) => array_map(fn ($x) => $alias[$x] ?? $x, $col), $s['board'])] + $s],
            $pay, $client['paylines'], $lines, $wild, $scatter, 1, false, 3)));
        // the wild and scatter: the pair most samples fit with the tall symbols paying nothing
        $best = [-1, null, null];
        foreach ([null, ...$symbols] as $wild) {
            foreach ([null, ...$symbols] as $scatter) {
                if (($n = $fitCount($fixed, $wild, $scatter)) > $best[0]) {
                    $best = [$n, $wild, $scatter];
                }
            }
        }
        [, $wild, $scatter] = $best;
        $targets = [null, ...array_keys(array_filter($client['paytable'], fn ($r) => array_sum($r) > 0))];
        $combos = [[]];
        foreach ($chains as $chain) {
            $next = [];
            foreach ($combos as $c) {
                foreach ($targets as $to) {
                    $next[] = [...$c, $to];
                }
            }
            $combos = $next;
        }
        $out = [];
        $top = count($samples) - min(3, (int) floor(count($samples) * 0.05));
        foreach ($combos as $combo) {
            $alias = $fixed;
            foreach ($chains as $i => $chain) {
                foreach ($combo[$i] === null ? [] : $chain as $piece) {
                    $alias[$piece] = $combo[$i];
                }
            }
            $n = $fitCount($alias, $wild, $scatter);
            if ($n > $top) {
                [$out, $top] = [[], $n];
            }
            if ($n === $top) {
                $out[] = $alias;
            }
        }

        return ['maps' => $out, 'missed' => count($samples) - $top];
    }

    /**
     * A second wild symbol (Billy's Gang: one wild on the first reel, another
     * on the third): every {symbol => wild} map of two symbols without a pay
     * row under which the plain lines fit every sample, with some scatter.
     *
     * @param  Init  $init
     * @param  list<Spin>  $spins
     * @param  ClientMath  $client
     * @return list<array<int, int>>
     */
    public function secondWilds(array $init, array $spins, array $client): array
    {
        $reels = count($init['strips']);
        $lines = $init['lines'];
        $symbols = range(0, max(array_merge(...$init['strips'])));
        $pay = array_map(fn ($s) => isset($client['paytable'][$s]) ? [0, ...$client['paytable'][$s]] : array_fill(0, $reels + 1, 0), $symbols);
        $unpaid = array_values(array_filter($symbols, fn ($s) => array_sum($pay[$s]) == 0));
        $samples = [];
        foreach ($spins as $sp) {
            if (in_array($sp['state'], [3, 5, 9], true) && $sp['line_wins'] !== null) {
                $samples[] = ['board' => $this->board($init['strips'], $sp['rp'], $client['rows']), 'wins' => array_pad(array_slice($sp['line_wins'], 0, $lines + 1), $lines + 1, 0)];
            }
        }
        $out = [];
        foreach ($unpaid as $wild) {
            foreach ($unpaid as $other) {
                if ($other === $wild) {
                    continue;
                }
                $aliased = array_map(fn ($s) => ['board' => array_map(fn ($col) => array_map(fn ($x) => $x === $other ? $wild : $x, $col), $s['board'])] + $s, $samples);
                // the lines alone first (the scatter slot zeroed), then a scatter that fits it
                $linesOnly = array_map(function ($s) use ($lines) {
                    $s['wins'][$lines] = 0;

                    return $s;
                }, $aliased);
                if (! $this->fitsAll($linesOnly, $pay, $client['paylines'], $lines, $wild, null, 1, false, 3)) {
                    continue;
                }
                foreach ([null, ...$symbols] as $scatter) {
                    if ($this->fitsAll($aliased, $pay, $client['paylines'], $lines, $wild, $scatter, 1, false, 3)) {
                        $out[] = [$other => $wild];
                        break;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The simple line rule set (wild × 1–3, expanding or not, a scatter, tall
     * symbols as their own or the wild) that matches the most live spins — for
     * games no exact rule set reproduces; the caller marks it unverified.
     *
     * @param  Init  $init
     * @param  list<Spin>  $spins
     * @param  ClientMath  $client
     * @return array{rules: array<string, mixed>, fit: int, samples: int}|null
     */
    public function bestFit(array $init, array $spins, array $client): ?array
    {
        $reels = count($init['strips']);
        $lines = $init['lines'];
        $symbols = range(0, max(array_merge(...$init['strips'])));
        $rowsN = count($client['paytable']);
        $pay = array_map(fn ($s) => $s < $rowsN ? [0, ...$client['paytable'][$s]] : array_fill(0, $reels + 1, 0), $symbols);
        $samples = [];
        foreach ($spins as $sp) {
            if (in_array($sp['state'], [3, 5, 9], true) && $sp['line_wins'] !== null) {
                $samples[] = ['board' => $this->board($init['strips'], $sp['rp'], $client['rows']), 'wins' => array_pad(array_slice($sp['line_wins'], 0, $lines + 1), $lines + 1, 0)];
            }
        }
        if ($samples === []) {
            return null;
        }
        $chains = $this->chains($init['strips']);
        $aliasOptions = $this->aliasOptions($init['strips']);
        if ($chains !== []) {
            $aliasOptions[] = array_fill_keys(array_values(array_diff(array_merge(...$chains), [$chains[0][0]])), $chains[0][0]);
        }
        $best = [-1, null];
        $deadline = microtime(true) + self::SEARCH_SECONDS;
        foreach ($aliasOptions as $alias) {
            $aliased = $alias === [] ? $samples : array_map(fn ($s) => ['board' => array_map(fn ($col) => array_map(fn ($x) => $alias[$x] ?? $x, $col), $s['board'])] + $s, $samples);
            foreach ([null, ...$symbols] as $wild) {
                foreach ($wild === null ? [[1, false]] : [[1, false], [2, false], [3, false], [1, true]] as [$wm, $expand]) {
                    foreach ([null, ...$symbols] as $scatter) {
                        if (microtime(true) > $deadline) {
                            break 4;
                        }
                        $n = count(array_filter($aliased, fn ($one) => $this->fitsAll([$one], $pay, $client['paylines'], $lines, $wild, $scatter, $wm, $expand, 3)));
                        if ($n > $best[0]) {
                            $best = [$n, compact('alias', 'wild', 'wm', 'expand', 'scatter')];
                        }
                    }
                }
            }
        }
        if ($best[1] === null) {
            return null;
        }
        $f = $best[1];

        return ['fit' => $best[0], 'samples' => count($samples), 'rules' => [
            'reels' => $reels, 'rows' => $client['rows'], 'lines' => $lines, 'symbols' => $symbols,
            'paytable' => $pay, 'paylines' => $client['paylines'],
            'wild' => $f['wild'], 'wild_multiplier' => $f['wm'], 'expanding_wild' => $f['expand'], 'wild_pay_factor' => null,
            'scatter_wild_mult' => 1, 'symbol_alias' => $f['alias'], 'wild_mult_each' => 'once', 'full_board_mult' => 1,
            'scatter' => $f['scatter'], 'scatter_min' => 3, 'mixed_pays' => [], 'wild_pays_as' => null,
        ]];
    }

    /**
     * The ways rule set matching the most live spins (any misses allowed) —
     * the unverified fallback of `solveWays`.
     *
     * @param  int|list<int>  $rows
     * @return array{rules: array<string, mixed>, fit: int, samples: int}|null
     */
    public function bestFitWays(array $init, array $spins, array $table, int|array $rows = 3): ?array
    {
        $paying = $init['lines'];
        $symbols = range(0, max(array_merge(...$init['strips'])));
        $reels = count($init['strips']);
        $samples = [];
        foreach ($spins as $sp) {
            if (in_array($sp['state'], [3, 5, 9], true) && $sp['line_wins'] !== null) {
                $samples[] = ['board' => $this->board($init['strips'], $sp['rp'], $rows), 'wins' => array_pad(array_slice($sp['line_wins'], 0, $init['win_slots']), $init['win_slots'], 0)];
            }
        }
        if ($samples === [] || count($table) < $paying) {
            return null;
        }
        $paytable = array_map(fn ($s) => isset($table[$s]) ? [0, ...$table[$s]] : array_fill(0, $reels + 1, 0), $symbols);
        $best = [-1, null];
        $deadline = microtime(true) + self::SEARCH_SECONDS;
        foreach ($this->aliasOptions($init['strips']) as $alias) {
            $aliased = $alias === [] ? $samples : array_map(fn ($s) => ['board' => array_map(fn ($col) => array_map(fn ($x) => $alias[$x] ?? $x, $col), $s['board'])] + $s, $samples);
            foreach ([null, ...array_slice($symbols, $paying)] as $doubler) {
                foreach ([null, ...$symbols] as $wild) {
                    foreach ($init['win_slots'] > $paying ? [null, ...array_slice($symbols, $paying)] : [null] as $scatter) {
                        if (microtime(true) > $deadline) {
                            break 4;
                        }
                        $rules = ['reels' => $reels, 'rows' => $rows, 'lines' => $paying, 'symbols' => $symbols, 'paytable' => $paytable,
                            'paylines' => null, 'wild' => $wild, 'scatter' => $scatter, 'scatter_min' => null, 'symbol_alias' => $alias,
                            'ways' => ['symbols' => range(0, $paying - 1), 'doubler' => $doubler, 'free_any_reel' => false]];
                        $n = count($samples) - count($this->waysMisses($rules, $aliased, $init['win_slots'], PHP_INT_MAX, anyTails: true) ?? []);
                        if ($n > $best[0]) {
                            $best = [$n, $rules];
                        }
                    }
                }
            }
        }

        return $best[1] === null ? null : ['rules' => $best[1], 'fit' => $best[0], 'samples' => count($samples)];
    }

    /** Fast in-solver evaluation — the same rules as SlotEngine's "best" line mode. */
    /**
     * The best run from the first cell of `$syms` and its length.
     *
     * @param  list<int>|null  $mults  the cells' multipliers, in the same order
     * @return array{0: float|int, 1: int}
     */
    private function runPay(array $syms, ?array $mults, array $pay, ?int $wild, ?int $scatter, int|float $wm, string $each, ?int $wildAs, ?array $sums = null): array
    {
        $best = [0, 0];
        foreach (array_unique($syms) as $c) {
            if ($c === $scatter && $c !== $wild) {
                continue;
            }
            $count = $wilds = $wildSum = 0;
            foreach ($syms as $i => $x) {
                if ($x !== $c && $x !== $wild) {
                    break;
                }
                $count++;
                $wilds += $x === $wild ? 1 : 0;
                $wildSum += $x === $wild ? ($sums[$i] ?? 1) : 0;
            }
            $p = $pay[$c === $wild && $wildAs !== null ? $wildAs : $c][$count] ?? 0;
            for ($r = 0; $mults !== null && $r < $count; $r++) {
                $p *= $mults[$r];
            }
            if ($sums !== null) {
                $p *= max(1, $wildSum);
            } elseif ($wilds > 0 && $wilds < $count) {
                $p *= match ($each) {
                    'each' => $wm ** $wilds, 'extra' => $wm ** ($wilds - 1), default => $wm
                };
            }
            if ($p > $best[0]) {
                $best = [$p, $count];
            }
        }

        return $best;
    }

    /**
     * The groups of a mixed-pay option: one group, or several paid together.
     *
     * @return list<array{symbols: list<int>, row: int}>
     */
    private function mixedGroups(?array $mixed): array
    {
        return $mixed === null ? [] : ($mixed['groups'] ?? [$mixed]);
    }

    private function fitsAll(array $samples, array $pay, array $paylines, int $lines, ?int $wild, ?int $scatter, int|float $wm, bool $expand, int $scMin, int $swm = 1, string $each = 'once', int $fb = 1, ?array $mixed = null, ?int $wildAs = null): bool
    {
        foreach ($samples as $s) {
            $board = $s['board'];
            if ($expand && $wild !== null) {
                foreach ($board as $r => $col) {
                    if (in_array($wild, $col, true)) {
                        $board[$r] = array_fill(0, count($col), $wild);
                    }
                }
            }
            $full = $fb > 1 && count(array_unique(array_merge(...array_values($board)))) === 1 ? $fb : 1;
            foreach ($paylines as $li => $line) {
                $syms = [];
                foreach ($line as $r => $row) {
                    $syms[] = $board[$r][$row] ?? -1;
                }
                $best = 0;
                foreach ($this->anyPosition ? array_keys($syms) : [0] as $from) {
                    $mults = ($s['mults'] ?? null) === null ? null
                        : array_map(fn ($r) => $s['mults'][$r][$line[$r]] ?? 1, range($from, count($syms) - 1));
                    $best = max($best, $this->runPay(array_slice($syms, $from), $mults, $pay, $wild, $scatter, $wm, $each, $wildAs,
                        isset($s['rsum']) ? array_slice($s['rsum'], $from) : null)[0] * ($s['bm'] ?? 1) * $full);
                }
                // both ways: the line pays its better direction
                if ($this->bothWays) {
                    $best = max($best, $this->runPay(array_reverse($syms), null, $pay, $wild, $scatter, $wm, $each, $wildAs,
                        isset($s['rsum']) ? array_reverse($s['rsum']) : null)[0] * ($s['bm'] ?? 1) * $full);
                }
                foreach ($this->mixedGroups($mixed) as $g) {
                    $run = $runWilds = 0;
                    foreach ($syms as $x) {
                        if (! in_array($x, $g['symbols'], true) && $x !== $wild) {
                            break;
                        }
                        $run++;
                        $runWilds += $x === $wild ? 1 : 0;
                    }
                    $mp = ($pay[$g['row']][$run] ?? 0) * $full;
                    if (($mixed['wild_mult'] ?? false) && $runWilds > 0 && $runWilds < $run) {
                        $mp *= match ($each) {
                            'each' => $wm ** $runWilds, 'extra' => $wm ** ($runWilds - 1), default => $wm
                        };
                    }
                    $best = max($best, $mp);
                }
                if ((int) round($best) !== $s['wins'][$li]) {
                    return false;
                }
            }
            $sc = 0;
            if ($scatter !== null) {
                $n = $this->countOnBoard($board, $scatter);
                $sc = $n >= $scMin ? ($pay[$scatter][$n] ?? $pay[$scatter][$n - 1] ?? 0) : 0;
                if ($sc > 0 && $swm > 1 && $wild !== null && $this->countOnBoard($board, $wild) > 0) {
                    $sc *= $swm;
                }
            }
            if ((int) round($sc) !== $s['wins'][$lines]) {
                return false;
            }
        }

        return true;
    }

    /** Re-run the chosen rules through the real engine on every sample. */
    private function engineAgrees(array $rules, array $samples): bool
    {
        $cfg = new GameConfig(new GameTemplate($this->templateMath($rules)), new Game(['denomination' => 1]));
        foreach ($samples as $s) {
            $ev = $this->engine->evaluate(($rules['wild_block'] ?? null) !== null ? $s['landed'] : $s['board'], $cfg, 1.0, $rules['lines'], cellMults: $s['mults'] ?? null,
                boardMult: ($rules['wild_reel_mult'] ?? null) !== null ? $s['bm'] : null, wildCells: $s['marks'] ?? null,
                reelValues: $s['rsum'] ?? null);
            $ours = array_fill(0, $rules['lines'] + 1, 0.0);
            foreach ($ev['lines'] as $w) {
                $slot = $w['line'] < 0 ? $rules['lines'] : $w['line'];
                $ours[$slot] += $w['line'] < 0 ? $w['amount'] / $rules['lines'] : $w['amount'];
            }
            if (array_map(fn ($x) => (int) round($x), $ours) !== $s['wins']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Template columns for solved rules (reel strips are added by the caller).
     *
     * @return array<string, mixed>
     */
    public function templateMath(array $rules): array
    {
        return [
            'reel_count' => $rules['reels'],
            'row_count' => $rules['rows'],
            'symbols' => $rules['symbols'],
            'symbol_count' => count($rules['symbols']),
            'wild_symbol' => $rules['wild'],
            'scatter_symbol' => $rules['scatter'],
            'wild_multiplier' => $rules['wild_multiplier'],
            'min_match' => 1,
            'paytable' => $rules['paytable'],
            'paylines' => $rules['paylines'],
            'reel_strips' => [],
            'bonus_config' => array_filter([
                'line_eval' => 'best',
                'scatter_min' => $rules['scatter_min'],
                'expanding_wild' => $rules['expanding_wild'],
                'scatter_wild_mult' => $rules['scatter_wild_mult'] > 1 ? $rules['scatter_wild_mult'] : null,
                'wild_mult_mode' => $rules['wild_mult_each'] !== 'once' ? $rules['wild_mult_each'] : null,
                'full_board_mult' => $rules['full_board_mult'] > 1 ? $rules['full_board_mult'] : null,
                'symbol_alias' => $rules['symbol_alias'] ?: null,
                'mixed_pays' => ($rules['mixed_pays'] ?? []) ?: null,
                'mixed_wild_mult' => $rules['mixed_wild_mult'] ?? null,
                'wild_pays_as' => $rules['wild_pays_as'] ?? null,
                'mystery' => $rules['mystery'] ?? null,
                'wild_block' => $rules['wild_block'] ?? null,
                'cell_mult' => $rules['cell_mult'] ?? null,
                'wild_board_mult' => $rules['wild_board_mult'] ?? null,
                'wild_reel_mult' => $rules['wild_reel_mult'] ?? null,
                'random_wilds' => $rules['random_wilds'] ?? null,
                'wild_reel_values' => $rules['wild_reel_values'] ?? null,
                'line_any_position' => $rules['line_any_position'] ?? null,
                'line_both_ways' => $rules['line_both_ways'] ?? null,
                'wild_pay_factor' => $rules['wild_pay_factor'] ?? null,
            ], fn ($v) => $v !== null),
        ];
    }
}
