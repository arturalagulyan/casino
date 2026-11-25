<?php

namespace App\Services\GamePlay\Protocol;

use App\Models\PlaytechRound;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Playtech\PlaytechLibrary;
use App\Services\GamePlay\SpinResult;
use Illuminate\Support\Facades\Log;

/**
 * Playtech's casino platform (NGM HTML5), our side: the client's Socket.IO
 * messages (JSON, typed by `ID`) as our page's WebSocket shim tunnels them to
 * `/playtech/{session}`. Each request is answered with a list of messages.
 * Money is in cents (of the player's currency).
 *
 *   31031 / 10010   URL lists (lobby, cashier, history …)  → none
 *   10001  login    → token, nickname, balance
 *   40036  broken games list → none
 *   40024  game limits → the coin sizes we offer
 *   40020  game login → the game's own setup messages as recorded, then the credit
 *   48300  waiting messages → none
 *   the spin (40021 CasinoSpinRequest, or the game's own — Penguin Prize 48385)
 *          no round in play: the bet taken, a round drawn from the outcome
 *          library ({@see PlaytechLibrary}), its whole win paid, its first
 *          step served; a round in play (free spins / respins): its next step
 *   the game's pre-spin message (Gladiator 49082) → its recorded answer
 *
 * The client works a spin's win out itself, from the grid (`display`) and its
 * paytable, so the grids are served as recorded; only the credit they carry
 * and any money fields are ours. A bet is coin × the game's bet multiplier
 * (its lines, mostly); rounds are recorded at coin 1, so at coin c every money
 * field is × c.
 */
class PlaytechNgmProtocol
{
    /** Money fields (cents) found in spin responses, at any depth. */
    private const array MONEY = ['gamewin', 'freespinwin', 'totalWin', 'totalWinAmount', 'winAmount', 'lineWinAmount', 'bonusWin', 'respinWin', 'featureWin', 'totalBonusWin'];

    /** Messages that carry no answer. */
    private const array SILENT = [10021, 40041, 40045, 40028, 40029];   // 40029: "start the bonus game" — Betboy doesn't answer it either

    public function __construct(private readonly PlaytechLibrary $library) {}

    /**
     * @param  array<string, mixed>  $msg  the client's message
     * @return list<array<string, mixed>> the messages to send back
     */
    public function dispatch(GameContext $context, array $msg): array
    {
        $id = (int) ($msg['ID'] ?? 0);
        $cfg = $this->config($context);
        $init = (array) ($cfg['init'] ?? []);
        $window = $msg['windowId'] ?? null;

        if ($this->isSpin($cfg, $msg)) {
            return $this->spin($context, $cfg, $msg);
        }
        // a feature request (a pick, "start the feature") the round in play recorded next
        $state = $context->stateGet('playtech');
        if (is_array($state) && ($round = PlaytechRound::query()->find($state['round'])) !== null
            && ($round->steps[$state['next']]['t'] ?? null) === 'q' && (int) ($round->steps[$state['next']]['q']['ID'] ?? 0) === $id) {
            return $this->serve($context, $round, (int) $state['next'], $state, $window, $msg);
        }
        foreach ((array) ($cfg['before'] ?? []) as $b) {   // the game's pre-spin message
            if ((int) ($b['ID'] ?? 0) === $id) {
                return array_map(fn ($m) => $this->window($m, $window), (array) ($b['reply'] ?? []));
            }
        }

        return match ($id) {
            19 => [['ID' => 18]],
            31031 => [['data' => ['urlList' => []], 'ID' => 31032]],
            10010 => [['data' => ['urls' => (object) []], 'ID' => 10011]],
            10001 => [
                ['token' => ['secretKey' => '', 'currency' => $this->currency($context), 'balance' => 0], 'ID' => 10002],
                ['nicknameInfo' => ['nickname' => ''], 'ID' => 10022],
                ...$this->balance($context, $this->cents($context), true),
            ],
            40036 => [['data' => ['brokenGames' => [], 'windowId' => $window], 'ID' => 40037]],
            40024 => [$this->limits($context, $cfg, (array) ($init['limits'] ?? []))],
            40020 => $this->gameLogin($context, $init, $window),
            48300 => [['data' => ['waitingLogins' => [], 'waitingAlerts' => [], 'waitingDialogs' => [], 'waitingDialogMessages' => []], 'ID' => 48301]],
            default => $this->unknown($id),
        };
    }

    /** @param array<string, mixed> $cfg */
    private function isSpin(array $cfg, array $msg): bool
    {
        $id = (int) ($msg['ID'] ?? 0);

        return isset($cfg['custom']) ? $id === (int) $cfg['custom'] : $id === 40021;
    }

    /** @return list<array<string, mixed>> */
    private function gameLogin(GameContext $context, array $init, mixed $window): array
    {
        $context->statePut(['playtech' => null]);   // a round left unfinished was already paid
        $out = [];
        $stage = null;
        foreach ((array) ($init['gameLogin'] ?? []) as $m) {   // the game's own setup (Batman Begins 48940 …)
            if (! in_array((int) ($m['ID'] ?? 0), [40026, 40027, 10006, 40083, 40085], true)) {
                $out[] = $this->window($m, $window);
                if ($stage === null && is_string($m['data']['stageName'] ?? null)) {   // Batman Begins: "temple|0,0"
                    $stage = PlaytechLibrary::state($m['data']['stageName'], array_map(fn ($c) => (int) ($c['count'] ?? 0), (array) ($m['data']['symbols'] ?? [])));
                }
            }
        }
        // a game that carries progress (Batman Begins' stage and symbol counts) opens on the recorded setup's;
        // each spin is served a round played from the state in play, and leaves the state that round left
        $context->statePut(['playtech_stage' => $stage]);
        array_push($out, ...$this->balance($context, $this->cents($context), true));
        $out[] = ['data' => ['credit' => $this->cents($context), 'windowId' => $window], 'ID' => 40026];

        return $out;
    }

    /** @return array<string, mixed> 40025, with our coin sizes */
    private function limits(GameContext $context, array $cfg, array $limits): array
    {
        $data = (array) ($limits['data'] ?? []);
        $coins = $this->coins($context);
        $data['coinSizes'] = $coins;
        if (isset($data['selectedCoin']) || ! isset($data['selectedLines'])) {
            $data['selectedCoin'] = $coins[0];
        }
        $data['funNoticeGames'] = 0;

        return ['data' => $data, 'ID' => 40025];
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return list<array<string, mixed>>
     */
    private function spin(GameContext $context, array $cfg, array $msg): array
    {
        $window = $msg['windowId'] ?? null;
        $type = strtolower((string) ($msg['spinType'] ?? $msg['type'] ?? 'regular'));
        $state = $context->stateGet('playtech');
        if (is_array($state)) {   // free spins / respins of the round in play
            $round = PlaytechRound::query()->find($state['round']);
            if ($round !== null && isset($round->steps[$state['next']]) && ($round->steps[$state['next']]['t'] ?? '') !== 'q') {
                return $this->serve($context, $round, (int) $state['next'], $state, $window);
            }
            if ($round !== null && isset($round->steps[$state['next']])) {
                Log::info("Playtech round {$state['round']}: a spin where the recording has a feature request ({$round->steps[$state['next']]['q']['ID']})");

                return [];
            }
            Log::info("Playtech round {$state['round']}: a spin past the recording's end");
            $context->statePut(['playtech' => null]);
        }
        if ($type !== 'regular') {
            return [$this->error($window, 'no feature in play')];
        }

        $mult = max(1, (int) ($cfg['mult'] ?? 1));
        $cents = (int) ($msg['bet'] ?? 0);
        if ($cents % $mult !== 0 || ! in_array(intdiv($cents, $mult), $this->coins($context), true)) {
            return [$this->error($window, 'invalid bet')];
        }
        $stake = $cents / 100;
        if ($stake > $context->balance() + 1e-9) {
            return [$this->error($window, 'insufficient funds')];
        }
        $before = $context->balance();
        $context->placeBet($stake);
        $f = $cents / max(1, (int) $cfg['record_bet']);
        $budget = min($context->bankAvailable(), $context->maxWin($stake));
        $stage = $context->stateGet('playtech_stage');
        $round = $this->library->pick((string) $cfg['game_key'], $context->rtpTarget(), (int) floor($budget * 100 / $f), is_string($stage) ? $stage : null);
        if (is_string($stage) && $round->next_stage !== null) {
            $context->statePut(['playtech_stage' => $round->next_stage]);
        }
        $win = round($round->win_cents * $f) / 100;
        $paid = $context->awardWin($win);
        $context->recordRound(new SpinResult($stake, $paid, [], [], 'bet', ['playtech_round' => $round->id]),
            json_encode(['round' => $round->id, 'stake' => $stake]) ?: '');

        return $this->serve($context, $round, 0, [
            'round' => $round->id, 'f' => $f,
            'base' => (int) round(($before - $stake) * 100),   // the credit shown with the first step
            'end' => (int) round(($before - $stake + $win) * 100),
        ], $window);
    }

    /**
     * Step `$i` of the round: its messages with our credit (the recorded credit's
     * progress through the round, scaled) and money fields, then the balance
     * the client should show next.
     *
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function serve(GameContext $context, PlaytechRound $round, int $i, array $state, mixed $window, array $request = []): array
    {
        $f = (float) $state['f'];
        $credit0 = (int) ($round->steps[0]['c'] ?? $round->start_credit);
        $shown = fn (int $recorded) => (int) $state['base'] + (int) round(($recorded - $credit0) * $f);
        // what the client asked for, where the reply repeats it (a pick's index)
        $echo = array_diff_key((array) ($round->steps[$i]['q'] ?? []), ['ID' => 1, 'windowId' => 1, 'umid' => 1]);
        $out = [];
        foreach ((array) $round->steps[$i]['m'] as $m) {
            $m = $this->window($this->scale($m, $f), $window);
            foreach ($echo as $k => $v) {
                if (isset($m['data']) && is_array($m['data']) && array_key_exists($k, $m['data']) && array_key_exists($k, $request)) {
                    $m['data'][$k] = $request[$k];
                }
            }
            if (isset($m['data']['credit'])) {
                $m['data']['credit'] = $m['data']['balance'] = $shown((int) $m['data']['credit']);
            }
            $out[] = $m;
        }
        if (isset($round->steps[$i + 1])) {
            $context->statePut(['playtech' => array_merge($state, ['next' => $i + 1])]);
            $next = $shown((int) ($round->steps[$i + 1]['c'] ?? $credit0));
        } else {
            $context->statePut(['playtech' => null]);
            $next = (int) $state['end'];
        }
        array_push($out, ...$this->balance($context, $next));

        return $out;
    }

    /** @param array<string, mixed> $m */
    private function scale(array $m, float $f): array
    {
        if ($f == 1.0) {
            return $m;
        }
        array_walk_recursive($m, function (&$v, $k) use ($f) {
            if (is_string($k) && in_array($k, self::MONEY, true) && is_numeric($v)) {
                $v = (int) round((float) $v * $f);
            }
        });

        return $m;
    }

    /** @return array<string, mixed> */
    private function window(array $m, mixed $window): array
    {
        if ($window !== null && isset($m['data']) && is_array($m['data']) && array_key_exists('windowId', $m['data'])) {
            $m['data']['windowId'] = $window;
        }
        unset($m['umid']);

        return $m;
    }

    /**
     * The balance, the ways the platform hears it: the game's dynamic balance
     * (40085) and the casino's (40083) — what the client shows and bets
     * against — and the account total (10006). Bonus balances (types 1, 2): none.
     *
     * @return list<array<string, mixed>>
     */
    private function balance(GameContext $context, int $cents, bool $all = false): array
    {
        $cur = $this->currency($context);
        $out = [];
        foreach ([40085, 40083] as $id) {
            if ($all) {
                $out[] = ['data' => ['typeBalance' => 1, 'balanceInCents' => 0], 'ID' => $id];
                $out[] = ['data' => ['typeBalance' => 2, 'balanceInCents' => 0], 'ID' => $id];
            }
            $out[] = ['data' => ['typeBalance' => 0, 'currency' => $cur, 'balanceInCents' => $cents, 'deltaBalanceInCents' => 0], 'ID' => $id];
        }
        $out[] = ['balanceInfo' => ['clientType' => 'casino', 'totalBalance' => $cents, 'currency' => $cur, 'balanceChange' => 0], 'ID' => 10006];

        return $out;
    }

    /** @return array<string, mixed> 40023 */
    private function error(mixed $window, string $why): array
    {
        Log::info("Playtech: spin refused — {$why}");

        return ['data' => ['windowId' => $window, 'errorCode' => 1, 'errorText' => $why], 'ID' => 40023];
    }

    /** @return list<array<string, mixed>> */
    private function unknown(int $id): array
    {
        if (! in_array($id, self::SILENT, true)) {
            Log::debug("Playtech: unanswered message {$id}");
        }

        return [];
    }

    /**
     * The coin sizes a player may pick: the game's bet options ÷ the bet
     * multiplier, else Playtech's own.
     *
     * @return list<int>
     */
    public function coins(GameContext $context): array
    {
        $mult = max(1, (int) ($this->config($context)['mult'] ?? 1));
        $coins = [];
        foreach ($context->betOptions() as $v) {
            $cents = (int) round((float) $v * 100);
            if ($cents > 0 && $cents % $mult === 0) {
                $coins[] = intdiv($cents, $mult);
            }
        }
        if ($coins === []) {
            $coins = array_map('intval', (array) ($this->config($context)['coins'] ?? [1]));
        }
        sort($coins);

        return array_values(array_unique($coins));
    }

    private function cents(GameContext $context): int
    {
        return (int) round($context->balance() * 100);
    }

    private function currency(GameContext $context): string
    {
        return $context->currency->value;
    }

    /** @return array<string, mixed> the template's captured Playtech setup */
    private function config(GameContext $context): array
    {
        return (array) ($context->config()->bonusConfig()['playtech'] ?? []);
    }
}
