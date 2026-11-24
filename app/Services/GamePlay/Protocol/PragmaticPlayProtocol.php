<?php

namespace App\Services\GamePlay\Protocol;

use App\Models\PragmaticRound;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\PragmaticPlay\PragmaticLibrary;
use App\Services\GamePlay\PragmaticPlay\PragmaticLiveClient;
use App\Services\GamePlay\SpinResult;
use Illuminate\Support\Facades\Log;

/**
 * Pragmatic Play's game service ("gs2c"), our side: the client's
 * `POST <contextPath>gameService` with a form body (`action=doInit|doSpin|…`),
 * answered with a flat `key=value&…` body.
 *
 *   doInit     the recorded setup: our balance, our coin ladder (`sc`, `defc`),
 *              the player's last coin; no buy-feature offer
 *   doSpin     no round in play: stake (coin × lines) taken, a round drawn from
 *              the outcome library ({@see PragmaticLibrary}), its whole win paid,
 *              its first reply served. Inside a round: its next recorded reply,
 *              whatever the client calls it (doSpin for a free spin / tumble,
 *              doCollect, doBonus, doCollectBonus, doFSOption, …), no stake
 *   anything else with no round in play (a stray doCollect): an idle reply
 *
 * Rounds are recorded at the smallest coin c; served at coin C, every money
 * field is × C/c ({@see self::MONEY}) and `balance` is our balance moved by the
 * recorded change × C/c (the round's last reply: our real balance).
 */
class PragmaticPlayProtocol
{
    /**
     * Money fields: a key (or a key pattern, `/…/`) and which of its parts are
     * money — `*` every number in it (lists split on `,` `;` `~`), or the
     * index of the `~`-separated part in each `;`-separated item.
     *
     * @var array<string, string|int>
     */
    public const array MONEY = [
        'tw' => '*', 'w' => '*', 'fswin' => '*', 'fsres' => '*', 'fswin_total' => '*', 'fsres_total' => '*',
        'tmb_win' => '*', 'tmb_res' => '*', 'rs_win' => '*', 'rs_iw' => '*', 'mo_tw' => '*', 'pw' => '*', 'rw' => '*',
        'apwa' => '*', 'coef' => '*', 'wlc_v' => 1, 'psym' => 1, '/^l\d+$/' => 1,
    ];

    /**
     * What doInit drops: the buy-feature offers and the ante bet level (bought
     * rounds and ante rounds aren't recorded) — without them the client shows
     * neither button.
     */
    private const array NO_BUY = ['purInit', 'purInit_e', 'fspps', 'fspps_mask', 'total_bet_max', 'pur_min_coin', 'bls', 'bl', 'bl_paytable'];

    public function __construct(private readonly PragmaticLibrary $library) {}

    /** @param array<string, string> $params the request's form fields */
    public function dispatch(GameContext $context, array $params): string
    {
        $action = (string) ($params['action'] ?? '');
        $reply = match ($action) {
            'doInit' => $this->init($context),
            '' => ['balance' => $this->money($this->balance($context))],
            default => $this->play($context, $action, $params),
        };

        return PragmaticLiveClient::encode($this->envelope($reply, $params));
    }

    /** @return array<string, string> */
    private function init(GameContext $context): array
    {
        $state = $context->stateGet('pragmatic');
        if (is_array($state) && ($round = PragmaticRound::query()->find($state['round'])) !== null && isset($round->steps[$state['next']])) {
            // a round left in play (a reload): show the step last served, its remaining steps follow
            $prev = $this->scaled($round, (int) $state['next'] - 1, $state);
            $prev['balance'] = $this->money((float) $state['shown']);

            return array_merge($this->setup($context), array_diff_key($prev, ['index' => 1, 'counter' => 1]));
        }
        $context->statePut(['pragmatic' => null]);

        return $this->setup($context);
    }

    /** @return array<string, string> the recorded doInit at our coins and balance */
    private function setup(GameContext $context): array
    {
        $cfg = $this->config($context);
        $init = PragmaticLiveClient::parse((string) ($cfg['init'] ?? ''));
        $init = array_diff_key($init, array_flip(self::NO_BUY));
        $coins = $this->coins($context);
        $coin = (float) $context->stateGet('pragmatic_coin', 0);
        if (! in_array(round($coin * 100), array_map(fn ($c) => round($c * 100), $coins), true)) {
            $coin = $coins[0];
        }
        $balance = $this->money($this->balance($context));

        return array_merge($init, [
            'balance' => $balance, 'balance_cash' => $balance, 'balance_bonus' => '0.00', 'na' => 's',
            'sc' => implode(',', array_map(fn ($c) => PragmaticLiveClient::num($c), $coins)),
            'defc' => PragmaticLiveClient::num($coins[0]), 'c' => PragmaticLiveClient::num($coin), 'l' => (string) $cfg['lines'],
        ]);
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, string>
     */
    private function play(GameContext $context, string $action, array $params): array
    {
        $state = $context->stateGet('pragmatic');
        if (is_array($state)) {   // the round in play: its next step, whatever the client calls it
            $round = PragmaticRound::query()->find($state['round']);
            if ($round !== null && isset($round->steps[$state['next']])) {
                $i = (int) $state['next'];
                $expected = (string) $round->steps[$i]['a'];
                if ($expected !== $action) {
                    Log::info("Pragmatic round {$round->id}: client sent {$action}, recording has {$expected}");
                }
                // a choice (a box picked, a free-spin option) other than the recorded one
                $recorded = $round->steps[$i]['p']['ind'] ?? null;
                if (isset($params['ind']) && $recorded !== null && (int) $params['ind'] !== (int) $recorded && $action === $expected) {
                    $asked = PragmaticLiveClient::parse((string) ($round->steps[$i - 1]['r'] ?? ''));
                    if (isset($asked['status']) && $action === 'doBonus') {
                        $state['perm'] = $this->repick($state['perm'] ?? null, count(explode(',', $asked['status'])), (int) $recorded, (int) $params['ind']);
                    } elseif (($spliced = $this->splice($context, $round, $i, $action, (int) $params['ind'], $state)) !== null) {
                        [$round, $i, $state] = $spliced;
                    } else {
                        Log::info("Pragmatic round {$round->id}: no recorded {$action} ind={$params['ind']} to switch to");
                    }
                }

                return $this->serve($context, $round, $i, $state);
            }
            Log::info("Pragmatic round {$state['round']}: a {$action} past the recording's end");
            $context->statePut(['pragmatic' => null]);
        }
        if ($action !== 'doSpin') {   // a collect with nothing to collect: idle
            $balance = $this->money($this->balance($context));

            return ['balance' => $balance, 'balance_cash' => $balance, 'balance_bonus' => '0.00', 'na' => 's'];
        }
        if (isset($params['pur']) || (int) ($params['bl'] ?? 0) !== 0) {
            return $this->frozen('This option is not available');
        }

        $cfg = $this->config($context);
        $coin = (float) ($params['c'] ?? 0);
        $valid = in_array((int) round($coin * 100), array_map(fn ($c) => (int) round($c * 100), $this->coins($context)), true);
        if (! $valid || (int) ($params['l'] ?? $cfg['lines']) !== (int) $cfg['lines']) {
            return $this->frozen('Invalid bet');
        }
        $k = $coin / $this->recordCoin($cfg);
        $stake = round((int) $cfg['bet_cents'] * $k / 100, 2);
        if ($stake > $context->balance() + 1e-9) {
            $balance = $this->money($this->balance($context));

            return ['nomoney' => '1', 'error_type' => 'i', 'balance' => $balance, 'balance_cash' => $balance, 'balance_bonus' => '0.00'];
        }
        $before = $this->balance($context);
        $context->placeBet($stake);
        $budget = min($context->bankAvailable(), $context->maxWin($stake));
        $round = $this->library->pick((string) $cfg['game_key'], $context->rtpTarget(), (int) floor($budget * 100 / $k));
        $win = round($round->win_cents * $k / 100, 2);
        $paid = $context->awardWin($win);
        $context->recordRound(new SpinResult($stake, $paid, [], [], 'bet', ['pragmatic_round' => $round->id]),
            json_encode(['round' => $round->id, 'coin' => $coin]) ?: '');
        $context->statePut(['pragmatic_coin' => $coin]);

        return $this->serve($context, $round, 0, ['round' => $round->id, 'k' => $k, 'coin' => $coin, 'before' => $before]);
    }

    /**
     * Recorded step `$i` rescaled; keeps or ends the round state.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, string>
     */
    private function serve(GameContext $context, PragmaticRound $round, int $i, array $state): array
    {
        $reply = $this->scaled($round, $i, $state);
        $last = ! isset($round->steps[$i + 1]);
        $recorded = (float) (PragmaticLiveClient::parse((string) $round->steps[$i]['r'])['balance'] ?? 0);
        $balance = $last ? $this->balance($context)
            : round((float) $state['before'] + ($recorded * 100 - $round->start_credit) * (float) $state['k'] / 100, 2);
        $context->statePut(['pragmatic' => $last ? null : array_merge($state, ['next' => $i + 1, 'shown' => $balance])]);
        $reply['balance'] = $reply['balance_cash'] = $this->money($balance);
        $reply['balance_bonus'] = '0.00';

        return $reply;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, string>
     */
    private function scaled(PragmaticRound $round, int $i, array $state): array
    {
        $reply = $this->scale(PragmaticLiveClient::parse((string) $round->steps[$i]['r']), (float) $state['k']);
        if (isset($state['perm'])) {
            $reply = $this->permute($reply, (array) $state['perm']);
        }
        if (isset($reply['c'])) {
            $reply['c'] = PragmaticLiveClient::num((float) $state['coin']);
        }

        return $reply;
    }

    /**
     * A pick bonus (boxes: `status` = one entry per box, 0 = closed; `wins`,
     * `wins_mask` alike): the player opened box `$picked`, the recording box
     * `$recorded` — from now on every box list is shown with those two boxes'
     * contents swapped, so the box they chose holds the recorded prize.
     * `$perm`: recorded box → box shown.
     *
     * @param  list<int>|null  $perm
     * @return list<int>
     */
    private function repick(?array $perm, int $boxes, int $recorded, int $picked): array
    {
        $perm = $perm !== null && count($perm) === $boxes ? array_values($perm) : range(0, $boxes - 1);
        $other = array_search($picked, $perm, true);
        if ($other === false || ! isset($perm[$recorded])) {
            return $perm;
        }
        [$perm[$recorded], $perm[$other]] = [$perm[$other], $perm[$recorded]];

        return $perm;
    }

    /**
     * @param  array<string, string>  $reply
     * @param  list<int>  $perm
     * @return array<string, string>
     */
    private function permute(array $reply, array $perm): array
    {
        foreach ($reply as $key => $v) {
            if (! preg_match('/^(status|wins|wins_mask)$/', (string) $key)) {
                continue;
            }
            $items = explode(',', $v);
            if (count($items) !== count($perm)) {
                continue;
            }
            $shown = $items;
            foreach ($items as $from => $item) {
                $shown[$perm[$from]] = $item;
            }
            $reply[$key] = implode(',', $shown);
        }

        return $reply;
    }

    /**
     * A choice that changes what follows (a free-spin option: more spins or a
     * bigger multiplier): carry on in another recorded round of this game where
     * that choice was made — from its own choice step on. The round's win
     * becomes that round's: the difference is paid, or taken back.
     *
     * @param  array<string, mixed>  $state
     * @return array{0: PragmaticRound, 1: int, 2: array<string, mixed>}|null
     */
    private function splice(GameContext $context, PragmaticRound $round, int $i, string $action, int $ind, array $state): ?array
    {
        $k = (float) $state['k'];
        // the new total may use the bank as it stands plus what this round already paid, within the max-win cap
        $stake = (int) $this->config($context)['bet_cents'] * $k / 100;
        $cap = min($context->bankAvailable() + $round->win_cents * $k / 100, $context->maxWin($stake));
        $room = (int) floor($cap * 100 / $k);
        $candidates = PragmaticRound::query()->where('game_key', $round->game_key)->where('win_cents', '<=', $room)
            ->whereRaw('JSON_CONTAINS(steps, ?)', [json_encode([['a' => $action, 'p' => ['ind' => $ind]]])])
            ->pluck('id');
        if ($candidates->isEmpty()) {
            return null;
        }
        $other = PragmaticRound::query()->find($candidates->random());
        foreach ($other->steps as $j => $step) {
            if ($step['a'] === $action && (int) ($step['p']['ind'] ?? -1) === $ind) {
                $delta = round(($other->win_cents - $round->win_cents) * $k / 100, 2);
                if ($delta > 0) {
                    $context->awardWin($delta);
                } elseif ($delta < 0) {
                    $context->clawback(-$delta);
                }
                $context->recordRound(new SpinResult(max(0.0, -$delta), max(0.0, $delta), [], [], 'bonus',
                    ['pragmatic_round' => $other->id, 'switched_from' => $round->id, $action => $ind]),
                    json_encode(['round' => $other->id, 'from' => $round->id, 'ind' => $ind]) ?: '');
                unset($state['perm']);

                return [$other, $j, array_merge($state, ['round' => $other->id])];
            }
        }

        return null;
    }

    /**
     * Money fields × k.
     *
     * @param  array<string, string>  $reply
     * @return array<string, string>
     */
    public function scale(array $reply, float $k): array
    {
        if (abs($k - 1) < 1e-9) {
            return $reply;
        }
        foreach ($reply as $key => $v) {
            $how = self::MONEY[$key] ?? null;
            if ($how === null) {
                foreach (self::MONEY as $pattern => $h) {
                    if ($pattern[0] === '/' && preg_match($pattern, (string) $key)) {
                        $how = $h;
                        break;
                    }
                }
            }
            if ($how === '*') {
                $reply[$key] = (string) preg_replace_callback('/-?\d+(?:\.\d+)?/', fn ($m) => $this->times($m[0], $k), $v);
            } elseif (is_int($how)) {
                $reply[$key] = implode(';', array_map(function (string $item) use ($how, $k) {
                    $parts = explode('~', $item);
                    if (isset($parts[$how]) && is_numeric($parts[$how])) {
                        $parts[$how] = $this->times($parts[$how], $k);
                    }

                    return implode('~', $parts);
                }, explode(';', $v)));
            }
        }

        return $reply;
    }

    private function times(string $n, float $k): string
    {
        return PragmaticLiveClient::num(round((float) $n * $k, 2));
    }

    /**
     * Housekeeping every reply carries: the request's index, its counter + 1,
     * the server time.
     *
     * @param  array<string, string>  $reply
     * @param  array<string, string>  $params
     * @return array<string, string>
     */
    private function envelope(array $reply, array $params): array
    {
        if (isset($reply['frozen']) || isset($reply['nomoney'])) {
            return $reply + ['stime' => (string) (int) floor(microtime(true) * 1000)];
        }
        $reply['index'] = (string) (int) ($params['index'] ?? 1);
        $reply['counter'] = (string) ((int) ($params['counter'] ?? 0) + 1);
        $reply['stime'] = (string) (int) floor(microtime(true) * 1000);

        return $reply;
    }

    /** @return array<string, string> */
    private function frozen(string $message): array
    {
        return ['frozen' => $message, 'msg_code' => '11', 'ext_code' => 'SystemError'];
    }

    /**
     * Coins the player may pick: the game's coin ladder, kept to those whose
     * stakes (coin × lines) the game's bet options include.
     *
     * @return list<float>
     */
    public function coins(GameContext $context): array
    {
        $cfg = $this->config($context);
        $lines = max(1, (int) $cfg['lines']);
        $options = array_map(fn ($b) => (int) round((float) $b * 100), $context->betOptions());
        $all = array_map('floatval', (array) ($cfg['coins'] ?? [$this->recordCoin($cfg)]));
        $ok = array_values(array_filter($all, fn ($c) => in_array((int) round($c * $lines * 100), $options, true)));

        return $ok ?: $all;
    }

    /** @param array<string, mixed> $cfg */
    private function recordCoin(array $cfg): float
    {
        return max(1e-9, (float) ($cfg['record_coin'] ?? 0.01));
    }

    private function money(float $n): string
    {
        return number_format(round($n, 2), 2, '.', '');
    }

    private function balance(GameContext $context): float
    {
        return round($context->balance(), 2);
    }

    /** @return array<string, mixed> the template's captured Pragmatic setup */
    private function config(GameContext $context): array
    {
        return (array) ($context->config()->bonusConfig()['pragmatic'] ?? []);
    }
}
