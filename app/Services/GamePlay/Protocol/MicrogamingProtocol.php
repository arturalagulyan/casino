<?php

namespace App\Services\GamePlay\Protocol;

use App\Models\MicrogamingRound;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Microgaming\MicrogamingFamily;
use App\Services\GamePlay\Microgaming\MicrogamingLibrary;
use App\Services\GamePlay\Microgaming\MicrogamingLiveClient;
use App\Services\GamePlay\SpinResult;
use Illuminate\Support\Facades\Log;

/**
 * Microgaming's game packets, our side: the client's
 * `POST …/games/module/<mid>/client/<cid>/play` carries
 * `<Pkt …><Id … verb="AdvSlot"/><Request verbex='…' …/></Pkt>`, answered with
 * `<Pkt><Id … verb="<verbex>"/><Response>…</Response></Pkt>`.
 *
 *   Refresh   the recorded setup at our balance and the player's last bet —
 *             or, a round in play (a reload), the step last served
 *   Spin      no round in play: stake (chip × coins × coin cost) taken, a round
 *             drawn from the outcome library ({@see MicrogamingLibrary}), its
 *             whole win paid, its first reply served
 *   anything inside a round (BonusPick, FreeSpin, …): its next recorded reply
 *
 * Rounds are recorded at one coin of chip c; served at chip C × N coins, every
 * cent amount ({@see self::CENTS}) is × C·N/c and every coin count
 * ({@see self::COINS}) × N. `Player balance` is our balance moved by the
 * recorded change, scaled the same way (the round's last reply: our real balance).
 */
class MicrogamingProtocol
{
    /** Attributes holding cents. */
    public const array CENTS = ['win', 'triggeringWin', 'totalWin', 'extraWin', 'freeSpinGuaranteedWin', 'scaledFreeSpinGuaranteedWin', 'payout', 'totalPayout', 'bonusWin'];

    /** Attributes holding coins (scale with the coins bet, not the chip). */
    public const array COINS = ['numCoinsWon'];

    public function __construct(private readonly MicrogamingLibrary $library) {}

    /** @return string the reply packet */
    public function dispatch(GameContext $context, string $packet): string
    {
        $verbex = preg_match("/<Request\b[^>]*\bverbex=['\"]([A-Za-z]+)['\"]/", $packet, $m) ? $m[1] : '';
        $attrs = $this->requestAttributes($packet);
        // Quantum (Mahi Gaming): the request is JSON in the packet — {"QuantumRequest":{"Request":"Spin","BetLevel":…,"Denomination":…}}
        if (preg_match('/<!\[CDATA\[(.*?)\]\]>/s', $packet, $c) && is_array($j = json_decode($c[1], true))) {
            $q = (array) ($j['QuantumRequest'] ?? []);
            $verbex = (string) ($q['Request'] ?? $verbex);
            $attrs = ['chipSize' => (string) ($q['Denomination'] ?? ''), 'numChips' => (string) ($q['BetLevel'] ?? '')];
        }

        $reply = match ($verbex) {
            'Refresh' => $this->refresh($context),
            'Spin' => $this->spin($context, $attrs),
            // progressive jackpot info (Mega Moolah clients): none — Betboy's demo answers the same
            'GetProgInfo' => '<Pkt><Id verb="GetProgInfo"></Id><Response><Progressive/></Response></Pkt>',
            default => $this->step($context, $verbex, $attrs),
        };

        return $this->envelope($reply, $verbex === '' ? 'Refresh' : $verbex);
    }

    private function refresh(GameContext $context): string
    {
        $state = $context->stateGet('microgaming');
        if (is_array($state) && ($round = MicrogamingRound::query()->find($state['round'])) !== null && isset($round->steps[$state['next']])) {
            // a round left in play: the step last served (its own `verb`), the rest follows
            $xml = $this->scaled($round, (int) $state['next'] - 1, $state);
            if (($init = MicrogamingLiveClient::quantum((string) ($this->config($context)['init'] ?? ''))) !== null) {
                // a Quantum Refresh carries the bet settings: the setup's, with the round's stack
                $xml = $this->quantumEdit($xml, fn (array $q) => array_merge($q, ['RequestType' => 'Refresh', 'Settings' => $init['Settings'] ?? []]));
            }

            return $this->withBalance($xml, (float) $state['shown']);
        }
        $context->statePut(['microgaming' => null]);

        return $this->setup($context);
    }

    /** The recorded Refresh at our balance and chips, with the player's last bet. */
    private function setup(GameContext $context): string
    {
        $cfg = $this->config($context);
        $xml = (string) ($cfg['init'] ?? '');
        $chips = $this->chips($context);
        [$chip, $coins] = (array) $context->stateGet('microgaming_bet', [$chips[0], 1]);
        if (! in_array((int) $chip, $chips, true)) {
            [$chip, $coins] = [$chips[0], 1];
        }
        if (MicrogamingLiveClient::quantum($xml) !== null) {
            return $this->withBalance($this->quantumEdit($xml, function (array $q) use ($chips, $chip, $coins) {
                $q['Settings']['Denom']['Values'] = $chips;
                $q['Settings']['Denom']['Default'] = $chip;
                $q['Settings']['Level']['Default'] = $coins;

                return $q;
            }), $this->balance($context));
        }
        $xml = $this->setAttr($xml, 'Bet', ['validChips' => implode(',', $chips), 'chipSize' => (string) $chip, 'numChips' => (string) $coins]);
        $xml = $this->setAttr($xml, 'NextSpin', ['nextChipSize' => (string) $chip, 'nextNumChips' => (string) $coins]);

        return $this->withBalance($xml, $this->balance($context));
    }

    /** @param array<string, string> $attrs */
    private function spin(GameContext $context, array $attrs): string
    {
        if (is_array($context->stateGet('microgaming'))) {   // a Spin inside a round: its next step
            return $this->step($context, 'Spin');
        }
        $cfg = $this->config($context);
        $init = (string) ($cfg['init'] ?? '');
        $chip = (int) ($attrs['chipSize'] ?? 0);
        $coins = (int) ($attrs['numChips'] ?? 0);
        $qi = MicrogamingLiveClient::quantum($init);
        $min = (int) ($qi !== null ? ($qi['Settings']['Level']['Min'] ?? 1) : (MicrogamingLiveClient::attr($init, 'Bet', 'minChips') ?? 1));
        $max = (int) ($qi !== null ? ($qi['Settings']['Level']['Max'] ?? 1) : (MicrogamingLiveClient::attr($init, 'Bet', 'maxChips') ?? 1));
        // every line played: the recorded rounds are
        $lines = MicrogamingLiveClient::attr($init, 'Bet', 'activePaylines');
        $linesOk = ! isset($attrs['activePaylines']) || $lines === null || $attrs['activePaylines'] === $lines;
        if (! in_array($chip, $this->chips($context), true) || $coins < $min || $coins > $max || ! $linesOk) {
            return $this->error($context, 'Invalid bet');
        }
        $stake = MicrogamingFamily::stake($init, $chip, $coins) / 100;
        if ($stake > $context->balance() + 1e-9) {
            return $this->error($context, 'Insufficient funds', 3);
        }
        $k = $chip * $coins / max(1, (int) $cfg['record_chip']);
        $before = $this->balance($context);
        $context->placeBet($stake);
        $budget = min($context->bankAvailable(), $context->maxWin($stake));
        $round = $this->library->pick((string) $cfg['game_key'], $context->rtpTarget(), (int) floor($budget * 100 / $k));
        $win = round($round->win_cents * $k / 100, 2);
        $paid = $context->awardWin($win);
        $context->recordRound(new SpinResult($stake, $paid, [], [], 'bet', ['microgaming_round' => $round->id]),
            json_encode(['round' => $round->id, 'chip' => $chip, 'coins' => $coins]) ?: '');
        $context->statePut(['microgaming_bet' => [$chip, $coins]]);

        return $this->serve($context, $round, 0, ['round' => $round->id, 'k' => $k, 'coins' => $coins, 'chip' => $chip, 'before' => $before]);
    }

    /**
     * The round in play's next step, whatever the client calls it.
     *
     * @param  array<string, string>  $attrs
     */
    private function step(GameContext $context, string $verbex, array $attrs = []): string
    {
        $state = $context->stateGet('microgaming');
        if (is_array($state)) {
            $round = MicrogamingRound::query()->find($state['round']);
            if ($round !== null && isset($round->steps[$state['next']])) {
                $i = (int) $state['next'];
                $expected = (string) $round->steps[$i]['q'];
                if ($expected !== $verbex) {
                    Log::info("Microgaming round {$round->id}: client sent {$verbex}, recording has {$expected}");
                }
                // a pick other than the recorded one: carry on in a round where that pick was made
                $recorded = $round->steps[$i]['p']['item'] ?? null;
                if ($verbex === 'BonusPick' && $expected === 'BonusPick' && isset($attrs['item']) && $recorded !== null && (string) $attrs['item'] !== (string) $recorded) {
                    if (($spliced = $this->splice($context, $round, (string) $attrs['item'], $state)) !== null) {
                        [$round, $i, $state] = $spliced;
                    } else {
                        Log::info("Microgaming round {$round->id}: no recorded BonusPick item={$attrs['item']} to switch to");
                    }
                }

                return $this->serve($context, $round, $i, $state);
            }
            Log::info("Microgaming round {$state['round']}: a {$verbex} past the recording's end");
            $context->statePut(['microgaming' => null]);
        }

        return $this->setup($context);   // nothing to play: the idle state
    }

    /**
     * A pick that changes what follows (Thunderstruck II's free-spin hall):
     * carry on in another recorded round of this game where that item was
     * picked — from its own pick on. The round's win becomes that round's:
     * the difference is paid, or taken back.
     *
     * @param  array<string, mixed>  $state
     * @return array{0: MicrogamingRound, 1: int, 2: array<string, mixed>}|null
     */
    private function splice(GameContext $context, MicrogamingRound $round, string $item, array $state): ?array
    {
        $k = (float) $state['k'];
        $stake = (int) $round->bet_cents * $k / 100;
        // the new total may use the bank as it stands plus what this round already paid, within the max-win cap
        $cap = min($context->bankAvailable() + $round->win_cents * $k / 100, $context->maxWin($stake));
        $candidates = MicrogamingRound::query()->where('game_key', $round->game_key)->where('win_cents', '<=', (int) floor($cap * 100 / $k))
            ->whereRaw('JSON_CONTAINS(steps, ?)', [json_encode([['q' => 'BonusPick', 'p' => ['item' => $item]]])])
            ->pluck('id');
        if ($candidates->isEmpty()) {
            return null;
        }
        $other = MicrogamingRound::query()->find($candidates->random());
        foreach ($other->steps as $j => $step) {
            if ($step['q'] === 'BonusPick' && (string) ($step['p']['item'] ?? '') === $item) {
                $delta = round(($other->win_cents - $round->win_cents) * $k / 100, 2);
                if ($delta > 0) {
                    $context->awardWin($delta);
                } elseif ($delta < 0) {
                    $context->clawback(-$delta);
                }
                $context->recordRound(new SpinResult(max(0.0, -$delta), max(0.0, $delta), [], [], 'bonus',
                    ['microgaming_round' => $other->id, 'switched_from' => $round->id, 'item' => $item]),
                    json_encode(['round' => $other->id, 'from' => $round->id, 'item' => $item]) ?: '');
                // the balance shown runs on from what was shown, by the new round's recorded changes
                $before = (float) $state['shown'] - ($this->recordedBalance((string) $other->steps[max(0, $j - 1)]['r'], $other->start_credit) - $other->start_credit) * $k / 100;

                return [$other, $j, array_merge($state, ['round' => $other->id, 'before' => $before])];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $state */
    private function serve(GameContext $context, MicrogamingRound $round, int $i, array $state): string
    {
        $xml = $this->scaled($round, $i, $state);
        $last = ! isset($round->steps[$i + 1]);
        $recorded = $this->recordedBalance((string) $round->steps[$i]['r'], $round->start_credit);
        $balance = $last ? $this->balance($context)
            : round((float) $state['before'] + ($recorded - $round->start_credit) * (float) $state['k'] / 100, 2);
        $context->statePut(['microgaming' => $last ? null : array_merge($state, ['next' => $i + 1, 'shown' => $balance])]);

        return $this->withBalance($xml, $balance);
    }

    /** @param array<string, mixed> $state */
    private function scaled(MicrogamingRound $round, int $i, array $state): string
    {
        if (MicrogamingLiveClient::quantum((string) $round->steps[$i]['r']) !== null) {
            $k = (float) $state['k'];

            return $this->quantumEdit((string) $round->steps[$i]['r'], fn (array $q) => $this->quantumScale($q, $k, (int) $state['chip'], (int) $state['coins']));
        }
        $xml = $this->scale((string) $round->steps[$i]['r'], (float) $state['k'], (int) $state['coins']);
        $xml = $this->setAttr($xml, 'NextSpin', ['nextChipSize' => (string) $state['chip'], 'nextNumChips' => (string) $state['coins']]);

        return $this->setAttr($xml, 'Bet', ['chipSize' => (string) $state['chip'], 'numChips' => (string) $state['coins']]);
    }

    /** A recorded reply's balance, cents. */
    private function recordedBalance(string $xml, int $default): int
    {
        if (($q = MicrogamingLiveClient::quantum($xml)) !== null) {
            return (int) ($q['User']['Balance'] ?? $default);
        }

        return (int) (MicrogamingLiveClient::attr($xml, 'Player', 'balance') ?? $default);
    }

    /** Cent attributes × k, coin attributes × coins. */
    public function scale(string $xml, float $k, int $coins): string
    {
        if (abs($k - 1) < 1e-9 && $coins === 1) {
            return $xml;
        }
        $names = implode('|', array_merge(self::CENTS, self::COINS));

        return (string) preg_replace_callback('/(\s)('.$names.')="(-?\d+)"/', function ($m) use ($k, $coins) {
            $v = in_array($m[2], self::COINS, true) ? (int) $m[3] * $coins : (int) round((int) $m[3] * $k);

            return "{$m[1]}{$m[2]}=\"{$v}\"";
        }, $xml);
    }

    /** `<Player balance>` (cents) set to `$balance` (currency units). */
    private function withBalance(string $xml, float $balance): string
    {
        if (MicrogamingLiveClient::quantum($xml) !== null) {
            return $this->quantumEdit($xml, function (array $q) use ($balance) {
                $q['User']['Balance'] = (int) round($balance * 100);

                return $q;
            });
        }
        return $this->setAttr($xml, 'Player', ['balance' => (string) (int) round($balance * 100)]);
    }

    /**
     * Set attributes on the first `<$element …>` (added when missing).
     *
     * @param  array<string, string>  $values
     */
    private function setAttr(string $xml, string $element, array $values): string
    {
        return (string) preg_replace_callback('/<'.preg_quote($element, '/').'\b[^>]*?(\/?)>/', function ($m) use ($values) {
            $tag = substr($m[0], 0, -strlen($m[1].'>'));
            foreach ($values as $name => $v) {
                $v = htmlspecialchars($v, ENT_QUOTES | ENT_XML1);
                $tag = preg_match('/\s'.$name.'="[^"]*"/', $tag)
                    ? (string) preg_replace('/(\s'.$name.')="[^"]*"/', '$1="'.$v.'"', $tag)
                    : $tag." {$name}=\"{$v}\"";
            }

            return $tag.$m[1].'>';
        }, $xml, 1);
    }

    /** Quantum money: Win, FeatureWin, Wager (cents) × k; the bet blocks the player's level and denomination. */
    private const array QUANTUM_CENTS = ['Win', 'FeatureWin', 'TotalWin', 'Wager', 'Amount', 'Prize'];

    /**
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    private function quantumScale(array $q, float $k, int $chip, int $coins): array
    {
        $walk = function (array $node) use (&$walk, $k, $chip, $coins): array {
            if (($node['Type'] ?? null) === 'SlotBet') {
                $node['Level'] = $coins;
                $node['Denom'] = $chip;
            }
            foreach ($node as $key => $v) {
                if (is_array($v)) {
                    $node[$key] = $walk($v);
                } elseif (is_int($v) && in_array($key, self::QUANTUM_CENTS, true)) {
                    $node[$key] = (int) round($v * $k);
                }
            }

            return $node;
        };
        $user = $q['User'] ?? null;
        $q = $walk($q);
        if ($user !== null) {
            $q['User'] = $user;
        }

        return $q;
    }

    /** A Quantum reply with its JSON changed by `$edit`. */
    private function quantumEdit(string $xml, \Closure $edit): string
    {
        return (string) preg_replace_callback('/(<Q\b[^>]*>)(.*)(<\/Q>)/s', fn ($m) => $m[1]
            .json_encode($edit((array) json_decode(html_entity_decode($m[2], ENT_QUOTES | ENT_XML1), true)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            .$m[3], $xml, 1);
    }

    /** The reply's `<Id … verb>` is the request's verbex (what Betboy answers). */
    private function envelope(string $xml, string $verbex): string
    {
        return (string) preg_replace('/(<Id\b[^>]*\bverb=")[^"]*(")/', '${1}'.$verbex.'$2', $xml, 1);
    }

    private function error(GameContext $context, string $message, int $code = 1): string
    {
        $balance = (int) round($this->balance($context) * 100);

        return '<Pkt><Id verb="Error"/><Response><Error code="'.$code.'" msg="'.htmlspecialchars($message, ENT_QUOTES | ENT_XML1).'"/><Player balance="'.$balance.'"/></Response></Pkt>';
    }

    /** @return array<string, string> */
    private function requestAttributes(string $packet): array
    {
        if (! preg_match('/<Request\b([^>]*)>/', $packet, $m)) {
            return [];
        }
        preg_match_all("/(\w+)=['\"]([^'\"]*)['\"]/", $m[1], $a, PREG_SET_ORDER);

        return array_column(array_map(fn ($x) => [$x[1], html_entity_decode($x[2], ENT_QUOTES | ENT_XML1)], $a), 1, 0);
    }

    /**
     * Chip sizes the player may pick: the game's, kept to those whose one-coin
     * stake the game's bet options include.
     *
     * @return list<int> cents
     */
    public function chips(GameContext $context): array
    {
        $init = (string) ($this->config($context)['init'] ?? '');
        $all = MicrogamingFamily::chips($init);
        $options = array_map(fn ($b) => (int) round((float) $b * 100), $context->betOptions());
        $ok = array_values(array_filter($all, fn ($c) => in_array(MicrogamingFamily::stake($init, $c, 1), $options, true)));

        return $ok ?: $all;
    }

    private function balance(GameContext $context): float
    {
        return round($context->balance(), 2);
    }

    /** @return array<string, mixed> the template's captured Microgaming setup */
    private function config(GameContext $context): array
    {
        return (array) ($context->config()->bonusConfig()['microgaming'] ?? []);
    }
}
