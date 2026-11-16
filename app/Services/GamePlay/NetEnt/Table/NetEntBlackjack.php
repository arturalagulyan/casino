<?php

namespace App\Services\GamePlay\NetEnt\Table;

use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\NetEnt\NetEntReply;
use App\Services\GamePlay\SpinResult;
use RuntimeException;

/**
 * NetEnt's blackjack tables, dealt by our own shoe ({@see BlackjackRound}).
 *
 *   box     Blackjack (three boxes), Single Deck Touch (one): `deal` with
 *           `bet=0:10,1:0,2:10`; `hit`, `stand`, `double`, `split`,
 *           `insurance` / `noinsurance`, `evenmoney` / `noevenmoney` act on the
 *           server's active box and hand (insurance is asked box by box);
 *           every reply is the whole table: `box.i<b>.playerhand.i<h>.*`,
 *           `dealerhand.*`, `totalbet.cents`, `totalwin.cents`, `credit`.
 *           NetEnt's `hardvalue` is the high total (an ace as 11), `softvalue`
 *           the low one
 *   touch   Blackjack Touch: `deal` with `b0.bet.amount`; `insure` /
 *           `noinsure`; replies `request=…`, `b0.h<h>.cards` / `.value` /
 *           `.value.type`, `actions`, `activehand`, the dealer as `d.*`, and
 *           per hand `outcome` (win / lost), `outcome.type`, `outcome.amount`
 *
 * Money: the bets are taken at the deal, doubles / splits / insurance when
 * made; what the settled round returns is paid at its end. A round left open
 * is played out on the next load — every hand stands, insurance declined.
 */
final class NetEntBlackjack
{
    /** @var array<string, array{format: string, boxes: int, rules: array<string, mixed>}> */
    public const array TABLES = [
        'blackjack_html' => ['format' => 'box', 'boxes' => 3, 'rules' => self::BLACKJACK],
        'blackjack_single_deck_touch' => ['format' => 'box', 'boxes' => 1, 'rules' => ['decks' => 1, 'h17' => true] + self::BLACKJACK],
        'blackjack_touch' => ['format' => 'touch', 'boxes' => 1, 'rules' => [
            'decks' => 6, 'peek' => true, 'h17' => true, 'double' => [10, 11], 'das' => false, 'resplit' => false,
            'splitTens' => true, 'enhcLosesAll' => false, 'splitAcesOne' => true,
        ]],
    ];

    private const array BLACKJACK = ['decks' => 6, 'peek' => true, 'h17' => false, 'double' => [], 'das' => true, 'resplit' => false,
        'splitTens' => true, 'enhcLosesAll' => false, 'splitAcesOne' => true];

    /** @param  (\Closure(): list<string>)|null  $shoe  the cards in dealing order; tests fix it */
    public function __construct(private readonly ?\Closure $shoe = null) {}

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>  $cfg
     */
    public function dispatch(GameContext $context, array $query, array $cfg): string
    {
        $table = self::TABLES[(string) ($cfg['game_id'] ?? '')] ?? throw new RuntimeException('Not a NetEnt blackjack table');
        $engine = new BlackjackRound($table['rules']);
        $action = (string) ($query['action'] ?? '');
        $init = (string) ($cfg['init'] ?? '');
        try {
            return match ($action) {
                'init' => $this->init($context, $engine, $init),
                'reloadbalance', 'gamehistory' => NetEntReply::build(['credit' => NetEntTable::cents($context->balance())] + $this->common($context), '&'),
                'deal' => $this->deal($context, $engine, $table, $query, $init),
                'insurance', 'insure', 'evenmoney' => $this->insure($context, $engine, $table, $action, true),
                'noinsurance', 'noinsure', 'noevenmoney' => $this->insure($context, $engine, $table, $action, false),
                'hit', 'stand', 'double', 'split' => $this->act($context, $engine, $table, $action),
                default => $this->error($table, $action, 'Unknown action'),
            };
        } catch (RuntimeException $e) {
            return $this->error($table, $action, $e->getMessage());
        }
    }

    private function init(GameContext $context, BlackjackRound $engine, string $init): string
    {
        $s = $context->stateGet('blackjack');
        if (is_array($s)) {   // left open: declined insurance, every hand stands
            foreach (array_keys($s['boxes']) as $box) {
                if ($s['phase'] === 'insurance' && ! $s['boxes'][$box]['asked']) {
                    $s = $engine->insure($s, $box, false);
                }
            }
            while ($s['phase'] === 'play') {
                $s = $engine->act($s, 'stand');
            }
            $this->settle($context, $engine, $s);
        }

        return NetEntTable::init($context, $init);
    }

    /** @param  array<string, string>  $query */
    private function deal(GameContext $context, BlackjackRound $engine, array $table, array $query, string $init): string
    {
        if (is_array($context->stateGet('blackjack'))) {
            throw new RuntimeException('A round is in play');
        }
        $limits = NetEntTable::numbers($init);
        [$min, $max] = $table['format'] === 'touch' ? [$limits['minboxbet'] ?? 1, $limits['maxboxbet'] ?? PHP_INT_MAX]
            : [$limits['MIN_BOX_BET'] ?? 1, $limits['MAX_BOX_BET'] ?? PHP_INT_MAX];
        $bets = [];
        if ($table['format'] === 'touch') {
            $bets[0] = (int) ($query['b0.bet.amount'] ?? 0);
        } else {
            foreach (explode(',', (string) ($query['bet'] ?? '')) as $pair) {
                [$box, $cents] = array_map('intval', array_pad(explode(':', $pair, 2), 2, 0));
                if ($cents > 0 && $box >= 0 && $box < $table['boxes']) {
                    $bets[$box] = $cents;
                }
            }
        }
        ksort($bets);
        if ($bets === [] || min($bets) < $min || max($bets) > $max) {
            throw new RuntimeException('Bet outside the table limits');
        }
        $this->take($context, array_sum($bets));
        $s = $engine->deal($bets, $this->shoe ? ($this->shoe)() : $engine->shoe());

        return $this->reply($context, $engine, $table, 'deal', $s);
    }

    private function insure(GameContext $context, BlackjackRound $engine, array $table, string $action, bool $take): string
    {
        $s = $this->open($context);
        if ($s['phase'] !== 'insurance') {
            throw new RuntimeException('No insurance to decide');
        }
        $box = (int) array_key_first(array_filter($s['boxes'], fn ($b) => ! $b['asked']));
        $hand = $s['boxes'][$box]['hands'][0] ?? null;
        if ($take && $hand !== null && ! $engine->isBlackjack($hand)) {
            $this->take($context, intdiv($s['boxes'][$box]['bet'], 2));
        }
        $s = $engine->insure($s, $box, $take);

        return $this->reply($context, $engine, $table, $action, $s, ['insured' => $box]);
    }

    private function act(GameContext $context, BlackjackRound $engine, array $table, string $action): string
    {
        $s = $this->open($context);
        if (! in_array($action, $engine->actions($s), true)) {
            throw new RuntimeException("'{$action}' is not open now");
        }
        $before = $s['active'];
        if ($action === 'double' || $action === 'split') {
            $this->take($context, $s['boxes'][$before[0]]['hands'][$before[1]]['bet']);
        }
        $s = $engine->act($s, $action);

        return $this->reply($context, $engine, $table, $action, $s, ['before' => $before]);
    }

    /** @return array<string, mixed> the round in play */
    private function open(GameContext $context): array
    {
        $s = $context->stateGet('blackjack');

        return is_array($s) && $s['phase'] !== 'over' ? $s : throw new RuntimeException('No round in play');
    }

    private function take(GameContext $context, int $cents): void
    {
        if ($cents / 100 > $context->balance() + 1e-9) {
            throw new RuntimeException('Insufficient funds');
        }
        $context->placeBet($cents / 100);
    }

    /** Keep the round, or pay and record it when it is over. */
    private function settle(GameContext $context, BlackjackRound $engine, array $s): void
    {
        if ($s['phase'] !== 'over') {
            $context->statePut(['blackjack' => $s]);

            return;
        }
        $returned = $engine->returned($s);
        $paid = $returned > 0 ? $context->awardWin($returned / 100) : 0.0;
        $hands = [];
        foreach ($s['boxes'] as $b) {
            foreach ($b['hands'] as $hand) {
                $hands[] = implode(',', $hand['cards']).':'.$hand['outcome'];
            }
        }
        $context->recordRound(new SpinResult($engine->staked($s) / 100, $paid, [], [], 'bet', ['blackjack' => $hands, 'dealer' => $s['dealer']]),
            json_encode(['dealer' => $s['dealer'], 'hands' => $hands]) ?: '');
        $context->statePut(['blackjack' => null]);
    }

    /** @param  array<string, mixed>  $moved  `before`: the hand that acted, `insured`: the box that answered */
    private function reply(GameContext $context, BlackjackRound $engine, array $table, string $action, array $s, array $moved = []): string
    {
        $this->settle($context, $engine, $s);

        return $table['format'] === 'touch'
            ? $this->touchReply($context, $engine, $action, $s, $moved)
            : $this->boxReply($context, $engine, $action, $s);
    }

    private function boxReply(GameContext $context, BlackjackRound $engine, string $action, array $s): string
    {
        $over = $s['phase'] === 'over';
        $asking = $s['phase'] === 'insurance' ? (int) array_key_first(array_filter($s['boxes'], fn ($b) => ! $b['asked'])) : null;
        [$activeBox, $activeHand] = $asking !== null ? [$asking, 0] : ($s['active'] ?? [0, 0]);
        $f = ['clientaction' => $action, 'activebox' => $activeBox, 'activeplayerhand' => $activeHand];
        foreach ($s['boxes'] as $b => $box) {
            foreach ($box['hands'] as $h => $hand) {
                [$high, $soft] = $engine->value($hand['cards']);
                $low = $soft ? $high - 10 : $high;
                $bust = $high > 21;
                // what the hand could do on its turn — NetEnt keeps listing it once the hand is done
                $acts = $bust ? [] : $engine->actionsFor($s, $b, $h, ignoreDone: true);
                $p = "box.i{$b}.playerhand.i{$h}.";
                $f += [
                    $p.'availableactions' => strtoupper(implode(',', $acts)),
                    $p.'blackjack' => $this->bool($engine->isBlackjack($hand)),
                    $p.'canbesplit' => $this->bool(in_array('split', $acts, true)),
                    $p.'candouble' => $this->bool(in_array('double', $acts, true)),
                    $p.'doublebet' => $hand['doubled'] ? $hand['bet'] : 0,
                    $p.'initialbet' => $hand['bet'],
                    $p.'insurancebet' => $h === 0 ? $box['insurance'] : 0,
                    $p.'hand' => implode(',', $hand['cards']),
                    $p.'hardvalue' => $high,
                    $p.'outcomeevent' => $bust ? 'bust' : ($over ? ['win' => 'normal', 'lose' => 'normal'][$hand['outcome']] ?? $hand['outcome'] : 'inplay'),
                    $p.'soft' => $this->bool($soft),
                    $p.'softvalue' => $low,
                    $p.'split' => $this->bool($hand['split']),
                    $p.'splitfromaa' => $this->bool($hand['split'] && $hand['aces']),
                ];
                if ($h > 0) {
                    $f[$p.'splitparenthandindex'] = $h - 1;
                }
                $f += [
                    $p.'state' => match (true) {
                        $bust => 'lost',
                        $s['phase'] === 'insurance' => $box['asked'] ? 'player_done' : 'inplay',
                        // a split box's hands are done together, when the last one is
                        ! $over => array_filter($box['hands'], fn ($x) => ! $x['done']) === [] ? 'player_done' : 'inplay',
                        default => in_array($hand['outcome'], ['bust', 'lose'], true) ? 'lost' : 'win',
                    },
                    $p.'win' => $over ? ($hand['return'] ?? 0) + ($h === 0 ? $box['insurancewin'] ?? 0 : 0) : 0,
                ];
            }
        }
        [$dv, $dsoft] = $engine->value($s['dealer']);
        if ($over) {
            $f += ['dealerhand.blackjack' => $this->bool($engine->isBlackjack(['cards' => $s['dealer']])), 'dealerhand.hand' => implode(',', $s['dealer']),
                'dealerhand.hardvalue' => $dv, 'dealerhand.soft' => $this->bool($dsoft), 'dealerhand.softvalue' => $dsoft ? $dv - 10 : $dv, 'dealerhand.value' => $dv];
        } else {
            $f += ['dealerhand.hand' => implode(',', $s['dealer']), 'dealerhand.offerinsurance' => $this->bool($asking !== null), 'dealerhand.value' => $dv];
        }
        $f += ['gameover' => $this->bool($over), 'totalbet.cents' => $engine->staked($s), 'totalwin.cents' => $over ? $engine->returned($s) : 0,
            'credit' => NetEntTable::cents($context->balance())];

        return NetEntReply::build($f + $this->common($context));
    }

    private function touchReply(GameContext $context, BlackjackRound $engine, string $action, array $s, array $moved): string
    {
        $over = $s['phase'] === 'over';
        $f = ['request' => $action];
        if (! $over) {
            $hand = $s['boxes'][0]['hands'][0];
            $f['actions'] = $s['phase'] === 'insurance'
                ? ($engine->isBlackjack($hand) ? 'evenmoney,noevenmoney' : 'insure,noinsure')
                : implode(',', $this->touchOrder($engine->actions($s)));
        }
        foreach ($s['boxes'][0]['hands'] as $h => $hand) {
            [$v, $soft] = $engine->value($hand['cards']);
            $p = "b0.h{$h}.";
            $f += [$p.'cards' => implode(',', $hand['cards']), $p.'isblackjack' => $this->bool($engine->isBlackjack($hand))];
            if ($over) {
                $ret = ($hand['return'] ?? 0);
                $type = ['win' => 'normal', 'lose' => 'normal'][$hand['outcome']] ?? $hand['outcome'];
                if ($ret > 0) {
                    $f[$p.'outcome.amount'] = $ret;
                }
                $f += [$p.'outcome.type' => $type, $p.'outcome' => $ret > 0 ? 'win' : 'lost'];
                if ($h === 0 && $s['boxes'][0]['insurance'] > 0) {
                    $f[$p.'insurance.amount'] = $s['boxes'][0]['insurancewin'] ?? 0;
                }
            }
            $f += [$p.'value.type' => $soft ? 'soft' : 'hard', $p.'value' => $v];
            if ($h > 0) {
                $f[$p.'parent'] = 'b0.h'.($h - 1);
            }
        }
        if (! $over) {
            $active = $s['active'][1] ?? 0;
            $f['activehand'] = "b0.h{$active}";
            if ($action === 'split') {
                $f['newhand'] = 'b0.h'.(count($s['boxes'][0]['hands']) - 1);
            } elseif (isset($moved['before']) && $s['active'] !== null && $moved['before'] !== $s['active']) {
                $f['targethand'] = "b0.h{$active}";
            }
            if ($s['phase'] === 'insurance') {
                $f['ytanta'] = 'true';
            }
        }
        [$dv, $dsoft] = $engine->value($s['dealer']);
        $f += ['d.cards' => implode(',', $s['dealer'])];
        if ($over) {
            $f += ['d.isblackjack' => $this->bool($engine->isBlackjack(['cards' => $s['dealer']])), 'd.value.type' => $dsoft ? 'soft' : 'hard'];
        }
        $f += ['d.value' => $dv, 'credit' => NetEntTable::cents($context->balance())];

        return NetEntReply::build($f + $this->common($context));
    }

    /**
     * Touch lists `hit,stand` first, then `double`, `split`.
     *
     * @param  list<string>  $actions
     * @return list<string>
     */
    private function touchOrder(array $actions): array
    {
        return array_values(array_intersect(['hit', 'stand', 'double', 'split'], $actions));
    }

    private function error(array $table, string $action, string $message): string
    {
        return NetEntReply::build([$table['format'] === 'touch' ? 'request' : 'clientaction' => $action, 'errorcode' => '1', 'errormessage' => $message]);
    }

    /** @return array<string, string> */
    private function common(GameContext $context): array
    {
        return ['historybutton' => 'false', 'isJackpotWin' => 'false', 'jackpotcurrency' => '', 'jackpotcurrencyiso' => $context->currency->value,
            'playercurrency' => '', 'playercurrencyiso' => $context->currency->value, 'playforfun' => 'false'];
    }

    private function bool(bool $b): string
    {
        return $b ? 'true' : 'false';
    }
}
