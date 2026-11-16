<?php

namespace App\Services\GamePlay\NetEnt\Table;

use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\NetEnt\NetEntReply;
use App\Services\GamePlay\SpinResult;
use Illuminate\Support\Facades\Cache;

/**
 * NetEnt's roulette tables, served by our own wheel (a player's chips decide
 * the round, so there is no recorded outcome to replay).
 *
 *   french, american   `action=spin`, chips as `bet.<position>=<cents>`;
 *                      reply: `credit`, a `win.<position>` per winning chip
 *                      (stake included, in currency units), `winnumber`
 *   touch              `action=draw` (and `selectedchip`); the reply adds
 *                      `totbet` / `totwin` (cents), a `win.` for every chip,
 *                      `wincolor`, `winhighlow`, and `winoddeven` — which
 *                      NetEnt's server gives with the parity flipped (21 is
 *                      "even", 0 "odd"); the client shows what it is told, so
 *                      we tell it the same
 *
 * Limits come from the recorded init: per position by type (`straight`, …
 * French/American; `maxbet.straight`, … Touch), the table's `minbet` / `maxbet`.
 * A fair wheel: the house edge is the table's own (2.7 %, American 5.26 %);
 * a number the bank could not pay is drawn again.
 */
final class NetEntRoulette
{
    /** @param  (\Closure(list<int>): int)|null  $draw  picks the number from the wheel; tests fix it */
    public function __construct(private readonly ?\Closure $draw = null) {}

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>  $cfg  the template's `netent` setup
     */
    public function dispatch(GameContext $context, array $query, array $cfg, string $variant): string
    {
        $layout = new RouletteLayout($variant === 'american');
        $spin = $variant === 'touch' ? 'draw' : 'spin';

        return match ((string) ($query['action'] ?? '')) {
            'init' => NetEntTable::init($context, (string) ($cfg['init'] ?? '')),
            'reloadbalance' => NetEntReply::build(['credit' => NetEntTable::cents($context->balance()), 'playercurrencyiso' => $context->currency->value], '&'),
            $spin => $this->spin($context, $query, $cfg, $layout, $variant),
            default => NetEntReply::build(['errorcode' => '1', 'errormessage' => 'Unknown action'], '&'),
        };
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>  $cfg
     */
    private function spin(GameContext $context, array $query, array $cfg, RouletteLayout $layout, string $variant): string
    {
        $limits = $this->limits((string) ($cfg['init'] ?? ''), $variant === 'touch');
        $chips = [];
        foreach ($query as $k => $v) {
            if (! str_starts_with($k, 'bet.')) {
                continue;
            }
            $position = $layout->position(substr($k, 4));
            if ($position === null || ! ctype_digit($v) || (int) $v === 0) {
                continue;   // not a position on this table: no bet (as NetEnt)
            }
            if ((int) $v > ($limits[$position['limit']] ?? PHP_INT_MAX)) {
                return $this->error('Bet above the table limit');
            }
            $chips[substr($k, 4)] = ['cents' => (int) $v] + $position;
        }
        $total = array_sum(array_column($chips, 'cents'));
        if ($chips !== [] && ($total < ($limits['minbet'] ?? 0) || $total > ($limits['maxbet'] ?? PHP_INT_MAX))) {
            return $this->error('Total bet outside the table limits');
        }
        $stake = $total / 100;
        if ($stake > $context->balance() + 1e-9) {
            return $this->error('Insufficient funds');
        }

        $returns = fn (int $n) => array_sum(array_map(fn (array $c) => in_array($n, $c['numbers'], true) ? $c['cents'] * $c['returns'] : 0, $chips));
        if ($stake > 0) {
            $context->placeBet($stake);
        }
        $number = $this->draw ? ($this->draw)($layout->wheel()) : $layout->wheel()[random_int(0, count($layout->wheel()) - 1)];
        $bank = NetEntTable::bankCents($context);
        if ($returns($number) > $bank) {
            $affordable = array_values(array_filter($layout->wheel(), fn (int $n) => $returns($n) <= $bank));
            $number = $affordable !== [] ? $affordable[random_int(0, count($affordable) - 1)]
                : $layout->wheel()[(int) array_search(min($all = array_map($returns, $layout->wheel())), $all, true)];
        }
        $this->remember($context, $number);
        $win = $returns($number) / 100;
        $paid = $win > 0 ? $context->awardWin($win) : 0.0;
        if ($stake > 0) {   // a spin with no chips turns the wheel (as NetEnt) but is no round
            $context->recordRound(new SpinResult($stake, $paid, [], [], 'bet', ['roulette' => $number]),
                json_encode(['number' => $number, 'bets' => array_map(fn ($c) => $c['cents'], $chips)]) ?: '');
        }

        $wins = [];
        foreach ($chips as $key => $c) {
            $w = in_array($number, $c['numbers'], true) ? $c['cents'] * $c['returns'] / 100 : 0;
            if ($w > 0 || $variant === 'touch') {
                $wins["win.{$key}"] = NetEntReply::amount($w);
            }
        }
        $shown = $number === RouletteLayout::DOUBLE_ZERO ? '37' : (string) $number;
        $common = ['credit' => NetEntTable::cents($context->balance()), 'historybutton' => 'false', 'isJackpotWin' => 'false',
            'playercurrency' => '', 'playercurrencyiso' => $context->currency->value, 'playforfun' => 'false'];
        if ($variant !== 'touch') {
            return NetEntReply::build($common + $wins + ['winnumber' => $shown], '&');
        }

        return NetEntReply::build($common + ['totbet' => $total, 'totwin' => $returns($number)] + $wins + [
            'winnumber' => $shown, 'wincolor' => $layout->color($number),
            'winhighlow' => $number === 0 ? 'zero' : ($number <= 18 ? 'low' : 'high'),
            'winoddeven' => ($number + 1) % 2 === 0 ? 'even' : 'odd',   // flipped, as NetEnt's server
        ], '&');
    }

    /** Spins the statistics look back on (NetEnt: about a thousand). */
    private const int HISTORY = 1000;

    /** This table's last results, newest last. */
    private function remember(GameContext $context, int $number): void
    {
        Cache::forever($this->historyKey($context), array_slice([...(array) Cache::get($this->historyKey($context), []), $number], -self::HISTORY));
    }

    /**
     * The table's last results, newest last. A table without any yet starts
     * from fair wheel draws — display statistics only, like NetEnt's shared
     * table history; real results take their place as it is played. (Its
     * client reads an empty `numbers=` as no statistics reply at all.)
     *
     * @return list<int>
     */
    private function history(GameContext $context, ?RouletteLayout $layout = null): array
    {
        $history = array_map('intval', (array) Cache::get($this->historyKey($context), []));
        if ($history === []) {
            $wheel = ($layout ?? new RouletteLayout)->wheel();
            $history = array_map(fn () => $wheel[random_int(0, count($wheel) - 1)], range(1, self::HISTORY));
            Cache::forever($this->historyKey($context), $history);
        }

        return $history;
    }

    private function historyKey(GameContext $context): string
    {
        return "netent:roulette:{$context->game->id}";
    }

    /**
     * The InfoServlet statistics, as NetEnt's French Roulette sends them:
     *   roulettestat           hot.0–4 (most drawn first), cold.1–4 (least drawn
     *                          first) — number, timesdrawn, percent, lastdrawn
     *                          (spins ago) — then colour and odd / even shares, %
     *   roulettestat_extended  numbers= the last 37 results, newest first
     * American: `americanroulettestat[_extended]`, 00 as 37 — green, and "zero".
     */
    public function stats(GameContext $context, string $operation, bool $american = false): string
    {
        $layout = new RouletteLayout($american);
        $history = $this->history($context, $layout);
        $op = $american ? preg_replace('/^american/', '', $operation) : $operation;
        if ($op === 'roulettestat_extended') {
            return 'numbers='.implode(',', array_slice(array_reverse($history), 0, 37));
        }
        if ($op !== 'roulettestat' || ($american && ! str_starts_with($operation, 'american'))) {
            return '';
        }
        $n = max(1, count($history));
        $counts = array_fill_keys($layout->wheel(), 0);
        foreach ($history as $x) {
            $counts[$x] = ($counts[$x] ?? 0) + 1;
        }
        $last = [];
        foreach (array_reverse($history) as $ago => $x) {
            $last[$x] ??= $ago;
        }
        $entry = fn (string $p, int $num) => ["{$p}.lastdrawn" => $last[$num] ?? $n, "{$p}.number" => $num,
            "{$p}.percent" => (int) round(100 * $counts[$num] / $n), "{$p}.timesdrawn" => $counts[$num]];
        $byCount = array_keys($counts);
        usort($byCount, fn ($a, $b) => [$counts[$a], $last[$b] ?? $n] <=> [$counts[$b], $last[$a] ?? $n]);   // least drawn first
        $f = [];
        foreach (array_slice($byCount, 0, 4) as $i => $num) {
            $f += $entry('cold.'.($i + 1), $num);
        }
        foreach (array_reverse(array_slice(array_reverse($byCount), 0, 5)) as $i => $num) {
            $f += $entry('hot.'.(4 - $i), $num);
        }
        $share = fn (callable $test) => (int) round(100 * count(array_filter($history, $test)) / $n);

        return NetEntReply::build($f + [
            'color.black' => $share(fn ($x) => $layout->color($x) === 'black'), 'color.green' => $share(fn ($x) => $layout->color($x) === 'green'),
            'color.red' => $share(fn ($x) => $layout->color($x) === 'red'),
            'oddeven.even' => $share(fn ($x) => $x > 0 && $x <= 36 && $x % 2 === 0), 'oddeven.odd' => $share(fn ($x) => $x <= 36 && $x % 2 === 1),
            'oddeven.zero' => $share(fn ($x) => $layout->color($x) === 'green'),
        ], '&');
    }

    /** @return array<string, int> cents: per limit group, and `minbet` / `maxbet` */
    private function limits(string $init, bool $touch): array
    {
        $f = [];
        foreach (NetEntTable::numbers($init) as $k => $v) {
            $f[$touch && str_starts_with($k, 'maxbet.') ? substr($k, 7) : $k] = $v;
        }

        return array_intersect_key($f, array_flip(['straight', 'split', 'threeline', 'corner', 'topline', 'sixline',
            'column', 'dozen', 'redblack', 'oddeven', 'highlow', 'minbet', 'maxbet']));
    }

    private function error(string $message): string
    {
        return NetEntReply::build(['errorcode' => '1', 'errormessage' => $message], '&');
    }
}
