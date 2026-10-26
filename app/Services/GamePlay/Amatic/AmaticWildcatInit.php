<?php

namespace App\Services\GamePlay\Amatic;

/**
 * A WildCat Gaming init frame (Amatic's own, captured live) read the way the
 * client reads it — `dZW.dZ2` with Yvn 0, then the `dVh.dZ4` tail:
 *
 *   strips, state, positions, round, balance, win, bet index, coins per bet,
 *   max bet, lines, … the bet ladder, the per-symbol win list, then
 *   version, Y8K, bonus-buy prices (× stake), bonus-hunt price (× stake × 100), …
 */
class AmaticWildcatInit
{
    private const string LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    private string $s = '';

    private int $i = 0;

    /**
     * @return array{balance_at: int, coins: int, lines: int, ladder: list<int>, buy: list<int>, hunt: list<float>}
     */
    public function tail(string $init, int $symbolWidth = 1, bool $short = false): array
    {
        $this->s = $init;
        $this->i = 0;
        $ext = 0;
        if (($init[0] ?? '') === '-') {
            $this->i = 1;
            $ext = $this->one();
        }
        $this->one();                                           // Yvn (0 = init)
        for ($c = $this->one(), $k = 0; $k < $c; $k++) {
            $this->skipStrip($symbolWidth);                     // strip symbols
        }
        for ($c = $this->one(), $k = 0; $k < $c; $k++) {
            $this->skipStrip($symbolWidth);
        }
        $this->two();                                           // state
        $this->one();                                           // message
        $rl = $ext >= 1 ? $this->two() : 5;
        for ($k = 0; $k < max(5, $rl); $k++) {
            $this->num();
        }
        $this->i += 1 + $this->hex($this->s[$this->i] ?? '0');   // round id
        $balanceAt = $this->i;
        $this->num();                                           // balance
        $this->num();                                           // win
        $this->two();                                           // bet index
        $coins = $this->num();
        $this->num();                                           // max bet
        $lines = $this->two();
        for ($k = 0; $k < 7; $k++) {
            $this->num();
        }
        $this->two();
        $this->two();
        $this->two();
        $this->num();
        $this->two();
        $dh = $ext >= 1 ? $this->two() : 5;
        for ($k = 0; $k < max(5, $dh); $k++) {
            $this->num();
        }
        $this->i += 16;                                         // 8 × 2-hex
        $ladder = [];
        for ($c = $this->two(), $k = 0; $k < $c; $k++) {
            $ladder[] = $this->num();
        }
        for ($c = $this->two(), $k = 0; $k < $c; $k++) {
            $this->num();                                       // per-symbol wins
        }
        $this->num();                                           // tail version
        if (! $short) {                                         // short tails (Rampage) go straight to the buy prices
            $this->num();                                       // Y8K
        }

        return [
            'balance_at' => $balanceAt,
            'coins' => max(1, $coins),
            'lines' => $lines,
            'ladder' => $ladder,
            'buy' => $this->list(),
            'hunt' => array_map(fn (int $v) => $v / 100, $this->list()),
        ];
    }

    /**
     * The base reel strips the init carries (2-hex symbols; empty strips dropped).
     *
     * @return list<list<int>>
     */
    public function strips(string $init, int $symbolWidth = 2): array
    {
        $this->s = $init;
        $this->i = ($init[0] ?? '') === '-' ? 2 : 0;
        $this->one();                                           // Yvn
        $out = [];
        for ($c = $this->one(), $k = 0; $k < $c; $k++) {
            $strip = [];
            if ($symbolWidth === 1) {
                // one character per symbol: a hex digit (Merlin) or a base64 letter (ElToro)
                $n = $this->num();
                $chars = substr($this->s, $this->i, $n);
                $this->i += $n;
                $hex = $chars !== '' && ctype_xdigit($chars) && strtolower($chars) === $chars;
                foreach (str_split($chars) as $ch) {
                    $strip[] = $hex ? (int) hexdec($ch) : (int) strpos(self::LETTERS, $ch);
                }
            }
            for ($n = $symbolWidth === 1 ? 0 : $this->num(), $m = 0; $m < $n && $this->i < strlen($this->s); $m++) {
                $strip[] = $symbolWidth === 0 ? $this->num() : $this->two();
            }
            if ($strip !== []) {
                $out[] = $strip;
            }
        }

        return $out;
    }

    /** The init frame with `$cents` as the balance (`$symbolWidth`: 1 = letter strips, 2 = 2-hex strips, 0 = number strips). */
    public function withBalance(string $init, int $cents, int $symbolWidth = 1): string
    {
        $at = $this->tail($init, $symbolWidth)['balance_at'];
        $len = $this->hex($init[$at] ?? '0');
        $hex = dechex(max(0, $cents));

        return substr($init, 0, $at).strlen($hex).$hex.substr($init, $at + 1 + $len);
    }

    /** Skip one strip: a count, then that many letters / 2-hex symbols (width 1 / 2) or numbers (width 0). */
    private function skipStrip(int $width): void
    {
        $n = $this->num();
        if ($width > 0) {
            $this->i += $n * $width;

            return;
        }
        for ($k = 0; $k < $n && $this->i < strlen($this->s); $k++) {
            $this->num();
        }
    }

    private function one(): int
    {
        return $this->hex($this->s[$this->i++] ?? '0');
    }

    private function two(): int
    {
        $v = $this->hex(substr($this->s, $this->i, 2));
        $this->i += 2;

        return $v;
    }

    private function num(): int
    {
        $n = $this->hex($this->s[$this->i++] ?? '0');
        $v = substr($this->s, $this->i, $n);
        $this->i += $n;

        return $n > 0 ? $this->hex($v) : 0;
    }

    /** @return list<int> */
    private function list(): array
    {
        $out = [];
        for ($c = $this->num(), $k = 0; $k < $c; $k++) {
            $out[] = $this->num();
        }

        return $out;
    }

    /** A hex field — 0 when the bytes aren't hex (probing a layout that doesn't fit). */
    private function hex(string $v): int
    {
        return $v !== '' && ctype_xdigit($v) ? (int) hexdec($v) : 0;
    }
}
