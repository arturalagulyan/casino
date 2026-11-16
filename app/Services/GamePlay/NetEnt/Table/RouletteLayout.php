<?php

namespace App\Services\GamePlay\NetEnt\Table;

/**
 * NetEnt roulette bet positions — the `bet.<key>` a client sends — as the
 * numbers they cover, the limit they fall under and what they return.
 * Read off the clients' own tables (config.json `betTypeId`, `winRatio`) and
 * checked against NetEnt's servers.
 *
 *   n            straight (00 on the American table; 00 is number 37)
 *   n.3          split n / n−3 (on the first row: n / 0; American 3.3: 3 / 00)
 *   n.1          street n…n+2 on the first row (n ≡ 1 mod 3), else split n−1 / n
 *   n.2          six-line n−3…n+2 on the first row (n ≥ 4), else corner
 *                n−4, n−3, n−1, n; around zero: 1.2 0-1-2-3 (American: the
 *                five-number top line 0-00-1-2-3), 2.2 trio 0-1-2, 3.2 trio
 *                0-2-3 (American: 00-2-3)
 *   00.1, 2.4    American: split 0 / 00, trio 0-00-2
 *   column.k     the row of n ≡ k (mod 3), k = 3 the top row
 *   dozen.k, red, black, odd, even, low, high
 *
 * A key the table does not know is no bet at all — NetEnt's servers skip it
 * (American `2.3`) without taking a stake.
 */
final class RouletteLayout
{
    public const int DOUBLE_ZERO = 37;

    private const array RED = [1, 3, 5, 7, 9, 12, 14, 16, 18, 19, 21, 23, 25, 27, 30, 32, 34, 36];

    public function __construct(public readonly bool $american = false) {}

    /** @return list<int> 0–36, and 37 (00) on the American wheel */
    public function wheel(): array
    {
        return $this->american ? [...range(0, 36), self::DOUBLE_ZERO] : range(0, 36);
    }

    /**
     * What a position covers, its limit group (the init's `straight`,
     * `split`, … `redblack`) and its return per unit staked, stake included.
     *
     * @return array{numbers: list<int>, limit: string, returns: int}|null
     */
    public function position(string $key): ?array
    {
        $numbers = $this->numbers($key);
        if ($numbers === null) {
            return null;
        }
        $limit = match (true) {
            str_starts_with($key, 'column.') => 'column',
            str_starts_with($key, 'dozen.') => 'dozen',
            in_array($key, ['red', 'black'], true) => 'redblack',
            in_array($key, ['odd', 'even'], true) => 'oddeven',
            in_array($key, ['low', 'high'], true) => 'highlow',
            default => match (count($numbers)) {
                1 => 'straight', 2 => 'split', 3 => 'threeline', 4 => 'corner', 5 => 'topline', default => 'sixline',
            },
        };

        // 35:1, 17:1, 11:1, 8:1, 5:1, 2:1, 1:1 — and the top line's 6:1
        return ['numbers' => $numbers, 'limit' => $limit, 'returns' => $limit === 'topline' ? 7 : intdiv(36, count($numbers))];
    }

    /** @return list<int>|null */
    private function numbers(string $key): ?array
    {
        if ($key === '00') {
            return $this->american ? [self::DOUBLE_ZERO] : null;
        }
        if (ctype_digit($key)) {
            return (int) $key <= 36 ? [(int) $key] : null;
        }
        $outside = match ($key) {
            'red' => self::RED,
            'black' => array_values(array_diff(range(1, 36), self::RED)),
            'odd' => range(1, 35, 2),
            'even' => range(2, 36, 2),
            'low' => range(1, 18),
            'high' => range(19, 36),
            default => null,
        };
        if ($outside !== null) {
            return $outside;
        }
        if (preg_match('/^(column|dozen)\.([123])$/', $key, $m)) {
            $k = (int) $m[2];

            return $m[1] === 'dozen' ? range(12 * $k - 11, 12 * $k) : range($k, 34 + $k - 1, 3);
        }
        if ($this->american) {
            $zeros = ['00.1' => [0, self::DOUBLE_ZERO], '2.4' => [0, self::DOUBLE_ZERO, 2], '1.2' => [0, self::DOUBLE_ZERO, 1, 2, 3],
                '3.2' => [self::DOUBLE_ZERO, 2, 3], '3.3' => [self::DOUBLE_ZERO, 3], '2.3' => null];
            if (array_key_exists($key, $zeros)) {
                return $zeros[$key];
            }
        }
        if (! preg_match('/^(\d{1,2})\.([123])$/', $key, $m) || (int) $m[1] < 1 || (int) $m[1] > 36) {
            return null;
        }
        [$n, $kind, $row] = [(int) $m[1], (int) $m[2], (int) $m[1] % 3];   // row: 1 bottom, 2 middle, 0 top

        return match ($kind) {
            3 => $n <= 3 ? [0, $n] : [$n - 3, $n],
            1 => $row === 1 ? [$n, $n + 1, $n + 2] : [$n - 1, $n],
            2 => match (true) {
                $n === 1 => [0, 1, 2, 3],
                $n === 2 => [0, 1, 2],
                $n === 3 => [0, 2, 3],
                $row === 1 => range($n - 3, $n + 2),
                default => [$n - 4, $n - 3, $n - 1, $n],
            },
        };
    }

    public function color(int $n): string
    {
        return $n === 0 || $n === self::DOUBLE_ZERO ? 'green' : (in_array($n, self::RED, true) ? 'red' : 'black');
    }
}
