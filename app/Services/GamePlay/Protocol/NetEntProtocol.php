<?php

namespace App\Services\GamePlay\Protocol;

use App\Models\NetEntRound;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\NetEnt\NetEntLibrary;
use App\Services\GamePlay\NetEnt\NetEntQuirks;
use App\Services\GamePlay\NetEnt\NetEntReply;
use App\Services\GamePlay\NetEnt\Table\NetEntBaccarat;
use App\Services\GamePlay\NetEnt\Table\NetEntBlackjack;
use App\Services\GamePlay\NetEnt\Table\NetEntRoulette;
use App\Services\GamePlay\NetEnt\Table\NetEntVideoPoker;
use App\Services\GamePlay\SpinResult;
use Illuminate\Support\Facades\Log;

/**
 * NetEnt's classic HTML5 servlet protocol, server side: the client's
 * `GET …/servlet/CasinoGameServlet;jsession=<s>?action=<a>&…` calls, answered
 * with flat `key=value&…` bodies.
 *
 *   init / reloadbalance   the game's recorded init, with our balance, currency,
 *                          coin values and bet levels
 *   paytable               the recorded paytable (coin amounts — no money in it)
 *   spin                   stake taken, a round drawn from the outcome library
 *                          ({@see NetEntLibrary}) and its win paid; the round's
 *                          first reply served
 *   freespin, …            the round's next recorded reply for that action
 *
 * Rounds are recorded at bet level 1 and coin value rd cents (1, or the
 * game's smallest: `record_denomination`). Served to a player at level L /
 * coin value D: `*.coins` × L, `*.cents` × L·D/rd, the bet fields L and D,
 * and `credit` our balance moved by the recorded change × L·D/rd (the final
 * reply: our real balance).
 */
class NetEntProtocol
{
    public function __construct(private readonly NetEntLibrary $library) {}

    /** @param array<string, string> $query */
    public function dispatch(GameContext $context, array $query): string
    {
        $cfg = $this->config($context);
        if ($table = NetEntQuirks::table((string) ($cfg['game_id'] ?? ''))) {
            return match ($table[0]) {
                'roulette' => app(NetEntRoulette::class)->dispatch($context, $query, $cfg, $table[1]),
                'baccarat' => app(NetEntBaccarat::class)->dispatch($context, $query, $cfg),
                'videopoker' => app(NetEntVideoPoker::class)->dispatch($context, $query, $cfg),
                'blackjack' => app(NetEntBlackjack::class)->dispatch($context, $query, $cfg),
                default => throw new \RuntimeException("No engine for NetEnt table kind '{$table[0]}'"),
            };
        }
        $action = (string) ($query['action'] ?? '');
        // the action that opens a round: `spin`, or a game's own (Swipe & Roll: `pickwintype`)
        $start = NetEntQuirks::startAction((string) ($this->config($context)['game_id'] ?? ''));

        return match ($action) {
            'init' => $this->init($context),
            'reloadbalance' => $this->reloadBalance($context),
            'paytable' => $this->fixed($context, (string) ($this->config($context)['paytable'] ?? '')),
            $start => $this->spin($context, $query, $start),
            default => $this->step($context, $action),
        };
    }

    /**
     * NetEnt's InfoServlet: the roulette tables' hot / cold numbers and history
     * (`action=stat&operation=roulettestat[_extended]`, American
     * `americanroulettestat[_extended]`); anything else gets an empty body.
     *
     * @param  array<string, string>  $query
     */
    public function info(GameContext $context, array $query): string
    {
        $table = NetEntQuirks::table((string) ($this->config($context)['game_id'] ?? ''));
        if (! in_array($table[1] ?? null, ['french', 'american'], true) || ($query['action'] ?? '') !== 'stat') {   // Touch keeps none
            return '';
        }

        return app(NetEntRoulette::class)->stats($context, (string) ($query['operation'] ?? ''), $table[1] === 'american');
    }

    private function init(GameContext $context): string
    {
        $context->statePut(['netent' => null]);   // a round left unfinished was already settled
        $cfg = $this->config($context);
        $denoms = $this->denominations($context);
        $levels = array_map('intval', (array) ($cfg['levels'] ?? [1]));
        $standard = $denoms[0] ?? 1;

        return NetEntReply::patch((string) $cfg['init'], function (string $k, string $v) use ($context, $denoms, $levels, $standard) {
            return match ($k) {
                'credit' => (string) $this->cents($context->balance()),
                'playercurrencyiso', 'jackpotcurrencyiso' => $context->currency->value,
                'playercurrency', 'jackpotcurrency' => '',
                'denomination.all' => implode(',', $denoms),
                'denomination.standard', 'bet.denomination' => (string) $standard,
                'betlevel.all' => implode(',', $levels),
                'betlevel.standard', 'bet.betlevel' => (string) $levels[0],
                // the device lists sit in our mirror: <root>/gameclient_html/… from <root>/<game>/game/
                'staticsharedurl' => '../../gameclient_html/devicedetection/current',
                'restore' => 'false',
                'playforfun' => 'false',   // as recorded: NetEnt's fun mode changes the client's UI
                default => null,
            };
        });
    }

    private function reloadBalance(GameContext $context): string
    {
        $state = $context->stateGet('netent');

        return NetEntReply::build([
            'clientaction' => 'reloadbalance',
            'credit' => (string) $this->cents($context->balance() - (is_array($state) ? (float) $state['unshown'] : 0)),
            'playercurrencyiso' => $context->currency->value,
            'jackpotcurrencyiso' => $context->currency->value,
            'gameover' => is_array($state) ? 'false' : 'true',
            'nextaction' => is_array($state) ? (string) $state['nextaction'] : NetEntQuirks::startAction((string) ($this->config($context)['game_id'] ?? '')),
        ]);
    }

    /** @param array<string, string> $query */
    private function spin(GameContext $context, array $query, string $start = 'spin'): string
    {
        $cfg = $this->config($context);
        $level = (int) ($query['bet.betlevel'] ?? 0);
        $denom = (int) ($query['bet.denomination'] ?? 0);
        $coins = $this->betCoins($cfg);
        if (! in_array($level, array_map('intval', (array) ($cfg['levels'] ?? [])), true)
            || ! in_array($denom, $this->denominations($context), true) || $coins < 1) {
            return $this->error($start, 'Invalid bet');
        }
        // a bet option that prices the spin (Hotline's extra hotlines, Koi
        // Princess's bonus bet): the library holds the recorded one only
        foreach (NetEntQuirks::fixedParams((string) ($cfg['game_id'] ?? '')) as $k => $v) {
            if (isset($query[$k]) && $query[$k] !== $v) {
                return $this->error($start, 'This bet option is not available');
            }
        }
        $stake = $coins * $level * $denom / 100;
        if ($stake > $context->balance() + 1e-9) {
            return $this->error($start, 'Insufficient funds');
        }
        $before = $this->cents($context->balance());
        $context->placeBet($stake);
        $budget = min($context->bankAvailable(), $context->maxWin($stake));
        $round = $this->library->pick((string) $cfg['game_id'], $context->rtpTarget(), (int) floor($budget * 100 / ($level * $denom)));
        $win = $round->win_coins * $level * $denom / 100;
        $paid = $context->awardWin($win);
        $context->recordRound(new SpinResult($stake, $paid, [], [], 'bet', ['netent_round' => $round->id]),
            json_encode(['round' => $round->id, 'level' => $level, 'denom' => $denom]) ?: '');

        $state = ['round' => $round->id, 'next' => 1, 'level' => $level, 'denom' => $denom, 'before' => $before, 'unshown' => 0.0,
            'rd' => max(1, (int) ($cfg['record_denomination'] ?? 1))];

        return $this->serve($context, $round, 0, $state);
    }

    /** A later action of the round in play: its next recorded reply for that action. */
    private function step(GameContext $context, string $action): string
    {
        $state = $context->stateGet('netent');
        if (! is_array($state)) {
            return $this->error($action, 'No round in play');
        }
        $round = NetEntRound::query()->find($state['round']);
        $steps = $round?->steps ?? [];
        for ($i = (int) $state['next']; isset($steps[$i]) && $steps[$i]['sent'] !== $action; $i++);
        if ($round === null || ! isset($steps[$i])) {
            Log::info("NetEnt round {$state['round']}: client sent '{$action}', the recording has no such step left");

            return $this->error($action, 'Unexpected action');
        }

        return $this->serve($context, $round, $i, $state);
    }

    /** Serve recorded step `$i` rescaled to the player's bet; keeps or ends the round state. */
    private function serve(GameContext $context, NetEntRound $round, int $i, array $state): string
    {
        $steps = $round->steps;
        $last = ! isset($steps[$i + 1]);
        $L = (int) $state['level'];
        $D = (int) $state['denom'];
        $rd = (int) ($state['rd'] ?? 1);   // the coin value the round was recorded at: its cents are coins × rd
        $reply = NetEntReply::patch($steps[$i]['reply'], function (string $k, string $v) use ($context, $round, $state, $last, $L, $D, $rd) {
            if (str_starts_with($k, 'bl.')) {
                return null;
            }

            return match (true) {
                $k === 'credit' => (string) ($last ? $this->cents($context->balance()) : (int) $state['before'] + intdiv(((int) $v - $round->start_credit) * $L * $D, $rd)),
                str_ends_with($k, '.coins') && is_numeric($v) => (string) ((int) $v * $L),
                str_ends_with($k, '.cents') && is_numeric($v) => (string) intdiv((int) $v * $L * $D, $rd),
                in_array($k, ['bet.betlevel', 'freespins.betlevel'], true) => (string) $L,
                in_array($k, ['bet.denomination', 'freespins.denomination'], true) => (string) $D,
                in_array($k, ['playercurrencyiso', 'jackpotcurrencyiso'], true) => $context->currency->value,
                default => null,
            };
        });

        if ($last) {
            $context->statePut(['netent' => null]);
        } else {
            $f = NetEntRound::fields($steps[$i]['reply']);
            $shownCredit = (int) $state['before'] + intdiv(((int) ($f['credit'] ?? 0) - $round->start_credit) * $L * $D, $rd);
            $state['next'] = $i + 1;
            $state['nextaction'] = (string) ($f['nextaction'] ?? 'spin');
            $state['unshown'] = max(0, $this->cents($context->balance()) - $shownCredit) / 100;
            $context->statePut(['netent' => $state]);
        }

        return $reply;
    }

    /** A recorded reply with only the currency fields made ours. */
    private function fixed(GameContext $context, string $reply): string
    {
        return NetEntReply::patch($reply, fn (string $k) => in_array($k, ['playercurrencyiso', 'jackpotcurrencyiso'], true) ? $context->currency->value : null);
    }

    private function error(string $action, string $message): string
    {
        return NetEntReply::build(['clientaction' => $action, 'errorcode' => '1', 'errormessage' => $message]);
    }

    /**
     * Coin values (cents) the player may pick: NetEnt's own, kept to those
     * whose stakes (coins × level × value) the game's bet options include.
     *
     * @return list<int>
     */
    public function denominations(GameContext $context): array
    {
        $cfg = $this->config($context);
        $all = array_map('intval', (array) ($cfg['denominations'] ?? [1]));
        $options = array_map(fn ($b) => (int) round((float) $b * 100), $context->betOptions());
        if ($options === []) {
            return $all;
        }
        $coins = max(1, $this->betCoins($cfg));
        $levels = array_map('intval', (array) ($cfg['levels'] ?? [1]));
        $ok = array_values(array_filter($all, function (int $d) use ($options, $coins, $levels) {
            foreach ($levels as $l) {
                if (in_array($coins * $l * $d, $options, true)) {
                    return true;
                }
            }

            return false;
        }));

        return $ok ?: $all;
    }

    /**
     * Coins a spin costs at level 1: measured at import (`bet_coins`); games
     * imported before that are line games, one coin a line.
     *
     * @param  array<string, mixed>  $cfg
     */
    private function betCoins(array $cfg): int
    {
        return (int) ($cfg['bet_coins'] ?? $cfg['lines'] ?? 0);
    }

    /** @return array<string, mixed> the template's captured NetEnt setup */
    private function config(GameContext $context): array
    {
        return (array) ($context->config()->bonusConfig()['netent'] ?? []);
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
