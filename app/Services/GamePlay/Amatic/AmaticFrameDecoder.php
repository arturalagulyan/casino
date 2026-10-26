<?php

namespace App\Services\GamePlay\Amatic;

use RuntimeException;

/**
 * Reads Amatic's hex frames the way the gmsl clients do (`YRZ.Y3D`): a
 * cursor over 1-digit ints, 2-digit ints and length-prefixed hex numbers.
 * A leading `-<n>` switches on the extended layout (extra reel-count
 * fields). Reel-strip symbols are 1 hex digit, or 2 in newer games with
 * more than 16 symbols — {@see init()} tries both and keeps the reading
 * that parses cleanly.
 *
 * @phpstan-type Init array{ext: int, width: int, strips: list<list<int>>, free_strips: list<list<int>>, lines: int, bets: list<int>, win_slots: int, rest: string}
 * @phpstan-type Spin array{state: int, win: int, rp: list<int>, lines: ?int, line_wins: ?list<int>, tail?: string}
 */
class AmaticFrameDecoder
{
    private string $s = '';

    private int $i = 0;

    /** @return Init|null */
    public function init(string $frame): ?array
    {
        $readings = [];
        // more than 15 strips (Wild Boost: 20) are counted with two digits
        foreach ([false, true] as $wideCount) {
            foreach ([1, 2] as $width) {
                if ($o = $this->initWidth($frame, $width, $wideCount)) {
                    $all = array_merge(...$o['strips']);
                    // a 2-digit strip read 1 digit at a time is half zeros
                    $readings[] = [count(array_filter($all, fn ($x) => $x === 0)) / max(1, count($all)), $o];
                }
            }
            if ($readings !== []) {
                break;
            }
        }
        usort($readings, fn ($a, $b) => $a[0] <=> $b[0]);

        return $readings[0][1] ?? null;
    }

    /** @return Spin|null */
    public function spin(string $frame, int $ext): ?array
    {
        try {
            $this->start($frame);
            if ($this->one() !== 1) {
                return null;
            }
            $state = $this->two();
            if ($this->one() !== 0) {
                return null;
            }
            $this->len();
            $this->len();
            $win = $this->len();
            $reels = $ext ? $this->two() : 5;
            $rp = [];
            for ($b = 0; $b < max(5, $reels); $b++) {
                $rp[] = $this->len();
            }
        } catch (RuntimeException) {
            return null;
        }
        $out = ['state' => $state, 'win' => $win, 'rp' => $rp, 'lines' => null, 'line_wins' => null];
        try {
            $this->two();
            $out['lines'] = $this->two();
            for ($k = 0; $k < 6; $k++) {
                $this->len();
            }
            $freeReels = $ext ? $this->two() : 5;
            for ($b = 0; $b < max(5, $freeReels); $b++) {
                $this->len();
            }
            $wins = [];
            for ($c = $this->two(), $k = 0; $k < $c; $k++) {
                $wins[] = $this->len();
            }
            $out['line_wins'] = $wins;
            for ($k = 0; $k < 8; $k++) {
                $this->two();   // gamble cards
            }
            // what the game's own parser reads after the cards, up to the `#` trailer
            $rest = substr($this->s, $this->i);
            $hash = strpos($rest, '#');
            $out['tail'] = $hash === false ? $rest : substr($rest, 0, $hash);
        } catch (RuntimeException) {
        }

        return $out;
    }

    /** @return Init|null */
    private function initWidth(string $frame, int $width, bool $wideCount = false): ?array
    {
        try {
            $this->start($frame);
            $ext = $this->ext;
            if ($this->one() !== 0) {
                return null;
            }
            $strips = [];
            for ($n = $wideCount ? $this->two() : $this->one(), $b = 0; $b < $n; $b++) {
                $strips[] = $this->strip($width);
            }
            $free = [];
            for ($n = $wideCount ? $this->two() : $this->one(), $b = 0; $b < $n; $b++) {
                $free[] = $this->strip($width);
            }
            $this->two();
            $this->one();
            $reels = $ext ? $this->two() : count($strips);
            for ($b = 0; $b < max(5, $reels); $b++) {
                $this->len();
            }
            $this->len();
            $this->len();
            $this->len();
            $this->two();
            $this->len();
            $this->len();
            $lines = $this->two();
            for ($k = 0; $k < 7; $k++) {
                $this->len();
            }
            $this->two();
            $this->two();
            $this->two();
            $this->len();
            $this->two();
            $freeReels = $ext ? $this->two() : 5;
            for ($b = 0; $b < max(5, $freeReels); $b++) {
                $this->len();
            }
            for ($k = 0; $k < 8; $k++) {
                $this->two();
            }
            $bets = [];
            for ($c = $this->two(), $k = 0; $k < $c; $k++) {
                $bets[] = $this->len();
            }
            $slots = $this->two();
            for ($k = 0; $k < $slots; $k++) {
                $this->len();
            }
        } catch (RuntimeException) {
            return null;
        }
        // 3-reel games send 5 strip slots, the last two empty
        $strips = array_values(array_filter($strips, fn ($s) => $s !== []));
        $free = array_values(array_filter($free, fn ($s) => $s !== []));
        $rest = substr($this->s, $this->i);
        $short = array_filter($strips, fn ($s) => count($s) < 8);
        if (count($strips) < 3 || $short || $bets === [] || count($bets) > 40 || min($bets) <= 0
            || $lines < 1 || $lines > 1024 || ! preg_match('/[|#]/', $rest)) {
            return null;
        }

        return ['ext' => $ext, 'width' => $width, 'strips' => $strips, 'free_strips' => $free, 'lines' => $lines,
            'bets' => $bets, 'win_slots' => $slots, 'rest' => $rest];
    }

    private int $ext = 0;

    private function start(string $frame): void
    {
        $this->s = $frame;
        $this->i = 0;
        $this->ext = 0;
        if (str_starts_with($frame, '-')) {
            $this->i = 1;
            $this->ext = $this->one();
        }
    }

    /** @phpstan-impure */
    private function one(): int
    {
        if ($this->i >= strlen($this->s) || ! ctype_xdigit($this->s[$this->i])) {
            throw new RuntimeException('frame ended');
        }

        return (int) hexdec($this->s[$this->i++]);
    }

    /** @phpstan-impure */
    private function two(): int
    {
        return $this->digits(2);
    }

    /** @phpstan-impure */
    private function len(): int
    {
        $n = $this->one();

        return $n ? $this->digits($n) : 0;
    }

    /** @phpstan-impure */
    private function digits(int $n): int
    {
        $v = substr($this->s, $this->i, $n);
        if (strlen($v) < $n || ! ctype_xdigit($v)) {
            throw new RuntimeException('bad hex');
        }
        $this->i += $n;

        return (int) hexdec($v);
    }

    /** @return list<int> */
    private function strip(int $width): array
    {
        $out = [];
        for ($n = $this->len(), $k = 0; $k < $n; $k++) {
            $out[] = $this->digits($width);
        }

        return $out;
    }
}
