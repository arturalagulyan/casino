<?php

namespace App\Services\GamePlay\Protocol;

use App\Models\WazdanRound;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;
use App\Services\GamePlay\Wazdan\WazdanLibrary;
use Illuminate\Support\Facades\Log;

/**
 * Wazdan's RGS, our side: the client's requests (`{service, act, arg, id}`),
 * as our page's WebSocket shim tunnels them to `/wazdan/{session}`. Money is
 * in cents (of the player's currency).
 *
 *   setup / ping (service wsp)          transport bookkeeping
 *   <cmd> (the login), LOAD_CLDATA_WEB, LIST_PARAMS, GAME_PARAMS, GAME, STATE
 *                                       the recorded setup with our balance,
 *                                       currency and stakes
 *   START        no round in play: stake taken, a round drawn from the outcome
 *                library ({@see WazdanLibrary}), its whole win paid, its first
 *                reply served; a round in play (free games / respins left): its
 *                next recorded reply, no stake
 *   PRIZE_TO_BANK                       the round's win shown on the balance
 *   GAMBLING_*   red / black (or suit) on the last win — played by us
 *                ({@see SlotEngine::gamble}), not recorded
 *
 * Rounds are recorded at the game's smallest stake s; served at stake S, every
 * money field is × S/s. Wazdan holds a round's win in `prize` until the client
 * banks it, so `credit` reads our balance before the win until PRIZE_TO_BANK.
 */
class WazdanProtocol
{
    /** Money fields of a START reply (cents). Multipliers (bonus_mul, sym_mul, init_mul, …) and counts (coins) are not. */
    private const array MONEY = ['prize', 'bonus_stake', 'bonusbet', 'wfs_stake', 'cprize', 'jprize', 'bank', 'held_stake'];

    public function __construct(private readonly WazdanLibrary $library) {}

    /**
     * @param  array<string, mixed>  $msg  the client's request
     * @return array<string, mixed> the reply frame
     */
    public function dispatch(GameContext $context, array $msg): array
    {
        $act = (string) ($msg['act'] ?? '');
        $arg = (array) ($msg['arg'] ?? []);
        $cfg = $this->config($context);
        $init = (array) ($cfg['init'] ?? []);

        if (($msg['service'] ?? 'game') === 'wsp') {
            return match ($act) {
                'setup' => $this->ok($msg, ['pv' => 8, 'ver' => 5]),
                default => $this->ok($msg),   // ping, close
            };
        }

        $res = match (true) {
            $act === ($cfg['cmd'] ?? '') => (array) ($init['login'] ?? []),
            $act === 'LOAD_CLDATA_WEB' => array_merge((array) ($init['LOAD_CLDATA_WEB'] ?? []), [
                'currency' => $context->currency->value, 'currency_display_as' => $context->currency->symbol(),
            ]),
            $act === 'LIST_PARAMS' => array_merge((array) ($init['LIST_PARAMS'] ?? []), ['demoMode' => 'no']),
            // no feature buy ("shop"); volatility fixed at the level the rounds were recorded at
            $act === 'GAME_PARAMS' => array_merge((array) ($init['GAME_PARAMS'] ?? []), ['shop enabled' => 0, 'difficulty level mode' => 0, 'force default difficulty' => 1]),
            $act === 'GAME' => $this->game($context, (array) ($init['GAME'] ?? [])),
            $act === 'STATE' => $this->state($context, (array) ($init['STATE'] ?? [])),
            $act === 'GET_COUNTERS' => $this->counters($context),
            $act === 'START' => $this->start($context, $arg),
            $act === 'PRIZE_TO_BANK' => $this->bank($context),
            str_starts_with($act, 'GAMBLING_') => $this->gamble($context, $act, $arg),
            default => null,   // READY_TO_PLAY, GAME_END, GET_MESSAGES, …: a bare "ok"
        };
        if (is_array($res) && isset($res['__error'])) {
            return ['a' => self::tag($act), 'id' => $msg['id'] ?? 0, 's' => 'error', 'err' => $res['__error'], 'msg' => $res['__error']];
        }

        return $this->ok($msg, $res);
    }

    /** @return array<string, mixed> */
    private function game(GameContext $context, array $game): array
    {
        $context->statePut(['wazdan' => null, 'wazdan_gamble' => null]);   // a round left unfinished was already paid
        $stakes = $this->stakes($context);

        return array_merge($game, $this->counters($context), [
            'stake_list' => implode(',', $stakes), 'stake' => $stakes[0], 'minstake' => $stakes[0],
            'maxstake' => max($stakes), 'max_shop_stake' => max($stakes), 'free_rounds' => '', 'prize' => 0,
            'shop_enabled' => 0,   // the feature buy ("shop") — never offered
            'difficulty_level_mode' => 0,   // no volatility choice: the library is one level's
        ]);
    }

    /** @return array<string, mixed> the idle state: no round in play, no feature buy ("shop") offered */
    private function state(GameContext $context, array $state): array
    {
        unset($state['bonus_cost']);
        if (array_key_exists('shop_enabled', $state)) {
            $state['shop_enabled'] = 0;
        }
        $context->statePut(['wazdan' => null]);

        return $state;
    }

    /** @return array<string, mixed> */
    private function start(GameContext $context, array $arg): array
    {
        $state = $context->stateGet('wazdan');
        if (is_array($state)) {   // free games / respins of the round in play
            $round = WazdanRound::query()->find($state['round']);
            if ($round !== null && isset($round->steps[$state['next']])) {
                return $this->serve($context, $round, (int) $state['next'], $state);
            }
            Log::info("Wazdan round {$state['round']}: a START past the recording's end");
            $context->statePut(['wazdan' => null]);
        }

        $cfg = $this->config($context);
        $cents = isset($arg['stake_per_line'], $arg['lines']) ? (int) $arg['stake_per_line'] * (int) $arg['lines'] : (int) ($arg['stake'] ?? 0);
        if (! in_array($cents, $this->stakes($context), true)) {
            return ['__error' => 'invalid_stake'];
        }
        $stake = $cents / 100;
        if ($stake > $context->balance() + 1e-9) {
            return ['__error' => 'insufficient_funds'];
        }
        $before = $context->balance();
        $context->placeBet($stake);
        $f = $cents / max(1, (int) $cfg['record_stake']);
        $budget = min($context->bankAvailable(), $context->maxWin($stake));
        $round = $this->library->pick((string) $cfg['game_key'], $context->rtpTarget(), (int) floor($budget * 100 / $f));
        $win = round($round->win_cents * $f) / 100;
        $paid = $context->awardWin($win);
        $context->recordRound(new SpinResult($stake, $paid, [], [], 'bet', ['wazdan_round' => $round->id]),
            json_encode(['round' => $round->id, 'stake' => $stake]) ?: '');
        $context->statePut(['wazdan_gamble' => null]);

        return $this->serve($context, $round, 0, ['round' => $round->id, 'f' => $f, 'shown' => (int) round(($before - $stake) * 100)]);
    }

    /**
     * Serve recorded step `$i` rescaled; keeps or ends the round state.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function serve(GameContext $context, WazdanRound $round, int $i, array $state): array
    {
        $f = (float) $state['f'];
        $res = $round->steps[$i];
        foreach (self::MONEY as $k) {
            if (isset($res[$k]) && is_numeric($res[$k])) {
                $res[$k] = is_string($res[$k]) ? (string) (int) round((float) $res[$k] * $f) : (int) round((float) $res[$k] * $f);
            }
        }
        // the win sits in `prize` until banked: the balance shown is the one after the stake
        $res['credit'] = $res['cashInCredit'] = (int) $state['shown'];

        if (isset($round->steps[$i + 1])) {
            $context->statePut(['wazdan' => array_merge($state, ['next' => $i + 1])]);
        } else {
            $context->statePut(['wazdan' => null]);
            $prize = (int) ($res['prize'] ?? 0);
            $context->statePut(['wazdan_gamble' => $prize > 0 ? ['amount' => $prize, 'shown' => (int) $state['shown'], 'res' => $res] : null]);
        }

        return $res;
    }

    /** @return array<string, mixed> */
    private function bank(GameContext $context): array
    {
        $context->statePut(['wazdan_gamble' => null]);

        return $this->counters($context) + ['flags' => '0'];
    }

    /**
     * The gamble on the round's win (already paid to the balance; `credit`
     * reads the balance less the win at stake):
     *
     *   GAMBLING_ENTER {type}          → []
     *   GAMBLING_START {card_color}    → {prize: double or 0, win: 1|0, flags: 4|0, credit, …}
     *   GAMBLING_HALF                  half the win banked, the rest stays at stake
     *   GAMBLING_LEAVE                 → {flags: "0"}, then PRIZE_TO_BANK
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    private function gamble(GameContext $context, string $act, array $arg): ?array
    {
        $g = $context->stateGet('wazdan_gamble');
        if (! is_array($g) || (int) $g['amount'] <= 0) {
            return $act === 'GAMBLING_LEAVE' ? ['flags' => '0'] : ['__error' => 'invalid_state'];
        }
        $amount = (int) $g['amount'];
        $shown = fn (int $atStake) => (int) round($context->balance() * 100) - $atStake;

        switch ($act) {
            case 'GAMBLING_ENTER':
                return [];
            case 'GAMBLING_LEAVE':
                return ['flags' => '0'];
            case 'GAMBLING_HALF':
                $keep = intdiv($amount, 2);
                $context->statePut(['wazdan_gamble' => ['amount' => $keep] + $g]);

                return ['bank' => '0', 'cprize' => '0', 'credit' => $shown($keep), 'flags' => 4, 'prize' => $keep, 'win' => 1, 'x' => '0'];
            case 'GAMBLING_START':
                if ($amount / 100 > $context->balance() + 1e-9) {
                    return ['__error' => 'insufficient_funds'];
                }
                $stake = $amount / 100;
                $won = app(SlotEngine::class)->gamble($context, $stake)['won'];
                if ($won) {
                    $context->awardWin($stake);
                } else {
                    $context->clawback($stake);
                }
                $context->recordRound(new SpinResult($won ? 0.0 : $stake, $won ? $stake * 2 : 0.0, [], [], 'gamble'),
                    json_encode(['gamble' => $won ? 'won' : 'lost', 'amount' => $stake, 'card_color' => $arg['card_color'] ?? null]) ?: '');
                $prize = $won ? $amount * 2 : 0;
                $context->statePut(['wazdan_gamble' => $won ? ['amount' => $prize] + $g : null]);

                return ['bank' => '0', 'cprize' => '0', 'credit' => $shown($prize), 'flags' => $won ? 4 : 0, 'prize' => $prize, 'win' => $won ? 1 : 0, 'x' => '0'];
            default:
                return null;
        }
    }

    /** @return array{cashInCredit: int, credit: int} our balance, cents */
    private function counters(GameContext $context): array
    {
        $c = (int) round($context->balance() * 100);

        return ['cashInCredit' => $c, 'credit' => $c];
    }

    /**
     * The stakes a player may pick (cents): the game's bet options, else Wazdan's own.
     *
     * @return list<int>
     */
    public function stakes(GameContext $context): array
    {
        $options = array_values(array_filter(array_map(fn ($v) => (int) round((float) $v * 100), $context->betOptions()), fn ($v) => $v > 0));
        if ($options === []) {
            $options = array_map('intval', (array) ($this->config($context)['stakes'] ?? [10]));
        }
        sort($options);

        return array_values(array_unique($options));
    }

    /**
     * @param  array<string, mixed>  $msg
     * @return array<string, mixed>
     */
    private function ok(array $msg, ?array $res = null): array
    {
        $reply = ['a' => self::tag((string) ($msg['act'] ?? '')), 'id' => $msg['id'] ?? 0];
        if ($res !== null) {
            $reply['res'] = $res;
        }

        return $reply + ['s' => 'ok'];
    }

    /** A reply's `a` tag — the client doesn't check it; stable per act, like Wazdan's. */
    private static function tag(string $act): string
    {
        return substr(md5('wz:'.$act), 0, 8);
    }

    /** @return array<string, mixed> the template's captured Wazdan setup */
    private function config(GameContext $context): array
    {
        return (array) ($context->config()->bonusConfig()['wazdan'] ?? []);
    }
}
