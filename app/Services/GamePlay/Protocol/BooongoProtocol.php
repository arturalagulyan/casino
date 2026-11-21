<?php

namespace App\Services\GamePlay\Protocol;

use App\Models\BooongoRound;
use App\Services\GamePlay\Booongo\BooongoLibrary;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;
use Illuminate\Support\Facades\Log;

/**
 * Booongo's game server ("WebEngine.php"), our side: the game runner's
 * `POST {server}?gsc=<command>` calls, JSON in and out.
 *
 *   login     our session, balance and currency
 *   start     the recorded settings (line bets kept to the stakes the game
 *             offers) and an idle context
 *   play      `spin` with no round in play: stake taken, a round drawn from
 *             the outcome library ({@see BooongoLibrary}), its whole win
 *             paid, its first reply served; any other action (freespin_init,
 *             freespin, bonus_init, respin, …_stop) while a round is in play:
 *             its next recorded reply, no stake
 *   sync      balance and the context last served (keep-alive)
 *   anything else (the runner's log / slow-request reports): acknowledged
 *
 * Rounds are recorded at the smallest line bet b; served at line bet B,
 * every money field is × B/b, and `user.balance` is our balance moved by the
 * recorded change × B/b (the round's last reply: our real balance). Money is
 * in cents (`currency_format.denominator` 100).
 */
class BooongoProtocol
{
    /** Money fields (cents) of a context, at any depth — lists / boards: every number in them. */
    public const array MONEY = ['amount', 'round_win', 'round_bet', 'total_win', 'last_win', 'bs_v', 'bs',
        'win', 'macro_win', 'macro_round_win', 'boost_win', 'boostAddWin', 'total_win_state', 'jackpotPay', 'jackpotPay2', 'jackpotPay3'];
    // not money: bs_values, jackpotMultiple, mystery_values, wilds_coef, wild_symbols_values, mult — multipliers

    public function __construct(private readonly BooongoLibrary $library) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function dispatch(GameContext $context, string $command, array $body): array
    {
        $reply = match ($command) {
            'login' => $this->login($context),
            'start' => $this->start($context),
            'sync' => $this->envelope($context, $this->shownBalance($context), $this->shownContext($context)),
            'play' => $this->play($context, (array) ($body['action'] ?? [])),
            default => ['status' => ['code' => 'OK', 'message' => '']],
        };

        return array_merge($reply, ['command' => $command, 'request_id' => (string) ($body['request_id'] ?? '')]);
    }

    /** @return array<string, mixed> */
    private function login(GameContext $context): array
    {
        $context->statePut(['booongo' => null, 'booongo_last' => null]);   // a round left unfinished was already paid

        return ['session_id' => $context->session()->token, 'status' => ['code' => 'OK', 'message' => ''],
            'modes' => ['auto', 'play'], 'freebets' => [], 'awards' => [], 'user' => $this->user($context, $this->balance($context))];
    }

    /** @return array<string, mixed> */
    private function start(GameContext $context): array
    {
        $context->statePut(['booongo' => null, 'booongo_last' => null]);
        $cfg = $this->config($context);
        $settings = (array) ($cfg['settings'] ?? []);
        $settings['bets'] = $this->bets($context);
        $settings['lines'] = [(int) $cfg['lines']];

        return array_merge($this->envelope($context, $this->balance($context), $this->idle($context)), [
            'settings' => $settings, 'freebet' => null, 'origin_data' => ['data' => ['quick_spin' => false], 'quick_spin' => false],
        ]);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function play(GameContext $context, array $action): array
    {
        $name = (string) ($action['name'] ?? '');
        $state = $context->stateGet('booongo');
        if (is_array($state)) {   // the round in play: its next step, whatever the client calls it
            $round = BooongoRound::query()->find($state['round']);
            if ($round !== null && isset($round->steps[$state['next']])) {
                $expected = (string) ($round->steps[$state['next']]['context']['last_action'] ?? '');
                if ($name === 'spin') {
                    return $this->error('ROUND_IN_PROGRESS', 'crit');
                }
                if ($expected !== '' && $expected !== $name && ! (is_array($round->steps[$state['next']]['context']['last_action'] ?? null))) {
                    Log::info("Booongo round {$round->id}: client sent {$name}, recording has {$expected}");
                }

                $pick = isset($action['params']['pick']) && is_numeric($action['params']['pick']) ? (int) $action['params']['pick'] : null;

                return $this->serve($context, $round, (int) $state['next'], $state, $pick);
            }
            Log::info("Booongo round {$state['round']}: a play past the recording's end");
            $context->statePut(['booongo' => null]);
        }
        if ($name !== 'spin') {
            return $this->error('ACTION_NOT_ALLOWED', 'crit');
        }

        $cfg = $this->config($context);
        $params = (array) ($action['params'] ?? []);
        $bpl = (float) ($params['bet_per_line'] ?? 0);
        $valid = in_array((int) round($bpl * 100), array_map(fn ($b) => (int) round((float) $b * 100), $this->bets($context)), true);
        if (! $valid || (int) ($params['lines'] ?? $cfg['lines']) !== (int) $cfg['lines']) {
            return $this->error('WRONG_BET', 'crit');
        }
        $k = $bpl / $this->recordBet($cfg);
        $stake = round((int) $cfg['bet_cents'] * $k / 100, 2);
        if ($stake > $context->balance() + 1e-9) {
            return $this->error('FUNDS_EXCEED', 'exceed');
        }
        $before = $this->balance($context);
        $context->placeBet($stake);
        $budget = min($context->bankAvailable(), $context->maxWin($stake));
        $round = $this->library->pick((string) $cfg['game_key'], $context->rtpTarget(), (int) floor($budget * 100 / $k));
        $win = round($round->win_cents * $k / 100, 2);
        $paid = $context->awardWin($win);
        $context->recordRound(new SpinResult($stake, $paid, [], [], 'bet', ['booongo_round' => $round->id]),
            json_encode(['round' => $round->id, 'bet_per_line' => $bpl]) ?: '');
        $context->statePut(['booongo_bpl' => $bpl]);

        return $this->serve($context, $round, 0, ['round' => $round->id, 'k' => $k, 'bpl' => $bpl, 'before' => $before]);
    }

    /**
     * Recorded step `$i` rescaled; keeps or ends the round state.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function serve(GameContext $context, BooongoRound $round, int $i, array $state, ?int $pick = null): array
    {
        $step = $round->steps[$i];
        $ctx = $this->scale((array) $step['context'], (float) $state['k'], (float) $state['bpl']);
        if ($pick !== null) {
            $ctx = $this->pickAt($ctx, $pick);
        }
        $ctx['last_win'] = $this->lastWin($context, $round, $i, $ctx);
        $last = ! isset($round->steps[$i + 1]);
        $balance = $last ? $this->balance($context)
            : round((float) $state['before'] + ((int) $step['balance'] - $round->start_credit) * (float) $state['k'] / 100, 2);
        $context->statePut(['booongo' => $last ? null : array_merge($state, ['next' => $i + 1, 'shown' => $balance]),
            'booongo_last' => ['round' => $round->id, 'i' => $i, 'k' => $state['k'], 'bpl' => $state['bpl']]]);

        return $this->envelope($context, $balance, $ctx);
    }

    /**
     * A pick (African Spirit's free-spin mode boxes: `freespin_pick` = the
     * mode under each box, `freespin_mode` the one picked): the recorded
     * pick's box swapped with the player's, so the box they chose holds the
     * recorded outcome.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function pickAt(array $ctx, int $pick): array
    {
        $cur = is_string($ctx['current'] ?? null) ? $ctx['current'] : '';
        $boxes = $ctx[$cur]['freespin_pick'] ?? null;
        $mode = $ctx[$cur]['freespin_mode'] ?? null;
        if (! is_array($boxes) || ! array_is_list($boxes) || ! isset($boxes[$pick]) || ($at = array_search($mode, $boxes, true)) === false) {
            return $ctx;
        }
        [$boxes[$at], $boxes[$pick]] = [$boxes[$pick], $boxes[$at]];
        $ctx[$cur]['freespin_pick'] = $boxes;

        return $ctx;
    }

    /**
     * `last_win` (cents): the player's last win shown. The recording's value
     * is a new win when it changed within the round, or on the paid spin when
     * that spin won; otherwise it is the recorded session's earlier win —
     * ours stands instead.
     *
     * @param  array<string, mixed>  $ctx  step `$i`, scaled
     */
    private function lastWin(GameContext $context, BooongoRound $round, int $i, array $ctx): int|float
    {
        $recorded = $round->steps[$i]['context']['last_win'] ?? null;
        $new = $i === 0
            ? (float) ($round->steps[0]['context']['spins']['round_win'] ?? 0) > 0
            : $recorded !== ($round->steps[$i - 1]['context']['last_win'] ?? null);
        if ($new && is_numeric($ctx['last_win'] ?? null)) {
            $context->statePut(['booongo_lw' => $ctx['last_win']]);
        }

        return $context->stateGet('booongo_lw', 0);
    }

    /**
     * Money fields × k at any depth; line bets set to the player's.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    public function scale(array $c, float $k, float $bpl): array
    {
        foreach ($c as $key => $v) {
            if ($key === 'bet_per_line' && is_numeric($v)) {
                $c[$key] = self::number($bpl);
            } elseif (is_string($key) && in_array($key, self::MONEY, true)) {
                $c[$key] = $this->scaleAll($v, $k);
            } elseif (is_array($v)) {
                $c[$key] = $this->scale($v, $k, $bpl);
            }
        }

        return $c;
    }

    private function scaleAll(mixed $v, float $k): mixed
    {
        if (is_array($v) && ! array_is_list($v)) {   // a money symbol as {position, reel, value}: its value only
            foreach ($v as $key => $x) {
                if ($key === 'value' || in_array($key, self::MONEY, true)) {
                    $v[$key] = $this->scaleAll($x, $k);
                }
            }

            return $v;
        }
        if (is_array($v)) {
            return array_map(fn ($x) => $this->scaleAll($x, $k), $v);
        }

        return is_int($v) || is_float($v) ? self::number($v * $k) : $v;
    }

    private static function number(float $n): int|float
    {
        $r = round($n, 2);

        return floor($r) == $r ? (int) $r : $r;
    }

    /** @return array<string, mixed> an idle context at the player's last line bet */
    private function idle(GameContext $context): array
    {
        $cfg = $this->config($context);
        $bets = $this->bets($context);
        $bpl = (float) $context->stateGet('booongo_bpl', 0);
        if (! in_array($bpl, array_map('floatval', $bets), true)) {
            $bpl = (float) $bets[0];
        }
        $ctx = $this->scale((array) ($cfg['context'] ?? []), $bpl / $this->recordBet($cfg), $bpl);
        $ctx['actions'] = ['spin'];
        $ctx['round_finished'] = true;
        $ctx['last_win'] = $context->stateGet('booongo_lw', 0);

        return $ctx;
    }

    /** @return array<string, mixed> the context a `sync` reports: the step last served (in play or not), or idle */
    private function shownContext(GameContext $context): array
    {
        $last = $context->stateGet('booongo_last');
        if (is_array($last) && ($round = BooongoRound::query()->find($last['round'])) !== null && isset($round->steps[$last['i']])) {
            return array_merge($this->scale((array) $round->steps[$last['i']]['context'], (float) $last['k'], (float) $last['bpl']),
                ['last_win' => $context->stateGet('booongo_lw', 0)]);
        }

        return $this->idle($context);
    }

    private function shownBalance(GameContext $context): float
    {
        $state = $context->stateGet('booongo');

        return is_array($state) && isset($state['shown']) ? (float) $state['shown'] : $this->balance($context);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function envelope(GameContext $context, float $balance, array $ctx): array
    {
        return ['session_id' => $context->session()->token, 'status' => ['code' => 'OK', 'message' => ''], 'modes' => ['auto', 'play'],
            'freebets' => [], 'awards' => [], 'freebet' => null, 'current_award' => null,
            'user' => $this->user($context, $balance), 'context' => $ctx];
    }

    /** @return array<string, mixed> */
    private function user(GameContext $context, float $balance): array
    {
        // the runner drops a balance whose version is older than the one it has
        $version = (int) $context->stateGet('booongo_bv', 0) + 1;
        $context->statePut(['booongo_bv' => $version]);

        return ['huid' => 'huid', 'balance' => (int) round($balance * 100), 'balance_version' => $version, 'nick' => '',
            'currency' => $context->currency->value, 'show_balance' => true];
    }

    /**
     * Line bets the player may pick: Booongo's own, kept to those whose
     * stakes the game's bet options include.
     *
     * @return list<int|float>
     */
    public function bets(GameContext $context): array
    {
        $cfg = $this->config($context);
        $all = array_values(array_map(fn ($b) => self::number((float) $b), (array) ($cfg['bets'] ?? [1])));
        $options = array_map(fn ($b) => (int) round((float) $b * 100), $context->betOptions());
        $ok = array_values(array_filter($all, fn ($b) => in_array(
            (int) round($b / $this->recordBet($cfg) * (int) $cfg['bet_cents']), $options, true)));

        return $ok ?: $all;
    }

    /** @param array<string, mixed> $cfg */
    private function recordBet(array $cfg): float
    {
        return max(1e-9, (float) ($cfg['record_bet'] ?? 1));
    }

    /** @return array<string, mixed> */
    private function error(string $code, string $type): array
    {
        return ['status' => ['code' => $code, 'type' => $type, 'message' => '']];
    }

    private function balance(GameContext $context): float
    {
        return round($context->balance(), 2);
    }

    /** @return array<string, mixed> the template's captured Booongo setup */
    private function config(GameContext $context): array
    {
        return (array) ($context->config()->bonusConfig()['booongo'] ?? []);
    }
}
