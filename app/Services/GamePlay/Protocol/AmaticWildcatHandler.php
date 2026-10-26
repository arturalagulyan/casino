<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\Amatic\AmaticWildcatInit;
use App\Services\GamePlay\Engine\CascadeEngine;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * Amatic's WildCat Gaming titles (`gmsl/wcg/…`) on the shared Amatic socket —
 * the "tumble" family (AnubisGold: 6×5 pay-anywhere, wilds, multiplier
 * symbols, bonus spins). No legacy backend exists; the wire format is read
 * from the client's own parsers (`dZW` base frame + the `dVh` tail) and from
 * Amatic's live server. The maths is ours: {@see CascadeEngine} on the
 * template's strips, gated like every other slot.
 *
 *   A/u25                   init — Amatic's captured init frame with our balance
 *   A/u251,<l>,<bet>,…      spin — bet = index into the init's bet ladder (× coins per bet, cents)
 *   A/u2531,… / A/u2535,…   next tumble of the round (base / bonus spin)
 *   A/u256,…                bonus spin
 *   A/u2566,…,<n>           buy bonus n (price `buy[n]` × stake, `4 + n` bonus symbols)
 *   A/u250 / A/u254 / A/u350  resync / collect / balance poll
 *
 * A whole round (every tumble) is played and settled on its first command;
 * the client then pulls one frame per tumble. Frame states (client `YXf`):
 *   base   9 no win · 30 tumbles start · 31 next tumble · 33 round over · 5 bonus won
 *   bonus  6 no win · 34/35 tumbles · 36 tumbles over · 10 more spins won · 12/74 last spin over
 *   buy    66 bonus bought
 */
class AmaticWildcatHandler
{
    private const string LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /** Client symbol slots in the per-symbol win list. */
    private const int WIN_SLOTS = 12;

    public function __construct(
        private readonly AmaticFormatter $fmt,
        private readonly CascadeEngine $engine,
        private readonly AmaticWildcatInit $initFrame,
        private readonly AmaticHoldWinHandler $holdWin,
        private readonly AmaticWildcatLinesHandler $lines,
        private readonly AmaticPufferHandler $puffer,
        private readonly AmaticWildRushHandler $wildRush,
        private readonly AmaticDinopupsHandler $dinopups,
        private readonly AmaticSweetCandyHandler $sweetCandy,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     * @return list<string>
     */
    public function dispatch(GameContext $ctx, array $request): array
    {
        $mechanic = $this->table($ctx)['mechanic'] ?? 'tumble';
        if ($mechanic === 'holdwin') {
            return $this->holdWin->dispatch($ctx, $request);
        }
        if ($mechanic === 'sweetcandy') {
            return $this->sweetCandy->dispatch($ctx, $request);
        }
        if ($mechanic === 'dinopups') {
            return $this->dinopups->dispatch($ctx, $request);
        }
        if ($mechanic === 'wildrush') {
            return $this->wildRush->dispatch($ctx, $request);
        }
        if ($mechanic === 'puffer') {
            return $this->puffer->dispatch($ctx, $request);
        }
        if ($mechanic === 'lines') {
            return $this->lines->dispatch($ctx, $request);
        }
        $parts = explode(',', (string) strtok((string) ($request['gameData'] ?? ''), ';'));
        $state = $this->state($ctx);

        return match ($parts[0]) {
            'A/u25' => [$this->init($ctx)],
            'A/u251' => [$this->spin($ctx, (int) ($parts[2] ?? 0))],
            'A/u2531', 'A/u2535' => [$this->next($ctx)],
            'A/u256' => [$this->bonusSpin($ctx)],
            'A/u2566' => [$this->buy($ctx, (int) end($parts))],
            'A/u250', 'A/u254' => [$state['last'] ?? $this->idle($ctx)],
            'A/u350' => ['UPDATE#'.$this->cents($ctx->balance())],
            default => [$this->fmt->error($parts[0], 'unknown command')],
        };
    }

    // ---- commands --------------------------------------------------

    private function init(GameContext $ctx): string
    {
        $init = (string) ($this->table($ctx)['init'] ?? '');
        if ($init === '') {
            return $this->fmt->error('A/u25', 'game not configured');
        }
        // The captured init can't resume an interrupted round: finish it here.
        // Tumbles are already paid; bonus spins left are played out and paid.
        for ($guard = 0; $guard < 500 && (int) ($this->state($ctx)['free_left'] ?? 0) > 0; $guard++) {
            $this->save($ctx, ['queue' => []] + $this->state($ctx));
            $this->bonusSpin($ctx);
        }
        $this->save($ctx, ['queue' => [], 'last' => null] + $this->state($ctx));

        return $this->initFrame->withBalance($init, $this->cents($ctx->balance()));
    }

    private function spin(GameContext $ctx, int $betIndex): string
    {
        $state = $this->state($ctx);
        if (($state['queue'] ?? []) !== []) {
            return $this->next($ctx);   // a tumble still pending — the client lost track
        }
        if ((int) ($state['free_left'] ?? 0) > 0) {
            return $this->bonusSpin($ctx);
        }
        $stake = $this->stake($ctx, $betIndex);
        if ($stake === null) {
            return $this->fmt->error('A/u251', 'invalid bet');
        }
        if ($ctx->balance() < $stake) {
            return $this->fmt->error('A/u251', 'invalid balance');
        }
        $ctx->placeBet($stake);

        $cc = $ctx->config()->cascadeConfig();
        $result = $this->engine->spin($ctx, $stake, $stake / $cc['lines'], false);
        $grant = $this->freeSpinsFor($ctx, (int) ($result->extra['scatters'] ?? 0));

        return $this->settle($ctx, $result, [
            'bet_index' => $betIndex, 'stake' => $stake, 'free_left' => $grant, 'free_total' => $grant,
            'bonus_win' => 0.0, 'total_multiplier' => 0,
        ], false, $grant);
    }

    private function bonusSpin(GameContext $ctx): string
    {
        $state = $this->state($ctx);
        if (($state['queue'] ?? []) !== []) {
            return $this->next($ctx);
        }
        $left = (int) ($state['free_left'] ?? 0);
        if ($left <= 0) {
            return $this->fmt->error('A/u256', 'invalid bonus state');
        }
        $stake = (float) $state['stake'];
        $cc = $ctx->config()->cascadeConfig();
        $result = $this->engine->spin($ctx, $stake, $stake / $cc['lines'], true, [
            'total_multiplier' => (int) ($state['total_multiplier'] ?? 0),
        ]);
        $extra = $this->freeSpinsFor($ctx, (int) ($result->extra['scatters'] ?? 0), true);
        $state['free_left'] = $left - 1 + $extra;
        $state['free_total'] = (int) $state['free_total'] + $extra;
        $state['total_multiplier'] = (int) ($result->extra['total_multiplier'] ?? 0);

        return $this->settle($ctx, $result, $state, true, $extra);
    }

    /** Buy the bonus: price × stake, a board with `4 + $n` bonus symbols and nothing else paying. */
    private function buy(GameContext $ctx, int $n): string
    {
        $table = $this->table($ctx);
        $prices = array_values((array) ($table['buy'] ?? []));
        $state = $this->state($ctx);
        $stake = $this->stake($ctx, (int) ($state['bet_index'] ?? 0));
        if (! isset($prices[$n]) || $stake === null || ($state['queue'] ?? []) !== [] || (int) ($state['free_left'] ?? 0) > 0) {
            return $this->fmt->error('A/u2566', 'invalid buy');
        }
        $price = round($stake * (float) $prices[$n], 2);
        if ($ctx->balance() < $price) {
            return $this->fmt->error('A/u2566', 'invalid balance');
        }
        $ctx->placeBet($price);

        $cfg = $ctx->config();
        $cc = $cfg->cascadeConfig();
        $need = $cc['trigger'] + $n;
        for ($try = 0; $try < 200; $try++) {
            $round = $this->engine->play($cfg, $cc, $stake / $cc['lines'], false, $need);
            if ($round['cascade_win'] <= 0 && $round['scatters'] >= $need) {
                break;
            }
        }
        $grant = $this->freeSpinsFor($ctx, $need);
        $state = ['bet_index' => (int) ($state['bet_index'] ?? 0), 'stake' => $stake, 'free_left' => $grant,
            'free_total' => $grant, 'bonus_win' => 0.0, 'total_multiplier' => 0];
        $frame = $this->frame($ctx, $state, [
            'state' => 66, 'grid' => $round['steps'][0]['grid'], 'bombs' => $round['steps'][0]['bombs'],
            'wins' => [], 'step_win' => 0, 'win' => 0, 'shown' => 0, 'total' => 0, 'multiplier' => 0, 'pairs' => '', 'extra' => 0,
        ], $this->cents($ctx->balance()));
        $ctx->recordRound(
            new SpinResult(bet: $price, win: 0.0, state: 'bet'),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => ['buy' => $n, 'free_spins' => $grant]]),
        );
        $this->save($ctx, ['queue' => [], 'last' => $frame] + $state);

        return $frame;
    }

    /** The next pre-built tumble frame of the round in progress. */
    private function next(GameContext $ctx): string
    {
        $state = $this->state($ctx);
        $queue = (array) ($state['queue'] ?? []);
        if ($queue === []) {
            return $state['last'] ?? $this->idle($ctx);
        }
        $frame = (string) array_shift($queue);
        $this->save($ctx, ['queue' => $queue, 'last' => $frame] + $state);

        return $frame;
    }

    // ---- round → frames ------------------------------------------------

    /**
     * Pay the round, then pre-build one frame per tumble: the boards before
     * each removal, the final board last (balance with the win only there).
     *
     * @param  array<string, mixed>  $state  the round's state after this spin (free spins left, …)
     */
    private function settle(GameContext $ctx, SpinResult $result, array $state, bool $free, int $grant): string
    {
        $balanceBefore = $this->cents($ctx->balance());
        $win = $result->win > 0 ? $ctx->awardWin($result->win) : 0.0;
        $balanceAfter = $this->cents($ctx->balance());
        $bonusBefore = 0.0;
        if ($free) {
            $bonusBefore = (float) ($state['bonus_win'] ?? 0);
            $state['bonus_win'] = round($bonusBefore + $win, 2);
        }

        $steps = (array) ($result->extra['cascade'] ?? []);
        $mult = (int) ($result->extra['multiplier'] ?? 0);
        $cascadeWin = (float) ($result->extra['cascade_win'] ?? 0);
        $last = count($steps) - 1;
        $bonusOver = $free && (int) $state['free_left'] <= 0;
        $frames = [];
        $running = 0;
        $pairs = '';
        foreach ($steps as $k => $step) {
            $stepWin = $this->cents((float) $step['win']);
            $running += $stepWin;
            foreach ($step['wins'] as $w) {
                $pairs .= self::LETTERS[(int) $w['symbol']].self::LETTERS[min(63, (int) $w['count'])];
            }
            $final = $k === $last;
            $code = match (true) {
                ! $free && $final && $grant > 0 => 5,
                ! $free && $last === 0 => 9,
                ! $free => $final ? 33 : ($k === 0 ? 30 : 31),
                $final && $grant > 0 => 10,
                $last === 0 => $bonusOver ? 12 : 6,
                $final => $bonusOver ? 74 : 36,
                default => $k === 0 ? 34 : 35,
            };
            $frames[] = $this->frame($ctx, $state, [
                'state' => $code,
                'grid' => $step['grid'],
                'bombs' => $step['bombs'],
                'wins' => $step['wins'],
                'step_win' => $stepWin,
                'win' => $final ? $this->cents($win) : $running,
                // the tumble's own win; on the last board the pre-multiplier total when a multiplier applies
                'shown' => $final ? ($mult > 0 && $cascadeWin > 0 ? $this->cents($cascadeWin) : 0) : $stepWin,
                'total' => $final ? $this->cents($win) : $running,
                'multiplier' => $free ? ($final ? (int) $state['total_multiplier'] : (int) ($state['total_multiplier_before'] ?? 0)) : ($final ? $mult : 0),
                'pairs' => $pairs,
                'extra' => $final && $free ? $grant : 0,
                // the bonus total so far — this spin's win joins it on its last board
                'bonus' => $final ? (float) ($state['bonus_win'] ?? 0) : $bonusBefore,
            ], $final ? $balanceAfter : $balanceBefore);
        }

        $first = array_shift($frames);
        $ctx->recordRound(
            new SpinResult(bet: $free ? 0.0 : (float) $state['stake'], win: $win, state: $free ? 'freespin' : 'bet'),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => [
                'tumbles' => count($steps), 'multiplier' => $mult, 'scatters' => $result->extra['scatters'] ?? 0, 'totalWin' => $win,
            ]]),
        );
        if ($bonusOver) {
            $state = ['bet_index' => $state['bet_index'], 'stake' => $state['stake'], 'free_left' => 0, 'free_total' => 0, 'bonus_win' => 0.0, 'total_multiplier' => 0];
        }
        $state['total_multiplier_before'] = (int) ($state['total_multiplier'] ?? 0);
        $this->save($ctx, ['queue' => $frames, 'last' => $first] + $state);

        return (string) $first;
    }

    /**
     * One spin frame: the shared base fields (`dZW` spin parser) then the
     * WildCat tail (`dVh.dZ4`) and the usual `#` trailer.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $f
     */
    private function frame(GameContext $ctx, array $state, array $f, int $balance): string
    {
        $h = fn (int|float $n) => $this->fmt->hexFmt($n);
        $grid = (array) $f['grid'];
        $reels = count($grid);
        $free = (int) ($state['free_total'] ?? 0) > 0;

        $perSymbol = array_fill(0, self::WIN_SLOTS, 0);
        foreach ((array) $f['wins'] as $w) {
            $perSymbol[(int) $w['symbol']] = ($perSymbol[(int) $w['symbol']] ?? 0) + $this->cents((float) $w['amount']);
        }

        $out = '-11'.sprintf('%02x', $f['state']).'0'.$h(random_int(0x10000000, 0x7fffffff)).$h($balance).$h($f['win'])
            .sprintf('%02x', $reels).str_repeat('10', max(5, $reels))
            .sprintf('%02x', (int) ($state['bet_index'] ?? 0)).sprintf('%02x', $this->lines($ctx))
            .$h($free ? (int) $state['free_total'] : 0)
            .$h($free ? (int) $state['free_left'] : 0)
            .$h($free ? $this->cents((float) ($f['bonus'] ?? $state['bonus_win'] ?? 0)) : 0)
            .'101010'
            .sprintf('%02x', $reels).str_repeat('10', max(5, $reels))
            .sprintf('%02x', self::WIN_SLOTS).implode('', array_map($h, array_slice($perSymbol, 0, self::WIN_SLOTS)))
            .str_repeat('00', 8);

        // dVh tail: version, dtl, two "none" flags, the board (columns top→bottom)
        $out .= $h(4).$h(0).'ff'.$h($reels);
        foreach ($grid as $col) {
            $out .= $this->letters(array_map(fn ($s) => self::LETTERS[(int) $s], $col));
        }
        $values = array_fill(0, $reels, []);
        foreach ((array) $f['bombs'] as $b) {
            $values[(int) $b['reel']][(int) $b['row']] = (int) $b['value'];
        }
        $out .= $h(0)                                             // wild transforms (none)
            .$h((int) $f['multiplier']).$h($reels);
        foreach ($values as $col) {
            ksort($col);
            $out .= $h(count($col)).implode('', array_map($h, $col));
        }
        $out .= $h($f['step_win']).$this->letters(str_split((string) $f['pairs']) ?: [])
            .$h($f['shown']).$h($f['total']).'0'.$h($f['extra']).'#101010';

        return $out;
    }

    /** @param list<string> $chars */
    private function letters(array $chars): string
    {
        $chars = array_values(array_filter($chars, fn ($c) => $c !== ''));

        return $this->fmt->hexFmt(count($chars)).implode('', $chars);
    }

    /** A no-win frame on a fresh board — for a resync before any round. */
    private function idle(GameContext $ctx): string
    {
        $cfg = $ctx->config();
        $cc = $cfg->cascadeConfig();
        do {
            $round = $this->engine->play($cfg, $cc, 0.01, false);
        } while ($round['cascade_win'] > 0 || $round['scatters'] >= $cc['trigger']);

        return $this->frame($ctx, $this->state($ctx), [
            'state' => 9, 'grid' => $round['grid'], 'bombs' => [], 'wins' => [], 'step_win' => 0, 'win' => 0,
            'shown' => 0, 'total' => 0, 'multiplier' => 0, 'pairs' => '', 'extra' => 0,
        ], $this->cents($ctx->balance()));
    }

    // ---- config / state ------------------------------------------------

    /** @return array<string, mixed> */
    private function table(GameContext $ctx): array
    {
        return (array) ($ctx->config()->bonusConfig()['table'] ?? []);
    }

    /** @return array<string, mixed> */
    private function state(GameContext $ctx): array
    {
        return (array) (((array) $ctx->stateGet('features', []))['wildcat'] ?? []);
    }

    /** @param array<string, mixed> $state */
    private function save(GameContext $ctx, array $state): void
    {
        $ctx->statePut(['features' => ['wildcat' => $state]]);
    }

    /** Stake of ladder step `$index`, in money (the ladder × coins per bet are cents). */
    private function stake(GameContext $ctx, int $index): ?float
    {
        $init = $this->initFrame->tail((string) ($this->table($ctx)['init'] ?? ''));
        $step = $init['ladder'][$index] ?? null;

        return $step === null ? null : round($step * $init['coins'] / 100, 2);
    }

    private function lines(GameContext $ctx): int
    {
        return $this->initFrame->tail((string) ($this->table($ctx)['init'] ?? ''))['lines'];
    }

    private function freeSpinsFor(GameContext $ctx, int $scatters, bool $retrigger = false): int
    {
        $cc = $ctx->config()->cascadeConfig();
        if ($scatters < ($retrigger ? $cc['retrigger'] : $cc['trigger'])) {
            return 0;
        }
        $grant = 0;
        foreach ((array) ($this->table($ctx)['free_spins'] ?? []) as $count => $spins) {
            if ($scatters >= (int) $count) {
                $grant = (int) $spins;
            }
        }

        return $grant ?: ($retrigger ? $cc['retrigger_spins'] : $cc['free_spins']);
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
