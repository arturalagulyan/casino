<?php

namespace App\Services\GamePlay\Protocol;

use App\Models\EgtRound;
use App\Services\GamePlay\Egt\EgtLibrary;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;
use Illuminate\Support\Facades\Log;

/**
 * EGT's game server, our side: the client's socket frames (JSON, plus a bare
 * `ping`), as our page's WebSocket shim tunnels them to `/egt/{session}/ws`.
 *
 *   login / settings / subscribe   the recorded replies with our balance,
 *                                  currency and bets
 *   bet {gameCommand: bet}         no round in play: the bet taken, a round
 *                                  drawn from the outcome library
 *                                  ({@see EgtLibrary}), its whole win paid,
 *                                  its first reply served; a round in play
 *                                  (free spins): its next recorded reply
 *   bet {gameCommand: gamble}      red / black on the win — played by us
 *                                  ({@see SlotEngine::gamble}), not recorded
 *   bet {gameCommand: collect}     the win (already paid) shown in the balance
 *   ping, history, …               bookkeeping
 *
 * Money is in cents. Rounds are recorded at the game's smallest bet b; served
 * at bet B every money field is × B/b, and the balance a reply shows is ours
 * moved by the recorded change × B/b — like EGT's, it leaves a win out until
 * it is collected (our ledger pays it at the spin).
 */
class EgtProtocol
{
    /** Money fields, at any depth of a reply's `complex`. */
    private const array MONEY = ['winAmount', 'winPerCount', 'freeSpinsExpandWinAmount', 'totalWinAmount', 'totalWin',
        'pendingWinAmount', 'mysteryAmount'];

    /** `complex` keys that hold no money even if their names suggest it. */
    private const array NOT_MONEY = ['gameCommand', 'reels', 'cells', 'card', 'line', 'freespins', 'gambles', 'scatterName',
        'multiplier', 'multipliers', 'expand', 'specialExpand'];

    public function __construct(private readonly EgtLibrary $library) {}

    /** @return list<string> frames to hand back, in order */
    public function dispatch(GameContext $context, string $frame): array
    {
        $frame = trim($frame);
        if ($frame === 'ping' || $frame === '') {
            return [$this->encode($this->envelope($context, ['command' => 'ping', 'messageId' => bin2hex(random_bytes(16))], [
                'qName' => 'app.services.messages.response.BaseResponse',
            ]))];
        }
        $request = json_decode($frame, true);
        if (! is_array($request)) {
            return [];
        }
        $command = (string) ($request['command'] ?? '');
        $sub = strtolower((string) ($request['bet']['gameCommand'] ?? ''));
        $reply = match (true) {
            $command === 'login' => $this->login($context, $request),
            $command === 'settings' => $this->settings($context, $request),
            $command === 'subscribe' => $this->subscribe($context, $request),
            $command === 'bet' && $sub === 'gamble' => $this->gamble($context, $request),
            $command === 'bet' && $sub === 'collect' => $this->collect($context, $request),
            $command === 'bet' => $this->bet($context, $request),
            $command === 'balance' => $this->envelope($context, $request, ['balance' => $this->cents($context->balance())]),
            default => $this->envelope($context, $request, ['complex' => (object) []]),   // history, statistics, event, …
        };

        return [$this->encode($reply)];
    }

    // ---- session bootstrap ------------------------------------------

    /** @return array<string, mixed> */
    private function login(GameContext $context, array $request): array
    {
        $context->statePut(['egt' => null, 'egt_gamble' => null]);   // a round left unfinished was already paid
        $login = (array) ($this->config($context)['login'] ?? []);
        unset($login['playerIdToken'], $login['messageId'], $login['sessionKey'], $login['eventTimestamp']);

        $name = (string) ($context->user->username ?? ('player'.$context->user->id));
        $b64 = fn (array $v) => rtrim(strtr(base64_encode((string) json_encode($v)), '+/', '-_'), '=');

        return $this->envelope($context, $request, array_merge($login, [
            'playerName' => $name,
            // the portal decodes it (jwt-decode) for the player's id and screen name; unsigned, like EGT's demo
            'playerIdToken' => $b64(['alg' => 'none', 'typ' => 'JWT']).'.'.$b64(['playerUid' => md5('player'.$context->user->id),
                'portalUid' => 'egt', 'screenName' => $name]).'.',
            'balance' => $this->cents($context->balance()),
            'currency' => $context->currency->value,
            'showRtp' => false,
            'multigame' => false,
            'qName' => 'app.services.messages.response.LoginResponse',
        ]));
    }

    /** @return array<string, mixed> */
    private function settings(GameContext $context, array $request): array
    {
        $settings = (array) ($this->config($context)['settings'] ?? []);
        $complex = (array) ($settings['complex'] ?? []);
        $bets = $this->bets($context);
        $complex['bets'] = $bets;
        $complex['defaultBetIndex'] = 0;
        $complex['jackpot'] = false;
        $complex['jackpotMinBet'] = $bets[0];
        $complex['jackpotMaxBet'] = end($bets);

        return $this->envelope($context, $request, ['complex' => $complex, 'gameNumber' => -1,
            'qName' => 'app.services.messages.response.GameResponse']);
    }

    /** @return array<string, mixed> */
    private function subscribe(GameContext $context, array $request): array
    {
        $sub = (array) ($this->config($context)['subscribe'] ?? []);
        $current = (array) ($sub['complex']['currentState'] ?? []);
        $current = array_merge($current, [
            'gamblesUsed' => 0, 'freespinsUsed' => 0, 'state' => 'idle', 'winAmount' => 0, 'freespins' => 0,
            'bet' => $this->bets($context)[0], 'denomination' => 1, 'jackpot' => false,
            'previousGambles' => (array) ($context->stateGet('egt_cards') ?? $this->cards()),
        ]);

        return $this->envelope($context, $request, ['complex' => ['currentState' => $current], 'gameNumber' => '-1',
            'qName' => 'app.services.messages.response.GameEventResponse']);
    }

    // ---- play ---------------------------------------------------------

    /** @return array<string, mixed> */
    private function bet(GameContext $context, array $request): array
    {
        $state = $context->stateGet('egt');
        if (is_array($state)) {   // free spins of the round in play
            $round = EgtRound::query()->find($state['round']);
            if ($round !== null && isset($round->steps[$state['next']])) {
                return $this->serve($context, $request, $round, (int) $state['next'], $state);
            }
            Log::info("EGT round {$state['round']}: a step past the recording's end");
            $context->statePut(['egt' => null]);
        }

        $cfg = $this->config($context);
        $bet = (int) ($request['bet']['bet'] ?? 0) * max(1, (int) ($request['bet']['betMultiplier'] ?? 1));
        if (! in_array($bet, $this->bets($context), true)) {
            return $this->error($context, $request, 'Invalid bet');
        }
        $stake = $bet / 100;
        if ($stake > $context->balance() + 1e-9) {
            return $this->error($context, $request, 'Insufficient funds');
        }
        $before = $this->cents($context->balance());
        $context->placeBet($stake);
        $f = $bet / max(1, (int) $cfg['record_bet']);
        $budget = min($context->bankAvailable(), $context->maxWin($stake));
        $round = $this->library->pick((int) $cfg['gin'], $context->rtpTarget(), (int) floor($budget * 100 / $f));
        $win = round($round->win_cents * $f / 100, 2);
        $paid = $context->awardWin($win);
        $context->recordRound(new SpinResult($stake, $paid, [], [], 'bet', ['egt_round' => $round->id]),
            json_encode(['round' => $round->id, 'bet' => $bet]) ?: '');
        $number = (int) ($context->stateGet('egt_number') ?? random_int(1_000_000_000, 1_300_000_000)) + 1;
        $context->statePut(['egt_gamble' => null, 'egt_number' => $number]);

        return $this->serve($context, $request, $round, 0, [
            'round' => $round->id, 'f' => $f, 'before' => $before, 'shown' => $before - $bet, 'number' => $number,
            'bet' => $bet, 'paid' => $this->cents($paid),
        ]);
    }

    /**
     * Serve recorded step `$i` rescaled; keeps or ends the round state.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function serve(GameContext $context, array $request, EgtRound $round, int $i, array $state): array
    {
        $f = (float) $state['f'];
        $step = $round->steps[$i];
        $last = ! isset($round->steps[$i + 1]);
        $complex = $this->scale((array) ($step['complex'] ?? []), $f);
        $complex['jackpot'] = false;
        $win = (int) round((int) ($step['winAmount'] ?? 0) * $f);
        $balance = isset($step['balance'])
            ? (int) round((int) $state['before'] + ((int) $step['balance'] - $round->start_balance) * $f)
            : (int) $state['shown'];
        $phase = (string) ($step['state'] ?? 'idle');

        if ($last) {
            $context->statePut(['egt' => null]);
            $real = $this->cents($context->balance());
            if ($phase === 'gamble' && $win > 0) {
                // the win waits to be collected (or gambled); our ledger paid it already
                $gambles = (int) ($complex['gambles'] ?? 0);
                $context->statePut(['egt_gamble' => ['amount' => $win, 'gambles' => $gambles, 'number' => $state['number']]]);
                $balance = $real - $win;
            } else {
                $phase = 'idle';
                $balance = $real;
            }
        } else {
            $context->statePut(['egt' => array_merge($state, ['next' => $i + 1, 'shown' => $balance])]);
        }

        return $this->envelope($context, $request, [
            'complex' => $complex, 'state' => $phase, 'winAmount' => $win, 'gameNumber' => $state['number'],
            'balance' => $balance, 'qName' => 'app.services.messages.response.GameEventResponse',
        ]);
    }

    /** @return array<string, mixed> */
    private function gamble(GameContext $context, array $request): array
    {
        $g = $context->stateGet('egt_gamble');
        if (! is_array($g) || (int) $g['amount'] <= 0 || (int) $g['gambles'] <= 0) {
            return $this->error($context, $request, 'Invalid gamble');
        }
        $amount = (int) $g['amount'];
        $stake = $amount / 100;
        $won = app(SlotEngine::class)->gamble($context, $stake)['won'];
        if ($won) {
            $context->awardWin($stake);
        } else {
            $context->clawback($stake);
        }
        $context->recordRound(new SpinResult($won ? 0.0 : $stake, $won ? $stake * 2 : 0.0, [], [], 'gamble'),
            json_encode(['gamble' => $won ? 'won' : 'lost', 'amount' => $stake]) ?: '');

        // color 0: red (cards 1, 2), 1: black (0, 3)
        $red = (int) ($request['bet']['color'] ?? 0) === 0;
        $card = ($won === $red) ? [1, 2][random_int(0, 1)] : [0, 3][random_int(0, 1)];
        $cards = array_slice(array_merge([$card], (array) ($context->stateGet('egt_cards') ?? $this->cards())), 0, 5);
        $gambles = (int) $g['gambles'] - 1;
        $context->statePut([
            'egt_cards' => $cards,
            'egt_gamble' => $won ? ['amount' => $amount * 2, 'gambles' => $gambles, 'number' => $g['number']] : null,
        ]);
        $real = $this->cents($context->balance());

        return $this->envelope($context, $request, [
            'complex' => ['gambles' => $gambles, 'gambleAttemptsLeft' => $gambles, 'card' => $card, 'jackpot' => false, 'gameCommand' => 'gamble'],
            'state' => $won ? 'gamble' : 'idle',
            'winAmount' => $won ? $amount * 2 : 0,
            'gameNumber' => $g['number'],
            'balance' => $won ? $real - $amount * 2 : $real,
            'qName' => 'app.services.messages.response.GameEventResponse',
        ]);
    }

    /** @return array<string, mixed> */
    private function collect(GameContext $context, array $request): array
    {
        $g = $context->stateGet('egt_gamble');
        $context->statePut(['egt_gamble' => null]);

        return $this->envelope($context, $request, [
            'complex' => ['gameCommand' => 'collect'],
            'state' => 'idle',
            'winAmount' => is_array($g) ? (int) $g['amount'] : 0,
            'gameNumber' => is_array($g) ? $g['number'] : -1,
            'balance' => $this->cents($context->balance()),
            'qName' => 'app.services.messages.response.GameEventResponse',
        ]);
    }

    // ---- helpers -------------------------------------------------------

    /**
     * Money fields scaled at any depth (line / scatter wins, sub-results);
     * cell lists, symbols and counters left alone.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    private function scale(array $data, float $f): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, self::NOT_MONEY, true)) {
                continue;
            }
            if (is_string($k) && in_array($k, self::MONEY, true)) {
                $data[$k] = $this->scaleAll($v, $f);
            } elseif (is_array($v)) {
                $data[$k] = $this->scale($v, $f);
            }
        }

        return $data;
    }

    private function scaleAll(mixed $v, float $f): mixed
    {
        if (is_array($v)) {
            return array_map(fn ($x) => $this->scaleAll($x, $f), $v);
        }

        return is_int($v) || is_float($v) ? (int) round($v * $f) : $v;
    }

    /**
     * The game's bets (total bet, cents): its bet options, else EGT's own.
     *
     * @return list<int>
     */
    public function bets(GameContext $context): array
    {
        $options = array_values(array_filter(array_map(fn ($v) => (int) round((float) $v * 100), $context->betOptions()), fn ($v) => $v > 0));
        if ($options === []) {
            $options = array_map('intval', (array) ($this->config($context)['bets'] ?? [100]));
        }
        $options = array_values(array_unique($options));
        sort($options);

        return $options;
    }

    /** @return list<int> five past gamble cards for the gamble screen's history */
    private function cards(): array
    {
        return array_map(fn () => random_int(0, 3), range(1, 5));
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function envelope(GameContext $context, array $request, array $body): array
    {
        return array_merge($body, [
            'gameIdentificationNumber' => $body['gameIdentificationNumber'] ?? (int) ($request['gameIdentificationNumber'] ?? $this->config($context)['gin'] ?? 0),
            'sessionKey' => (string) ($request['sessionKey'] ?? ''),
            'msg' => $body['msg'] ?? 'success',
            'messageId' => $request['messageId'] ?? bin2hex(random_bytes(16)),
            'qName' => $body['qName'] ?? 'app.services.messages.response.BaseResponse',
            'command' => (string) ($request['command'] ?? 'bet'),
            'eventTimestamp' => (int) floor(microtime(true) * 1000),
        ]);
    }

    /** @return array<string, mixed> */
    private function error(GameContext $context, array $request, string $message): array
    {
        return $this->envelope($context, $request, ['msg' => 'failure', 'message' => $message, 'state' => 'invalid',
            'complex' => ['gameCommand' => (string) ($request['bet']['gameCommand'] ?? 'bet')],
            'balance' => $this->cents($context->balance())]);
    }

    private function cents(float $money): int
    {
        return (int) round($money * 100);
    }

    /** @param  array<string, mixed>  $reply */
    private function encode(array $reply): string
    {
        return json_encode($reply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** @return array<string, mixed> the template's captured EGT setup */
    private function config(GameContext $context): array
    {
        return (array) ($context->config()->bonusConfig()['egt'] ?? []);
    }
}
