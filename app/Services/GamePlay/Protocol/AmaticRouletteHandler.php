<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * Amatic roulette (Roulette Royal, Roulette Prestige) over the shared Amatic
 * socket protocol — ported from the legacy RouletteRoyalAM backend:
 *
 *   A/u25            init — Amatic's own init frame (captured at import:
 *                    chips, table limits, number history) with our balance
 *   A/u291,<bets>    spin — bets `field$cents|field$cents…`; fields are a
 *                    number, `a,b` split, `a.b` corner, `x-y` street / six
 *                    line / dozen / low / high, `n/12` column, red/black/odd/even
 *   A/u250, A/u350   resync, balance poll
 *
 * Real European roulette pays (stake included, legacy `$paysArr`); the ball
 * is drawn at random, re-drawn only when the shop's win pool can't cover the
 * payout (legacy redraw loop against GetBank).
 */
class AmaticRouletteHandler
{
    private const array PAYS = [
        'straight' => 36, 'split' => 18, 'street' => 12, 'corner' => 9, 'line' => 6,
        'column' => 3, 'twelve' => 3, 'low' => 2, 'high' => 2, 'red' => 2, 'black' => 2, 'odd' => 2, 'even' => 2,
    ];

    private const array RED = [1, 3, 5, 7, 9, 12, 14, 16, 18, 19, 21, 23, 25, 27, 30, 32, 34, 36];

    public function __construct(private readonly AmaticFormatter $fmt) {}

    /**
     * @param  array<string, mixed>  $request
     * @return list<string>
     */
    public function dispatch(GameContext $ctx, array $request): array
    {
        $raw = (string) ($request['gameData'] ?? '');
        $cmd = strtok($raw, ',');

        return match ($cmd) {
            'A/u25' => [$this->init($ctx)],
            'A/u250' => ['100010'.$this->cents($ctx->balance()).'108ffffffff10null'],
            'A/u291' => [$this->spin($ctx, substr($raw, strlen('A/u291,')))],
            'A/u350' => ['UPDATE#'.(int) round($ctx->balance() * 100)],
            default => [$this->fmt->error((string) $cmd, 'unknown command')],
        };
    }

    /** Amatic's captured init with our balance in its balance field (type, state, msg, round, balance…). */
    private function init(GameContext $ctx): string
    {
        $frame = (string) ($ctx->config()->bonusConfig()['table']['init'] ?? '');
        if ($frame === '' || ! preg_match('/^0[0-9a-f]{2}0/', $frame)) {
            return $this->fmt->error('A/u25', 'table not configured');
        }
        $i = 4;
        $i += 1 + (int) hexdec($frame[$i]);              // round
        $len = (int) hexdec($frame[$i]);                 // balance

        return substr($frame, 0, $i).$this->cents($ctx->balance()).substr($frame, $i + 1 + $len);
    }

    private function spin(GameContext $ctx, string $betString): string
    {
        $bets = [];
        $stake = 0.0;
        foreach (explode('|', $betString) as $bet) {
            [$field, $cents] = array_pad(explode('$', $bet, 2), 2, null);
            if ($field === null || $cents === null || ! ctype_digit($cents) || (int) $cents <= 0) {
                continue;
            }
            [$type, $numbers] = $this->numbers($field);
            if ($type === null) {
                return $this->fmt->error('A/u291', 'invalid bet field');
            }
            $amount = (int) $cents / 100;
            $bets[] = [$type, $numbers, $amount];
            $stake += $amount;
        }
        $stake = round($stake, 2);
        if ($stake <= 0) {
            return $this->fmt->error('A/u291', 'invalid bet state');
        }
        if ($ctx->balance() < $stake) {
            return $this->fmt->error('A/u291', 'invalid balance');
        }
        $ctx->placeBet($stake);

        $payout = fn (int $n) => array_sum(array_map(fn ($b) => in_array($n, $b[1], true) ? self::PAYS[$b[0]] * $b[2] : 0, $bets));
        $number = random_int(0, 36);
        if ($payout($number) > $ctx->bankAvailable()) {
            $affordable = array_values(array_filter(range(0, 36), fn ($n) => $payout($n) <= $ctx->bankAvailable()));
            $number = $affordable !== [] ? $affordable[array_rand($affordable)]
                : (int) array_search(min($all = array_map($payout, range(0, 36))), $all, true);
        }
        $win = round($payout($number), 2);
        if ($win > 0) {
            $win = $ctx->awardWin($win);
        }

        $history = $this->history($ctx);
        $history[] = $number;
        $ctx->statePut(['features' => ['roulette_history' => array_slice($history, -17)]]);
        $ctx->recordRound(
            new SpinResult(bet: $stake, win: $win, state: 'bet'),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => ['number' => $number, 'bets' => $betString, 'totalWin' => $win]]),
        );

        return '105010'.$this->cents($ctx->balance()).$this->cents($win).$this->fmt->hexFmt($number).'10'.$betString;
    }

    /** @return list<int> */
    private function history(GameContext $ctx): array
    {
        return array_map('intval', (array) (((array) $ctx->stateGet('features', []))['roulette_history'] ?? []));
    }

    private function cents(float $amount): string
    {
        return $this->fmt->hexFmt(round($amount * 100));
    }

    /**
     * A bet field → [type, covered numbers] (legacy GetNumbersByField, with the
     * 19-36 bet corrected to its own 18 numbers).
     *
     * @return array{0: ?string, 1: list<int>}
     */
    private function numbers(string $field): array
    {
        $num = fn ($v) => ctype_digit((string) $v) && (int) $v <= 36;

        return match (true) {
            ctype_digit($field) && (int) $field <= 36 => ['straight', [(int) $field]],
            in_array($field, ['red', 'black', 'odd', 'even'], true) => [$field, array_values(array_filter(range(1, 36), fn ($n) => match ($field) {
                'red' => in_array($n, self::RED, true),
                'black' => ! in_array($n, self::RED, true),
                'odd' => $n % 2 === 1,
                default => $n % 2 === 0,
            }))],
            (bool) preg_match('#^([123])/12$#', $field, $m) => ['column', range((int) $m[1], 36, 3)],
            (bool) preg_match('/^(\d+),(\d+)$/', $field, $m) && $num($m[1]) && $num($m[2]) => ['split', [(int) $m[1], (int) $m[2]]],
            (bool) preg_match('/^(\d+)\.(\d+)$/', $field, $m) && $num($m[1]) && $num($m[2]) => ['corner', [(int) $m[1], (int) $m[1] + 1, (int) $m[2], (int) $m[2] - 1]],
            (bool) preg_match('/^(\d+)-(\d+)$/', $field, $m) && $num($m[1]) && $num($m[2]) => match (true) {
                $field === '1-18' => ['low', range(1, 18)],
                $field === '19-36' => ['high', range(19, 36)],
                (int) $m[2] - (int) $m[1] <= 2 => ['street', range((int) $m[1], (int) $m[1] + 2)],
                (int) $m[2] - (int) $m[1] <= 5 => ['line', range((int) $m[1], (int) $m[1] + 5)],
                (int) $m[2] - (int) $m[1] === 11 => ['twelve', range((int) $m[1], (int) $m[2])],
                default => [null, []],
            },
            default => [null, []],
        };
    }
}
