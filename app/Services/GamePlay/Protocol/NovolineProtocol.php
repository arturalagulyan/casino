<?php

namespace App\Services\GamePlay\Protocol;

use App\Models\NovolineRound;
use App\Models\NovolineSetup;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Novoline\NovolineLibrary;
use App\Services\GamePlay\Novoline\NovolineLiveClient as Wire;
use App\Services\GamePlay\SpinResult;
use Illuminate\Support\Facades\Log;

/**
 * Greentube's game server (Novoline slots), our side. The page's WebSocket
 * shim POSTs each client frame; the reply is the frames the server sends back
 * ({@see Wire} for the wire format):
 *
 *   \x01 login     the recorded init state (our balance, line bets the shop
 *                  offers, lines pinned to the recorded count) and config
 *   2 bet          the line bet for the next spin (no reply)
 *   3 spin         stake taken, a round drawn from the outcome library
 *                  ({@see NovolineLibrary}), its whole payout paid, its
 *                  first step served
 *   4 / 6 / …      a round in play: its next recorded step (collect, free spin…)
 *   :<n>           a pick on a feature's start screen (Wild Horses' side,
 *                  Sweet Sins' apple): the next recorded step, the player's
 *                  pick shown; other picks change nothing ({@see pick})
 *   9, 5r / 5b, 4  the gamble on a round's last win: red / black, double or
 *                  nothing ({@see SlotEngine::gamble}), not recorded; collect
 *
 * Rounds are recorded at the smallest line bet b; served at a line bet of B,
 * every credit amount is × k = B/b. The state's `S` (balance, credits) is ours:
 * moved by the recorded change × k mid-round, the real balance once idle —
 * a pending win (`W`, state 11) shows outside it, as the real server does.
 */
class NovolineProtocol
{
    /** Credit amounts: field key → value indexes (0-based, after the key). */
    private const array CREDITS = ['W' => [0], ':x' => [0], 'l' => [5], 'c' => [4], 'o' => [4], 'm' => [0], 'f' => [5, 6]];   // l: a line win, c: scatter, o: ways (Magic 81), m: an extra win (Quest for Gold)

    private const string ACK = "%t0\u{FF}q0";

    public function __construct(private readonly NovolineLibrary $library) {}

    /** @return list<string> frames to hand the client */
    public function dispatch(GameContext $context, string $frame): array
    {
        $type = $frame[0] ?? '';
        $body = substr($frame, 1);

        return match ($type) {
            "\x01" => $this->login($context),
            '2' => $this->bet($context, $body),
            '3' => $this->spin($context),
            '9' => $this->gambleStart($context),
            '5' => $this->gamble($context, strtolower(substr($body, 0, 1)) === 'r'),
            '4' => $this->collect($context, $frame),
            '6', '7', '8' => $this->next($context, $frame) ?? [],
            ':' => $this->pick($context, $frame),
            default => [],   // hello, ready, timing reports: no reply
        };
    }

    /** @return list<string> */
    private function login(GameContext $context): array
    {
        $context->statePut(['nv' => null, 'nv_g' => null]);   // a round left unfinished was already paid
        $cfg = $this->config($context);
        $steps = $this->steps($context);
        $perLine = $this->perLine($context);
        $out = [];
        foreach ($this->setup($context)->login as $f) {
            if (($f[0] ?? '') !== "\x06") {
                $out[] = $f;

                continue;
            }
            $fields = Wire::fields($f);
            // line options: `:i,<lines>` one field each; `:f` (the option's id — a bet's third
            // value), `:m` (stake multiple), `:e` one value each — only the recorded option stays
            $fIds = Wire::field($f, ':f') ?? [];
            $j = array_search((string) ($cfg['x'] ?? ''), $fIds, true);
            $option = 0;
            foreach ($fields as $i => $x) {
                $key = Wire::key($x);
                if ($j !== false && $key === ':i') {
                    if ($option++ !== $j) {
                        unset($fields[$i]);
                    }
                } elseif ($j !== false && in_array($key, [':f', ':m', ':e'], true)) {
                    $v = explode(',', $x);
                    $fields[$i] = $key.','.($v[$j + 1] ?? end($v));
                } elseif ($key === ':b') {
                    $fields[$i] = ':b,'.count($steps).','.implode(',', $steps);
                } elseif ($key === 'e') {
                    $v = explode(',', $x);
                    $v[2] = (string) $perLine;
                    $fields[$i] = implode(',', $v);
                } elseif ($key === 's') {
                    $fields[$i] = 's,1';
                }
            }
            $out[] = $this->frame($context, array_values($fields), $this->credits($context, $context->balance()), 1.0, $perLine, recorded: false);
        }

        return $out;
    }

    /** @return list<string> */
    private function bet(GameContext $context, string $body): array
    {
        $perLine = (int) (explode(Wire::SEP, $body)[0] ?? 0);
        if (in_array($perLine, $this->steps($context), true)) {
            $context->statePut(['nv_bet' => $perLine]);
        }

        return [];
    }

    /** @return list<string> */
    private function spin(GameContext $context): array
    {
        $state = $context->stateGet('nv');
        if (is_array($state)) {
            $round = NovolineRound::query()->find($state['round']);
            $step = $round?->steps[$state['next']] ?? null;
            // only the closing collect left (the client spins straight from a win): the round is over
            if ($round !== null && $step !== null && ! ($this->isLast($round, (int) $state['next']) && ($step['c'][0] ?? '') === '4')) {
                return $this->serve($context, $round, (int) $state['next'], $state);
            }
            $context->statePut(['nv' => null]);
        }
        $context->statePut(['nv_g' => null]);

        $cfg = $this->config($context);
        $perLine = $this->perLine($context);
        $k = $perLine / max(1, (int) $cfg['per_line']);
        $stake = round((int) $cfg['bet_cents'] * $k / 100, 2);
        if ($stake > $context->balance() + 1e-9) {
            return [];   // the client checks its credit first; nothing to play
        }
        $before = $context->balance();
        $context->placeBet($stake);
        $budget = min($context->bankAvailable(), $context->maxWin($stake));
        $round = $this->library->pick((string) $cfg['game_key'], $context->rtpTarget(), (int) floor($budget * 100 / $k));
        $win = round($round->win_cents * $k / 100, 2);
        $paid = $context->awardWin($win);
        $context->recordRound(new SpinResult($stake, $paid, [], [], 'bet', ['novoline_round' => $round->id]),
            json_encode(['round' => $round->id, 'per_line' => $perLine]) ?: '');

        return $this->serve($context, $round, 0, ['round' => $round->id, 'k' => $k, 'per_line' => $perLine, 'before' => $before]);
    }

    /** A round in play: its next step, whatever the client pressed. @return list<string>|null */
    private function next(GameContext $context, string $frame): ?array
    {
        $state = $context->stateGet('nv');
        if (! is_array($state)) {
            return null;
        }
        $round = NovolineRound::query()->find($state['round']);
        if ($round === null || ! isset($round->steps[$state['next']])) {
            Log::info("Novoline round {$state['round']}: {$frame} past the recording's end");
            $context->statePut(['nv' => null]);

            return null;
        }

        return $this->serve($context, $round, (int) $state['next'], $state);
    }

    /**
     * A pick (`:<n>`). On a win's chance feature (13) it ends it as recorded.
     * Made on a feature's start screen (state 15 — Wild
     * Horses' side, Sweet Sins' apple) it starts the feature: the round goes
     * on as recorded (the outcome is the recording's), the pick shown is the
     * player's. Anything else (`:-1`, a pointer moving over the choices; a
     * pick once the feature runs) changes nothing: the state again, the pick
     * written in.
     *
     * @return list<string>
     */
    private function pick(GameContext $context, string $frame): array
    {
        $state = $context->stateGet('nv');
        if (! is_array($state)) {
            return [];
        }
        $n = (int) substr($frame, 1);
        $last = (string) ($state['last'] ?? '');
        // a win's chance feature (13: Magic 27's mystery, Hot Chance's wheel) started: it ends as
        // recorded — the demo server was asked for the win (a mystery value; the wheel on COLLECT)
        if ($n >= 0 && Wire::stateOf($last) === 13) {
            return $this->next($context, $frame) ?? [];
        }
        if ($n >= 0 && Wire::stateOf($last) === 15) {
            $round = NovolineRound::query()->find($state['round']);
            if (($round?->steps[$state['next']]['c'][0] ?? '') === ':') {   // recorded with the pick (Wild Horses): play on
                $context->statePut(['nv' => array_merge($state, ['pick' => $n, 'picked' => true])]);

                return $this->next($context, $frame) ?? [];
            }
            // recorded without one (the demo server lets a free game skip it — Sweet Sins): the
            // pick taken, the feature starting — its first free game is the next recorded step
            $fields = array_values(array_filter(Wire::fields($last), fn (string $x) => Wire::key($x) !== 'v'));
            $taken = self::withPick("\x06".implode(Wire::SEP, array_map(fn (string $x) => Wire::key($x) === 's' ? 's,5' : $x, $fields)), $n, true);
            $context->statePut(['nv' => array_merge($state, ['pick' => $n, 'last' => $taken])]);

            return [$taken];
        }
        if ($last === '') {
            return [];
        }

        return [$n >= 0 ? self::withPick($last, $n, true) : $last];
    }

    /**
     * The pick written into a state: `x,<kind>,<pick>,…` (Wild Horses `x,2`,
     * Sweet Sins `x,1`); added as `x,1,<pick>` when the state has none and `$add`.
     */
    private static function withPick(string $frame, int $pick, bool $add): string
    {
        $fields = Wire::fields($frame);
        $found = false;
        foreach ($fields as $i => $x) {
            if (preg_match('/^x,([12]),\d+(,|$)/', $x)) {
                $fields[$i] = (string) preg_replace('/^x,([12]),\d+/', "x,\$1,{$pick}", $x);
                $found = true;
            }
        }
        if (! $found && $add) {
            array_push($fields, '', "x,1,{$pick}");
        }

        return "\x06".implode(Wire::SEP, $fields);
    }

    /**
     * Recorded step `$i` rescaled; keeps or ends the round state.
     *
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function serve(GameContext $context, NovolineRound $round, int $i, array $state): array
    {
        $k = (float) $state['k'];
        $adj = (int) ($state['adj'] ?? 0);   // credits a gamble within the round moved the recorded wins by
        $last = $this->isLast($round, $i);
        $out = [];
        foreach ($round->steps[$i]['r'] as $f) {
            $type = $f[0] ?? '';
            if ($type === "\x1f") {
                $out[] = "\x1f".(int) round((int) substr($f, 1) * $k);
            } elseif ($type === "\x06") {
                $recorded = Wire::balanceOf($f);
                $credits = $last ? $this->credits($context, $context->balance())
                    : $this->credits($context, (float) $state['before']) + (int) round(($recorded - $round->start_credit) * $k) + $adj;
                $served = $this->frame($context, Wire::fields($f), $credits, $k, (int) $state['per_line'], adj: $adj);
                // the pick the player made (the recording's may differ; the first state after it shows it)
                $out[] = isset($state['pick']) ? self::withPick($served, (int) $state['pick'], ! empty($state['picked'])) : $served;
            } elseif ($type !== "\x00" && $type !== 's') {
                $out[] = $f;
            }
        }
        $shownState = (string) collect($out)->last(fn ($f) => ($f[0] ?? '') === "\x06");
        $context->statePut(['nv' => $last ? null : array_merge($state, ['next' => $i + 1, 'last' => $shownState, 'picked' => false]), 'nv_g' => null]);

        // a win waiting for its collect — the round's last, or a free game's: it may be gambled instead
        $shown = (string) end($out);
        if (! $last && ($round->steps[$i + 1]['c'][0] ?? '') === '4' && Wire::stateOf($shown) === 11 && ($w = self::pending($shown)) > 0) {
            $after = (string) collect($round->steps[$i + 1]['r'])->last(fn ($f) => ($f[0] ?? '') === "\x06");
            $context->statePut(['nv_g' => ['w' => $w, 'base' => $w, 'n' => 0, 'frame' => $shown,
                'resume' => $i + 2, 'end' => ! isset($round->steps[$i + 2]), 'then' => Wire::stateOf($after)]]);
        }

        return $out;
    }

    /** A win waiting to be collected: `W`, or (a last free game) what `f`'s total grew by. */
    private static function pending(string $frame): int
    {
        $w = Wire::field($frame, 'W');
        if ($w !== null) {
            return (int) $w[0];
        }
        $f = Wire::field($frame, 'f') ?? [];

        return isset($f[5], $f[6]) ? (int) $f[6] - (int) $f[5] : 0;
    }

    /** @return list<string> */
    private function gambleStart(GameContext $context): array
    {
        $g = $context->stateGet('nv_g');
        if (! is_array($g) || ! $this->gambleAllowed($context)) {
            return [];
        }
        $ladder = [];
        for ($i = 1; $i <= min(5, $this->gambleMax($context)); $i++) {   // the client shows five steps ahead
            $ladder[] = 'q,'.((int) $g['base'] << $i).',0';
        }
        $fields = [];
        foreach (Wire::fields((string) $g['frame']) as $x) {
            $key = Wire::key($x);
            if ($key === 'q') {
                continue;
            }
            if ($key === 's') {
                array_push($fields, 's,3', ...$ladder);

                continue;
            }
            $fields[] = $key === 'I' ? 'I10' : $x;
        }
        $frame = "\x06".implode(Wire::SEP, $fields);
        $context->statePut(['nv_g' => array_merge($g, ['frame' => $frame, 'on' => true])]);

        return [self::ACK, $frame];
    }

    /** @return list<string> */
    private function gamble(GameContext $context, bool $red): array
    {
        $g = $context->stateGet('nv_g');
        if (! is_array($g) || empty($g['on'])) {
            return [];
        }
        $stake = round((int) $g['w'] / $this->factor($context), 2);
        $won = app(SlotEngine::class)->gamble($context, $stake)['won'];
        if ($won) {
            $context->awardWin($stake);
        } else {
            $context->clawback($stake);
        }
        $context->recordRound(new SpinResult($won ? 0.0 : $stake, $won ? $stake * 2 : 0.0, [], [], 'gamble'),
            json_encode(['gamble' => $won ? 'won' : 'lost', 'amount' => $stake]) ?: '');

        $cards = $won === $red ? ['D', 'H'] : ['C', 'S'];
        $card = $cards[random_int(0, 1)];
        $fields = [];
        foreach (Wire::fields((string) $g['frame']) as $x) {
            $fields[] = Wire::key($x) === 'h' ? 'h,'.substr(substr($x, 2).$card, 1) : $x;
        }
        if (! $won) {
            return $this->settle($context, $g, $fields, 0);
        }
        $w = (int) $g['w'] * 2;
        $fields = self::put($fields, 'W', "W,{$w}", 'I');
        $fields[] = 'g,'.(int) $g['w'].',0';
        $g = array_merge($g, ['w' => $w, 'n' => (int) $g['n'] + 1, 'frame' => "\x06".implode(Wire::SEP, $fields)]);
        $context->statePut(['nv_g' => $g]);
        if ($g['n'] >= $this->gambleMax($context)) {
            return $this->collect($context, '40');
        }

        return [self::ACK, $g['frame']];
    }

    /** @return list<string> */
    private function collect(GameContext $context, string $frame): array
    {
        $g = $context->stateGet('nv_g');
        if (is_array($g) && ! empty($g['on'])) {   // a gambled win: taken
            return $this->settle($context, $g, Wire::fields((string) $g['frame']), (int) $g['w']);
        }
        $context->statePut(['nv_g' => null]);

        return $this->next($context, $frame) ?? [];
    }

    /**
     * A gamble over: `$taken` credits collected (0: lost). The recorded collect
     * is skipped — the round goes on from the step after it (free games), the
     * wins it shows moved by what the gamble changed; or it is over.
     *
     * @param  array<string, mixed>  $g
     * @param  list<string>  $fields  the gamble's frame
     * @return list<string>
     */
    private function settle(GameContext $context, array $g, array $fields, int $taken): array
    {
        $shown = Wire::balanceOf((string) $g['frame']);
        $out = [];
        foreach ($fields as $x) {
            $key = Wire::key($x);
            if ($key === 'q' || $key === 'h' && $taken > 0 || $key === ':x' && $taken > 0) {
                continue;
            }
            if ($taken === 0 && (in_array($key, ['l', 'c', 'g'], true) || $x === '')) {
                continue;
            }
            if ($key === 'W') {
                if ($taken === 0 && ! $g['end']) {
                    $out[] = 'W,0';
                }

                continue;
            }
            if ($key === 'f') {
                $v = explode(',', $x);
                if (isset($v[6], $v[7])) {
                    $v[7] = $v[6] = (string) ((int) $v[6] + $taken);
                }
                $x = implode(',', $v);
            }
            if ($key === 's') {
                if ($taken > 0) {
                    $out[] = ":x,{$taken}";
                }
                $x = 's,'.($g['end'] ? 1 : (int) $g['then']);
            }
            if ($key === 'I') {
                $x = $taken > 0 || $g['end'] ? 'I11' : 'I10';
            }
            $out[] = $x;
        }
        $state = $context->stateGet('nv');
        if ($g['end'] || ! is_array($state)) {
            $context->statePut(['nv' => null, 'nv_g' => null]);
            $credits = $this->credits($context, $context->balance());
        } else {
            $context->statePut(['nv_g' => null, 'nv' => array_merge($state, [
                'next' => (int) $g['resume'], 'adj' => (int) ($state['adj'] ?? 0) + $taken - (int) $g['base']])]);
            $credits = $shown + $taken;
        }

        return [self::ACK, $this->state($context, $out, $credits)];
    }

    /**
     * A field set (or added after the field `$after`).
     *
     * @param  list<string>  $fields
     * @return list<string>
     */
    private static function put(array $fields, string $key, string $value, string $after): array
    {
        foreach ($fields as $i => $x) {
            if (Wire::key($x) === $key) {
                $fields[$i] = $value;

                return $fields;
            }
        }
        foreach ($fields as $i => $x) {
            if (Wire::key($x) === $after) {
                array_splice($fields, $i + 1, 0, [$value]);

                return $fields;
            }
        }
        $fields[] = $value;

        return $fields;
    }

    /**
     * A state frame with our balance, money × k and our bet written in.
     *
     * @param  list<string>  $fields
     */
    private function frame(GameContext $context, array $fields, int $credits, float $k, int $perLine, bool $recorded = true, int $adj = 0): string
    {
        foreach ($fields as $i => $x) {
            $key = Wire::key($x);
            if ($i === 0 && str_starts_with($x, 'S')) {
                $fields[$i] = 'S'.$credits;
            } elseif ($key === 'T') {
                $fields[$i] = 'T,0,0,0';
            } elseif ($key === 'e' && $recorded) {
                $v = explode(',', $x);
                $v[2] = (string) $perLine;
                $fields[$i] = implode(',', $v);
            } elseif (isset(self::CREDITS[$key]) && abs($k - 1) > 1e-9) {
                $v = explode(',', $x);
                foreach (self::CREDITS[$key] as $j) {
                    if (isset($v[$j + 1]) && is_numeric($v[$j + 1])) {
                        $v[$j + 1] = (string) (int) round((float) $v[$j + 1] * $k);
                    }
                }
                $fields[$i] = implode(',', $v);
            }
            if ($key === 'f' && $adj !== 0) {   // the free games' running totals, after a gamble
                $v = explode(',', $fields[$i]);
                foreach ([6, 7] as $j) {
                    if (isset($v[$j]) && is_numeric($v[$j])) {
                        $v[$j] = (string) max(0, (int) $v[$j] + $adj);
                    }
                }
                $fields[$i] = implode(',', $v);
            }
        }

        return "\x06".implode(Wire::SEP, $fields);
    }

    /** @param list<string> $fields */
    private function state(GameContext $context, array $fields, int $credits): string
    {
        if (! str_starts_with($fields[0] ?? '', 'S')) {
            array_unshift($fields, 'S'.$credits);
        }
        $fields[0] = 'S'.$credits;

        return "\x06".implode(Wire::SEP, $fields);
    }

    private function isLast(NovolineRound $round, int $i): bool
    {
        return ! isset($round->steps[$i + 1]);
    }

    /** The line bet for the next spin (credits). */
    private function perLine(GameContext $context): int
    {
        $bet = (int) $context->stateGet('nv_bet', 0);
        $steps = $this->steps($context);

        return in_array($bet, $steps, true) ? $bet : $steps[0];
    }

    /**
     * Line bets the player may pick: the game's own, kept to those whose
     * stakes the shop's bet options include.
     *
     * @return list<int>
     */
    public function steps(GameContext $context): array
    {
        $cfg = $this->config($context);
        $all = array_values(array_map('intval', (array) ($cfg['bet_steps'] ?? [1])));
        $options = array_map(fn ($b) => (int) round((float) $b * 100), $context->betOptions());
        $ok = array_values(array_filter($all, fn (int $s) => in_array(
            (int) round($s / max(1, (int) $cfg['per_line']) * (int) $cfg['bet_cents']), $options, true)));

        return $ok ?: $all;
    }

    private function gambleAllowed(GameContext $context): bool
    {
        return (bool) ($this->config($context)['gamble'] ?? true);
    }

    private function gambleMax(GameContext $context): int
    {
        return max(1, min(10, (int) ($this->config($context)['gamble_max'] ?? 5)));
    }

    /** Credits a unit of money is worth (Greentube's CURRENCYFACTOR). */
    private function factor(GameContext $context): float
    {
        return max(1.0, (float) ($this->config($context)['factor'] ?? 100));
    }

    private function credits(GameContext $context, float $money): int
    {
        return (int) round($money * $this->factor($context));
    }

    private function setup(GameContext $context): NovolineSetup
    {
        return NovolineSetup::query()->findOrFail((string) $this->config($context)['game_key']);
    }

    /** @return array<string, mixed> the template's captured Novoline setup */
    private function config(GameContext $context): array
    {
        return (array) ($context->config()->bonusConfig()['novoline'] ?? []);
    }
}
