<?php

namespace App\Services\GamePlay\Protocol;

use App\Enums\BonusFlow;
use App\Models\GameLog;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * The EGT "GamePlatform" wire protocol — one handler shared by every game that
 * speaks it (its category config sets `client_protocol: game_platform`).
 *
 * Translates the client's `login / settings / subscribe / ping / bet` (+ the
 * `bet.gameCommand` sub-commands `gamble / collect / multiplierchoice /
 * freespinchoice / bonuschoice`) to/from the generic {@see SlotEngine} and
 * {@see GameConfig}. Nothing here is game-specific: paytable, reels, paylines,
 * symbols, feature flows and their parameters all come from the DB
 * (game_templates + games, editable in the admin panel).
 *
 * @see GamePlatformFormatter  reel/win → client shape
 * @see GamePlatformBonusRounds     the pick/gamble sub-commands
 */
class GamePlatformProtocol implements GameProtocol
{
    private const string SESSION_KEY = '00000000000000000000000000000000';

    public function __construct(
        private readonly SlotEngine $engine,
        private readonly GamePlatformLobby $lobby,
        private readonly GamePlatformFormatter $formatter,
        private readonly GamePlatformBonusRounds $bonus,
    ) {}

    /**
     * @param  array<string, mixed>  $request  a decoded client frame
     * @return list<array<string, mixed>> messages to frame back (one JSON object each)
     */
    public function dispatch(GameContext $context, array $request): array
    {
        $command = (string) ($request['command'] ?? '');
        $sub = (string) ($request['bet']['gameCommand'] ?? '');

        if ($command === 'bet' && in_array($sub, ['gamble', 'collect', 'multiplierchoice', 'freespinchoice', 'bonuschoice', 'jackpot'], true)) {
            $command = $sub;
        }

        return match ($command) {
            'login' => [$this->login($context, $request)],
            'settings' => [$this->settings($context, $request)],
            'subscribe' => [$this->subscribe($context, $request)],
            'ping' => $this->ping($context, $request),
            'bet' => $this->bet($context, $request),
            'gamble' => $this->bonus->gamble($context, $request, fn ($r) => $this->envelope($context, $request, $r)),
            'collect' => [$this->bonus->collect($context, $request, fn ($r) => $this->envelope($context, $request, $r))],
            'multiplierchoice', 'freespinchoice', 'bonuschoice' => $this->bonus->choose(
                $context, $command, $request, fn ($r) => $this->envelope($context, $request, $r),
            ),
            default => [],
        };
    }

    // ---- session bootstrap ------------------------------------------

    private function login(GameContext $context, array $request): array
    {
        return array_merge([
            'playerName' => $context->user->username ?? ('player'.$context->user->id),
            'balance' => $this->cents($context->balance()),
            'currency' => $context->currency->value,
            'languages' => ['en'],
            'groups' => ['all', 'myGames'],
            'showRtp' => false,
            'multigame' => true,
            'sendTotalsInfo' => false,
            'complex' => $this->lobby->for(
                $context->shop,
                $context->game,
                (int) ($request['gameIdentificationNumber'] ?? 0) ?: null,
            ),
        ], $this->meta($context, $request, 'login', 'LoginResponse'));
    }

    private function settings(GameContext $context, array $request): array
    {
        $cfg = $context->config();

        return array_merge([
            'complex' => array_merge(
                [
                    'balance' => $this->cents($context->balance()),
                    'balanceRaw' => $this->cents($context->balance()),
                    'bets' => array_map('intval', $context->betOptions()),
                    'jackpotMinBet' => 1,
                    'jackpotMaxBet' => 100000,
                    'jackpot' => $context->jackpots()->isNotEmpty(),
                    'denominations' => [[(int) round($cfg->denomination() * 100), 70, 3000000]],
                ],
                $this->formatter->paytableCoef($cfg),
            ),
        ], $this->meta($context, $request, 'settings', 'GameResponse'));
    }

    private function subscribe(GameContext $context, array $request): array
    {
        $state = $context->stateGet('features', []);
        $freeLeft = (int) ($state['free_spins_left'] ?? 0);

        return array_merge([
            'complex' => [
                'currentState' => array_merge(
                    [
                        'gamblesUsed' => 0,
                        'wildcard' => (int) ($state['extra_wild'] ?? -1),
                        'multiplier' => (int) ($state['multiplier'] ?? 1),
                        'freespinsUsed' => (int) ($state['free_spins_used'] ?? 0),
                        'previousGambles' => [],
                        'bet' => (int) ($state['last_bet'] ?? ($context->betOptions()[0] ?? 1) * 100),
                        'numberOfLines' => (int) ($state['last_lines'] ?? $context->config()->lineCount()),
                        'denomination' => (int) round($context->config()->denomination() * 100),
                        'state' => $freeLeft > 0 ? 'freespin' : 'idle',
                        'winAmount' => $this->cents((float) ($state['bonus_win'] ?? 0)),
                        'reels' => $this->formatter->recoverReels($this->lastSpinPayload($context), $context->config()),
                        'lines' => [],
                        'scatters' => [],
                        'expand' => [],
                        'specialExpand' => [],
                        'gambles' => $context->config()->gambleConfig()['steps'],
                        'freespins' => $freeLeft,
                        'freespinScatters' => [],
                        'jackpot' => false,
                    ],
                ),
                'jackpotState' => $this->formatter->emptyJackpotState(),
            ],
        ], $this->meta($context, $request, 'subscribe', 'GameEventResponse'));
    }

    private function ping(GameContext $context, array $request): array
    {
        $cents = $this->cents($context->balance());

        return [
            array_merge([
                'balance' => $cents,     // real wallet — the client reconciles to this
                'balance_' => $cents,
                'balanceRaw' => $cents,
                'cashBackBtn' => 0,
            ], $this->meta($context, $request, 'ping', 'BaseResponse')),
        ];
    }

    // ---- the spin -------------------------------------------------

    private function bet(GameContext $context, array $request): array
    {
        $cfg = $context->config();
        $lines = max(1, (int) ($request['bet']['lines'] ?? $cfg->lineCount()));
        $betline = (float) ($request['bet']['bet'] ?? 0) / 100;
        $isFree = in_array($request['bet']['bonus'] ?? null, ['true', true], true);
        $denom = $cfg->denomination();
        $stake = round($lines * $betline * $denom, 4);

        if ($betline <= 0) {
            return [$this->error($request, 'invalid bet state')];
        }

        $state = $context->stateGet('features', []);

        if (! $isFree) {
            if ($context->balance() < $stake) {
                return [$this->error($request, 'invalid balance')];
            }
            $context->placeBet($stake);
            $state = [
                'last_bet' => (int) round($betline * 100),
                'last_lines' => $lines,
                'bonus_bet' => $stake,
                'total_win' => 0.0,
                'bonus_win' => 0.0,
                'free_spins_left' => 0,
                'free_spins_total' => 0,
                'free_spins_used' => 0,
                'multiplier' => 1,
                'extra_wild' => -1,
                // balance the client shows during the spin — post-stake, pre-win;
                // it animates `winAmount` on top (legacy behaviour).
                'frozen_balance' => $this->cents($context->balance()),
            ];
        } else {
            $state['free_spins_used'] = (int) ($state['free_spins_used'] ?? 0) + 1;
            $state['free_spins_left'] = max(0, (int) ($state['free_spins_left'] ?? 0) - 1);
        }

        $bonusMult = $isFree ? max(1, (int) ($state['multiplier'] ?? 1)) : 1;
        $extraWild = $isFree ? (int) ($state['extra_wild'] ?? -1) : -1;

        $result = $this->engine->spin($context, $stake, $lines, $betline * $denom, $isFree);
        $result->win = round($result->win * $bonusMult, 4);
        if ($result->win > 0) {
            $context->awardWin($result->win);
        }

        $scatters = $result->extra['scatters'] ?? [];

        // Accumulate + decide the next client state.
        if ($isFree) {
            $state['bonus_win'] = (float) ($state['bonus_win'] ?? 0) + $result->win;
            $state['total_win'] = $state['bonus_win'];
        } else {
            $state['total_win'] = $result->win;
        }

        [$clientState, $extra] = $this->afterSpin($context, $cfg, $result, $scatters, $isFree, $state);

        $context->statePut(['features' => $state]);
        $context->recordRound(
            new SpinResult(bet: $isFree ? 0.0 : $stake, win: $result->win, state: $isFree ? 'freespin' : 'bet'),
            json_encode(['engine' => 'slot', 'reels' => $result->reels, 'lines' => $result->lines, 'state' => $clientState]),
        );

        // Pre-win balance in the response; the client adds `winAmount` visually,
        // then reconciles to the real wallet on the next ping (legacy behaviour).
        $displayBalance = (int) ($state['frozen_balance'] ?? $this->cents($context->balance()));
        $displayWin = $isFree ? (float) $state['bonus_win'] : $result->win;

        return [
            $this->envelope($context, $request, [
                'complex' => $this->formatter->betComplex($cfg, $result, $state, $extra),
                'state' => $clientState,
                'winAmount' => $this->cents($displayWin),
                'balance' => $displayBalance,
                'afterBalance' => $this->cents($context->balance()),
            ]),
        ];
    }

    /**
     * Feature triggers → client state + any extra payload for the bet complex.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function afterSpin(GameContext $context, GameConfig $cfg, SpinResult $result, array $scatters, bool $isFree, array &$state): array
    {
        $default = $result->win > 0 && $cfg->hasGamble() ? 'gamble' : 'idle';
        $extra = [];

        foreach ($cfg->triggerSymbols() as $sym) {
            $flow = $cfg->bonusFlowFor($sym);
            $count = (int) ($scatters[$sym] ?? 0);

            if ($count < $flow['min']) {
                continue;
            }

            switch ($flow['flow']) {
                case BonusFlow::PickMultiplierFreeSpins:
                    if ($isFree) {
                        // retrigger during free spins → just add spins
                        $state['free_spins_left'] = (int) $state['free_spins_left'] + $cfg->freeSpinsFor($count);
                        $state['free_spins_total'] = (int) ($state['free_spins_total'] ?? 0) + $cfg->freeSpinsFor($count);

                        return ['freespin', ['freespins' => (int) $state['free_spins_left']]];
                    }
                    $state['bonus_step'] = 'multiplier';
                    $state['bonus_symbol'] = $sym;

                    return ['multiplierchoice', ['choices' => 1, 'freespinScatters' => [$sym]]];

                case BonusFlow::PickMoney:
                    if ($isFree) {
                        // A bank-bonus retrigger mid-free-spins would need its
                        // own pick UI on top of the running feature — the client
                        // can't nest that. Treat it as a plain scatter win and
                        // carry on (legacy bonus strips can't produce it anyway).
                        break 2;
                    }
                    $state['bonus_step'] = 'money';
                    $state['bonus_symbol'] = $sym;
                    $state['bonus_scatter_count'] = $count;

                    return ['bonuschoice', ['bonuschoiceObject' => ['bonusScatterIndex' => $sym]]];

                case BonusFlow::FreeSpins:
                    $granted = $cfg->freeSpinsFor($count);
                    $state['free_spins_left'] = $granted;
                    $state['free_spins_total'] = $granted;
                    $state['multiplier'] = max(1, $cfg->freeSpinsMultiplier());

                    return ['freespin', ['freespins' => $granted]];

                default:
                    break;
            }
        }

        if ($isFree) {
            $done = (int) $state['free_spins_left'] <= 0;

            return [$done && (float) $state['bonus_win'] > 0 && $cfg->hasGamble() ? 'gamble' : ($done ? 'idle' : 'freespin'), []];
        }

        return [$default, $extra];
    }

    // ---- helpers -------------------------------------------------

    /** Wrap a payload in the standard EGT GameEventResponse envelope. */
    public function envelope(GameContext $context, array $request, array $payload): array
    {
        return array_merge($payload, $this->meta($context, $request, 'bet', 'GameEventResponse'));
    }

    private function meta(GameContext $context, array $request, string $command, string $qName): array
    {
        return [
            'gameIdentificationNumber' => (int) ($request['gameIdentificationNumber'] ?? $this->lobby->gin($context->config())),
            'gameNumber' => $request['gameNumber'] ?? -1,
            'sessionKey' => $request['sessionKey'] ?? self::SESSION_KEY,
            'msg' => 'success',
            'messageId' => (string) ($request['messageId'] ?? ''),
            'qName' => "app.services.messages.response.{$qName}",
            'command' => $command,
            'eventTimestamp' => (int) (microtime(true) * 1000),
        ];
    }

    private function error(array $request, string $message): array
    {
        return [
            'responseEvent' => 'error',
            'responseType' => (string) ($request['command'] ?? ''),
            'serverResponse' => $message,
            'messageId' => (string) ($request['messageId'] ?? ''),
        ];
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function lastSpinPayload(GameContext $context): ?array
    {
        $payload = GameLog::query()
            ->where('game_id', $context->game->id)
            ->where('user_id', $context->user->id)
            ->latest('id')
            ->value('payload');

        $decoded = $payload ? json_decode((string) $payload, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
