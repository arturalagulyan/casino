<?php

namespace App\Services\GamePlay\Amatic;

/**
 * Pulls a game's base-game tables out of its (minified) Amatic engine. The
 * client draws its help screens and line animations from its own copy of
 * the maths, declared together in the game class:
 *
 *   this.Y56=[[0,0,40,200,1E3],[0,0,20,50,200],…];   paytable, one row per symbol: pays for 1…N in a row
 *   this.YEz=[0,1,2,2,3,3,4,5];                     (sometimes) symbol → paytable row
 *   this.YSB=[[2,2,2,2,2],[3,3,3,3,3],…];           paylines, 1-based row per reel
 *
 * The engine also carries a generic 5-line default earlier in the file, so
 * the paylines are the last such array whose length is the game's line
 * count, and the paytable is the reel-wide table declared just before it.
 * Which symbol is wild/scatter isn't declared in a findable way — that is
 * settled against live spins ({@see AmaticMathSolver}).
 *
 * @phpstan-type ClientMath array{paytable: list<list<int|float>>, paylines: list<list<int>>, rows: int, maps: list<list<int>>}
 */
class AmaticClientMath
{
    /** @return ClientMath|null */
    public function extract(string $js, int $reels, int $lines): ?array
    {
        $arrays = $this->nestedArrays($js);

        $pl = null;
        foreach ($arrays as $i => $a) {
            if (count($a['v']) === $lines && $this->isPaylines($a['v'], $reels)) {
                $pl = $i;
            }
        }
        if ($pl === null) {
            return null;
        }

        $pt = null;
        for ($j = $pl - 1; $j >= max(0, $pl - 4); $j--) {
            if ($this->isTable($arrays[$j]['v'], $reels)) {
                $pt = $j;
                break;
            }
        }
        // some clients declare the paytable just after the paylines (Lucky Joker 5)
        for ($j = $pl + 1; $pt === null && $j < min(count($arrays), $pl + 4); $j++) {
            if ($this->isTable($arrays[$j]['v'], $reels)) {
                $pt = $j;
            }
        }
        if ($pt === null) {
            return null;
        }

        $paylines = $arrays[$pl]['v'];
        $flat = array_merge(...$paylines);
        $min = min($flat);

        return [
            'paytable' => $arrays[$pt]['v'],
            'paylines' => array_map(fn ($line) => array_map(fn ($row) => $row - $min, $line), $paylines),
            'rows' => max($flat) - $min + 1,
            'maps' => $this->flatArrays($js, $arrays[$pt]['pos'] - 400, $arrays[$pl]['pos']),
        ];
    }

    /**
     * A ways game's paytable — no paylines to anchor on, so the last
     * reel-wide table with at least one row per paying symbol.
     *
     * @return list<list<int|float>>|null
     */
    public function waysTable(string $js, int $reels, int $paying): ?array
    {
        $found = null;
        foreach ($this->nestedArrays($js) as $a) {
            if (count($a['v']) >= $paying && $this->isTable($a['v'], $reels) && max(array_merge(...$a['v'])) > 0) {
                $found = $a['v'];
            }
        }

        return $found;
    }

    /**
     * Candidate per-reel heights of a rombo ways game: flat arrays one entry
     * per reel, 2…8 rows, not all alike (e.g. [3, 4, 5, 4, 3]).
     *
     * @return list<list<int>>
     */
    public function reelHeights(string $js, int $reels): array
    {
        $out = [];
        foreach ($this->flatArrays($js, 0, strlen($js)) as $v) {
            if (count($v) === $reels && min($v) >= 2 && max($v) <= 8 && count(array_unique($v)) > 1 && ! in_array($v, $out, true)) {
                $out[] = array_map('intval', $v);
            }
        }

        return $out;
    }

    /** @return list<array{pos: int, v: list<mixed>}> */
    private function nestedArrays(string $js): array
    {
        preg_match_all('/(?:this\.|\b)[A-Za-z_$][\w$]*\s*=\s*(\[\[[-0-9.Ee,\[\]\s]+\]\])/', $js, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m as $x) {
            // minified numbers: 1E3, .1 → JSON 1e3, 0.1
            $v = json_decode((string) preg_replace(['/(\d)E(\d)/', '/(?<=[,\[])\./'], ['$1e$2', '0.'], $x[1][0]), true);
            if (is_array($v) && array_is_list($v)) {
                $out[] = ['pos' => $x[0][1], 'v' => $v];
            }
        }

        return $out;
    }

    /** @return list<list<int>> */
    private function flatArrays(string $js, int $from, int $to): array
    {
        $from = max(0, $from);
        preg_match_all('/this\.[\w$]+\s*=\s*(\[[0-9,]+\])/', substr($js, $from, max(0, $to - $from)), $m);

        return array_values(array_filter(array_map(fn ($s) => json_decode($s, true), $m[1]), 'is_array'));
    }

    /** @param list<mixed> $v */
    private function isPaylines(array $v, int $reels): bool
    {
        foreach ($v as $line) {
            if (! is_array($line) || count($line) !== $reels) {
                return false;
            }
            foreach ($line as $row) {
                if (! is_int($row) || $row < 0 || $row > 8) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * A paytable: rows of pays per count, (nearly) all reel-wide — one odd row
     * is a symbol paid by its count anywhere on the board (e.g. a 3×3 game's
     * star row of 9), which reads the same way.
     *
     * @param  list<mixed>  $v
     */
    private function isTable(array $v, int $reels): bool
    {
        if (count($v) < 3) {
            return false;
        }
        $odd = 0;
        foreach ($v as $row) {
            if (! is_array($row) || $row === [] || ! array_is_list($row) || min($row) < 0) {
                return false;
            }
            $odd += count($row) !== $reels ? 1 : 0;
        }

        return $odd <= 1;
    }
}
