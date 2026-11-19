<?php

namespace App\Services\GamePlay\Protocol;

use App\Models\IgtRound;
use App\Models\IgtSetup;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Igt\IgtLibrary;
use App\Services\GamePlay\SpinResult;
use Illuminate\Support\Facades\Log;

/**
 * IGT's RGS game server ("WebEngine.php"), our side: the client's
 * `{server}/<action>&…` calls (the server URL ends in `?`), JSON in and out.
 *
 *   authenticate     always fine — our session token is in the URL
 *   paytable         the recorded paytable, lines pinned to the recorded
 *                    count and the bet slider to stakes the game offers
 *   currency         our currency's format
 *   initstate        the recorded init with our balance and default bet
 *   play             no round in play: stake taken, a round drawn from the
 *                    outcome library ({@see IgtLibrary}), its whole payout
 *                    paid, its first reply served; a round in play (free
 *                    spins…): its next recorded reply, no stake
 *
 * Rounds are recorded at the smallest line bet s on every line; served at a
 * line bet of S, every credit amount is × k = S/s, and `Balance` is our
 * balance moved by the recorded change × k (the round's last reply: our real
 * balance). Credits are of the game's denomination (0.01; Cleopatra: 1).
 */
class IgtProtocol
{
    /** Credit amounts, as attribute / field names (every number in them). */
    private const array CREDITS = ['@pay', '@totalPay', 'Settled', 'Pending', 'Payout', '@amount', '@payout', '@pending', '@settled'];

    public function __construct(private readonly IgtLibrary $library) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function dispatch(GameContext $context, string $action, array $body): array
    {
        return match ($action) {
            'authenticate' => ['Authentication' => ['Status' => 'Success']],
            'paytable' => ['PaytableResponse' => $this->paytable($context)],
            'currency' => $this->currency($context),
            'initstate' => ['GameLogicResponse' => $this->init($context)],
            'play' => $this->play($context, (array) ($body['GameLogicRequest'] ?? [])),
            default => ['Exception' => ['Id' => '1', 'Message' => "Unknown action {$action}"]],
        };
    }

    /** @return array<string, mixed> */
    private function paytable(GameContext $context): array
    {
        $cfg = $this->config($context);
        $p = $this->setup($context)->paytable;
        $patterns = (string) $cfg['patterns'];
        $steps = $this->steps($context);
        // lines pinned to the recorded count; the step twice — a single step disables the older (skb) clients' bet buttons
        $p['PatternSliderInfo']['PatternInfo'] = ['@max' => $patterns, '@min' => $patterns, 'Step' => [$patterns, $patterns]];
        $p['PatternSliderInfo']['BetInfo'] = ['@max' => end($steps), '@min' => $steps[0], 'Step' => $steps];
        if (isset($p['AwardCapInfo']['CurrencyCap']['CurrencyType'])) {
            $p['AwardCapInfo']['CurrencyCap']['CurrencyType'] = $context->currency->value;
        }

        return $p;
    }

    /** @return array<string, mixed> */
    private function currency(GameContext $context): array
    {
        $c = $this->setup($context)->currency;
        $cur = (array) ($c['CURRENCIES']['CURRENCY'] ?? []);
        $cur['@currencyCode'] = $context->currency->value;
        $cur['MAJOR_SYMBOL'] = [$context->currency->symbol()];
        $cur['MINOR_SYMBOL'] = [' '];
        $cur['DECIMAL_PRECISION'] = '2';
        $c['CURRENCIES']['CURRENCY'] = $cur;

        return $c;
    }

    /** @return array<string, mixed> */
    private function init(GameContext $context): array
    {
        $context->statePut(['igt' => null]);   // a round left unfinished was already paid
        $cfg = $this->config($context);
        $init = $this->setup($context)->init;
        $init['PatternSliderInput'] = ['BetPerPattern' => $this->steps($context)[0], 'PatternsBet' => (string) $cfg['patterns']];
        $init['OutcomeDetail'] = array_merge((array) $init['OutcomeDetail'], ['Stage' => 'BaseGame', 'NextStage' => 'BaseGame',
            'GameStatus' => 'Initial', 'Settled' => '0', 'Pending' => '0', 'Payout' => '0']);

        return $this->withBalance($context, $init, $this->balance($context));
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function play(GameContext $context, array $request): array
    {
        $state = $context->stateGet('igt');
        if (is_array($state)) {   // the round in play: its next step
            $round = IgtRound::query()->find($state['round']);
            if ($round !== null && isset($round->steps[$state['next']])) {
                return ['GameLogicResponse' => $this->serve($context, $round, (int) $state['next'], $state)];
            }
            Log::info("IGT round {$state['round']}: a play past the recording's end");
            $context->statePut(['igt' => null]);
        }

        $cfg = $this->config($context);
        $slider = (array) ($request['PatternSliderInput'] ?? []);
        $perLine = (string) ($slider['BetPerPattern'] ?? '');
        $valid = array_filter($this->steps($context), fn (string $s) => is_numeric($perLine) && abs((float) $s - (float) $perLine) < 1e-9);
        if ((int) ($slider['PatternsBet'] ?? 0) !== (int) $cfg['patterns'] || $valid === []) {
            return $this->error('Invalid bet');
        }
        $k = (float) $perLine / $this->recordStep($cfg);
        $stake = round((int) $cfg['bet_cents'] * $k / 100, 2);
        if ($stake > $context->balance() + 1e-9) {
            return $this->error('Insufficient funds');
        }
        $before = $this->balance($context);
        $context->placeBet($stake);
        $budget = min($context->bankAvailable(), $context->maxWin($stake));
        $round = $this->library->pick((string) $cfg['game_key'], $context->rtpTarget(), (int) floor($budget * 100 / $k));
        $win = round($round->win_cents * $k / 100, 2);
        $paid = $context->awardWin($win);
        $context->recordRound(new SpinResult($stake, $paid, [], [], 'bet', ['igt_round' => $round->id]),
            json_encode(['round' => $round->id, 'per_line' => $k]) ?: '');

        return ['GameLogicResponse' => $this->serve($context, $round, 0, ['round' => $round->id, 'k' => $k, 'per_line' => $perLine, 'before' => $before])];
    }

    /**
     * Recorded step `$i` rescaled; keeps or ends the round state.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function serve(GameContext $context, IgtRound $round, int $i, array $state): array
    {
        $k = (float) $state['k'];
        $reply = $this->scale($round->steps[$i], $k);
        $last = ! isset($round->steps[$i + 1]);
        $recorded = (float) ($round->steps[$i]['OutcomeDetail']['Balance'] ?? 0);
        $balance = $last ? $this->balance($context)
            : round((float) $state['before'] + ($recorded * 100 - $round->start_credit) * $k / 100, 2);
        $reply['PatternSliderInput'] = ['BetPerPattern' => (string) $state['per_line'], 'PatternsBet' => (string) $this->config($context)['patterns']];
        $context->statePut(['igt' => $last ? null : array_merge($state, ['next' => $i + 1])]);

        return $this->withBalance($context, $reply, $balance);
    }

    /**
     * Our balance and a fresh transaction id written in.
     *
     * @param  array<string, mixed>  $g
     * @return array<string, mixed>
     */
    private function withBalance(GameContext $context, array $g, float $balance): array
    {
        $money = (string) round($balance, 2);
        $tx = (int) $context->stateGet('igt_tx', 0) + 1;
        $context->statePut(['igt_tx' => $tx]);
        $id = "R{$context->session()->id}-{$tx}";
        $g['OutcomeDetail']['Balance'] = $money;
        $g['OutcomeDetail']['TransactionId'] = $id;
        if (array_key_exists('TransactionId', $g)) {
            $g['TransactionId'] = $id;
        }
        $g['Balances'] = ['@totalBalance' => $money, 'Balance' => ['@name' => 'CASH', '#text' => $money]];

        return $g;
    }

    /** Every credit amount × k, at any depth. */
    private function scale(mixed $v, float $k, bool $credit = false): mixed
    {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $key => $x) {
                $out[$key] = $this->scale($x, $k, $credit || (is_string($key) && in_array($key, self::CREDITS, true)));
            }

            return $out;
        }
        if ($credit && abs($k - 1) > 1e-9 && is_numeric($v)) {
            $n = round((float) $v * $k, 2);

            return is_string($v) ? (string) (floor($n) == $n ? (int) $n : $n) : $n;
        }

        return $v;
    }

    /**
     * Line bets the player may pick: IGT's own steps, kept to those whose
     * stakes the game's bet options include.
     *
     * @return list<string>
     */
    public function steps(GameContext $context): array
    {
        $cfg = $this->config($context);
        $all = array_values(array_map('strval', (array) ($cfg['bet_steps'] ?? ['1'])));
        $options = array_map(fn ($b) => (int) round((float) $b * 100), $context->betOptions());
        $ok = array_values(array_filter($all, fn (string $s) => in_array(
            (int) round((float) $s / $this->recordStep($cfg) * (int) $cfg['bet_cents']), $options, true)));

        return $ok ?: $all;
    }

    /** @param array<string, mixed> $cfg */
    private function recordStep(array $cfg): float
    {
        return max(1e-9, (float) ($cfg['record_step'] ?? 1));
    }

    /** @return array<string, mixed> */
    private function error(string $message): array
    {
        return ['Exception' => ['Id' => '1', 'Message' => $message]];
    }

    private function balance(GameContext $context): float
    {
        return round($context->balance(), 2);
    }

    /** The game's recorded server setup (paytable, init, money format). */
    private function setup(GameContext $context): IgtSetup
    {
        return IgtSetup::query()->findOrFail((string) $this->config($context)['game_key']);
    }

    /** @return array<string, mixed> the template's captured IGT setup */
    private function config(GameContext $context): array
    {
        return (array) ($context->config()->bonusConfig()['igt'] ?? []);
    }
}
