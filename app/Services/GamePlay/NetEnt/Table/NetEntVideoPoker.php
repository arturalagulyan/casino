<?php

namespace App\Services\GamePlay\NetEnt\Table;

use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\NetEnt\NetEntReply;
use App\Services\GamePlay\SpinResult;

/**
 * NetEnt's Jacks or Better (Double Up, multiple hands), served by our own deck.
 *
 *   deal       `coins` 1–5, `coinvalue` (cents), `nhands` 1/5/10/25 — the limits
 *              per hand count are in the init (`mincoins.h5`, `denominations.h5`…).
 *              The stake (coins × value × hands) is taken; reply: `cards`, the
 *              dealt hand's `handtype` and `autohold` (its paying cards), DRAWING
 *   draw       `hold` (TFTFF): every hand keeps the held cards and draws the rest
 *              from its own deck; per hand `newcards.i`, `handtype.i`,
 *              `win.coins.i`, `handwincards.i`; the total as `inplay.*`. A win is
 *              not paid yet: AWAITING_DOUBLING_OR_COLLECT, else GAMEOVER
 *   doubleup   `choice=COLLECT` pays what is in play (`win.*`, GAMEOVER);
 *              `choice=ALL` gambles it (PICKING)
 *   pick       `pick` RED / BLACK against a fresh card: double (DOUBLING, up to
 *              five times) or lose it all (GAMEOVER)
 *
 * The paytable (`pt.<hand>.<coins−1>`, coins per hand) is the init's — 9/6.
 * A draw or a gamble card the bank could not pay is drawn again.
 */
final class NetEntVideoPoker
{
    public const array HANDS = ['JACKS_OR_BETTER', 'TWO_PAIRS', 'THREE_OF_A_KIND', 'STRAIGHT', 'FLUSH',
        'FULL_HOUSE', 'FOUR_OF_A_KIND', 'STRAIGHT_FLUSH', 'ROYAL_STRAIGHT_FLUSH'];

    private const string RANKS = '23456789TJQKA';

    private const int DOUBLE_UPS = 5;

    /** @param  (\Closure(int): list<string>)|null  $deck  cards in dealing order for a draw (0: the deal, i ≥ 1: hand i−1's draw, −1: a gamble card); tests fix it */
    public function __construct(private readonly ?\Closure $deck = null) {}

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>  $cfg
     */
    public function dispatch(GameContext $context, array $query, array $cfg): string
    {
        $init = (string) ($cfg['init'] ?? '');

        return match ((string) ($query['action'] ?? '')) {
            'init' => $this->init($context, $init),
            'reloadbalance' => NetEntReply::build(['credit' => NetEntTable::cents($context->balance()), 'playercurrencyiso' => $context->currency->value], '&'),
            'paytable' => '',   // NetEnt's is empty too: the paytable is in the init
            'deal' => $this->deal($context, $query, $init),
            'draw' => $this->draw($context, $query, $init),
            'doubleup' => $this->doubleUp($context, (string) ($query['choice'] ?? '')),
            'pick' => $this->pick($context, strtoupper((string) ($query['pick'] ?? ''))),
            default => $this->error('Unknown action'),
        };
    }

    /** A round left open (the player went away) is settled in the player's favour first. */
    private function init(GameContext $context, string $init): string
    {
        $round = $context->stateGet('jacks');
        if (is_array($round)) {
            if ($round['state'] === 'DRAWING') {   // dealt, never drawn: keep every card
                $this->draw($context, ['hold' => 'TTTTT'], $init);
                $round = $context->stateGet('jacks');
            }
            if (is_array($round)) {
                $this->settle($context, $round, (int) $round['inplay']);
            }
        }

        return NetEntTable::init($context, $init);
    }

    /** @param  array<string, string>  $query */
    private function deal(GameContext $context, array $query, string $init): string
    {
        if (is_array($context->stateGet('jacks'))) {
            return $this->error('A round is in play');
        }
        $f = NetEntTable::numbers($init);
        $hands = (int) ($query['nhands'] ?? 0);
        $coins = (int) ($query['coins'] ?? 0);
        $value = (int) ($query['coinvalue'] ?? 0);
        $values = array_map('intval', explode(',', $this->field($init, "denominations.h{$hands}")));
        if (! isset($f["maxcoins.h{$hands}"]) || $coins < ($f["mincoins.h{$hands}"] ?? 1) || $coins > $f["maxcoins.h{$hands}"] || ! in_array($value, $values, true)) {
            return $this->error('Invalid bet');
        }
        $stake = $coins * $value * $hands;
        if ($stake / 100 > $context->balance() + 1e-9) {
            return $this->error('Insufficient funds');
        }
        $context->placeBet($stake / 100);

        $cards = array_slice($this->cards(0, []), 0, 5);
        [$type, $mask] = $this->evaluate($cards);
        $context->statePut(['jacks' => ['state' => 'DRAWING', 'cards' => $cards, 'hands' => $hands, 'coins' => $coins, 'value' => $value,
            'stake' => $stake, 'inplay' => 0, 'left' => self::DOUBLE_UPS]]);

        return NetEntReply::build(['autohold' => $mask, 'cards' => implode('', $cards), 'credit' => NetEntTable::cents($context->balance())]
            + $this->common($context) + ['handtype' => $type ?? 'NO_HAND', 'state' => 'DRAWING'], '&');
    }

    /** @param  array<string, string>  $query */
    private function draw(GameContext $context, array $query, string $init): string
    {
        $round = $context->stateGet('jacks');
        $hold = strtoupper((string) ($query['hold'] ?? ''));
        if (! is_array($round) || $round['state'] !== 'DRAWING') {
            return $this->error('No hand to draw to');
        }
        if (! preg_match('/^[TF]{5}$/', $hold)) {
            return $this->error('Invalid hold');
        }
        $pays = NetEntTable::numbers($init);
        $bank = NetEntTable::bankCents($context);
        for ($try = 0; ; $try++) {
            $out = [];
            $coinsWon = 0;
            for ($h = 0; $h < $round['hands']; $h++) {
                $fresh = $this->cards($h + 1, $round['cards']);
                $final = $round['cards'];
                $new = [];
                foreach (str_split($hold) as $i => $held) {
                    if ($held === 'F') {
                        $final[$i] = $new[] = array_shift($fresh);
                    }
                }
                [$type, $mask] = $this->evaluate($final);
                $won = $type === null ? 0 : (int) ($pays['pt.'.array_search($type, self::HANDS, true).'.'.($round['coins'] - 1)] ?? 0);
                $coinsWon += $won;
                $out[] = ["handtype.{$h}" => $type ?? 'NO_HAND', "newcards.{$h}" => implode('', $new), "win.coins.{$h}" => $won, "handwincards.{$h}" => $won > 0 ? $mask : 'FFFFF'];
            }
            if ($coinsWon * $round['value'] <= $bank || $try >= 50) {
                break;
            }
        }
        $inplay = $coinsWon * $round['value'];
        $fields = array_merge(...$out) + $this->common($context) + ['inplay.cents' => $inplay, 'inplay.coins' => $coinsWon];
        if ($inplay === 0) {
            $this->settle($context, $round, 0);

            return NetEntReply::build($fields + ['credit' => NetEntTable::cents($context->balance()), 'state' => 'GAMEOVER'], '&');
        }
        $context->statePut(['jacks' => ['state' => 'AWAITING_DOUBLING_OR_COLLECT', 'inplay' => $inplay] + $round]);

        return NetEntReply::build($fields + ['credit' => NetEntTable::cents($context->balance()), 'state' => 'AWAITING_DOUBLING_OR_COLLECT'], '&');
    }

    private function doubleUp(GameContext $context, string $choice): string
    {
        $round = $context->stateGet('jacks');
        if (! is_array($round) || ! in_array($round['state'], ['AWAITING_DOUBLING_OR_COLLECT', 'DOUBLING'], true)) {
            return $this->error('Nothing to collect or gamble');
        }
        if ($choice === 'COLLECT') {
            $won = (int) $round['inplay'];
            $this->settle($context, $round, $won);

            return NetEntReply::build(['state' => 'GAMEOVER', 'win.cents' => $won, 'win.coins' => intdiv($won, $round['value']),
                'credit' => NetEntTable::cents($context->balance())], '&').'&';
        }
        if ($choice !== 'ALL' || $round['left'] < 1) {
            return $this->error('Invalid choice');
        }
        $context->statePut(['jacks' => ['state' => 'PICKING'] + $round]);

        return NetEntReply::build(['collected.cents' => 0, 'collected.coins' => 0, 'inplay.cents' => $round['inplay'],
            'inplay.coins' => intdiv((int) $round['inplay'], $round['value']), 'state' => 'PICKING', 'credit' => NetEntTable::cents($context->balance())] + $this->common($context));
    }

    private function pick(GameContext $context, string $pick): string
    {
        $round = $context->stateGet('jacks');
        if (! is_array($round) || $round['state'] !== 'PICKING') {
            return $this->error('No gamble in play');
        }
        if (! in_array($pick, ['RED', 'BLACK'], true)) {
            return $this->error('Invalid pick');
        }
        $bank = NetEntTable::bankCents($context);
        $card = $this->cards(-1, [])[0];
        if ($this->color($card) === $pick && 2 * $round['inplay'] > $bank) {   // a double the bank could not pay
            $card = current(array_filter($this->cards(-1, []), fn ($c) => $this->color($c) !== $pick));
        }
        $left = $round['left'] - 1;
        $won = $this->color($card) === $pick;
        $inplay = $won ? 2 * $round['inplay'] : 0;
        $state = $won ? 'DOUBLING' : 'GAMEOVER';
        $collected = 0;
        if (! $won) {
            $this->settle($context, $round, 0);
        } elseif ($left === 0) {   // the last double: paid at once
            $this->settle($context, $round, $inplay);
            [$collected, $inplay, $state] = [$inplay, 0, 'GAMEOVER'];
        } else {
            $context->statePut(['jacks' => ['state' => 'DOUBLING', 'inplay' => $inplay, 'left' => $left] + $round]);
        }

        return NetEntReply::build(['inplay.cents' => $inplay, 'inplay.coins' => intdiv($inplay, $round['value']), 'card' => $card,
            'collected.cents' => $collected, 'collected.coins' => intdiv($collected, $round['value']), 'color' => $this->color($card),
            'credit' => NetEntTable::cents($context->balance())] + $this->common($context)
            + ['maxdoubleupsleft' => $left, 'state' => $state, 'suit' => ['S' => 'SPADES', 'H' => 'HEARTS', 'D' => 'DIAMONDS', 'C' => 'CLUBS'][$card[1]]], '&');
    }

    /** Pay what the round won (cents) and close it. */
    private function settle(GameContext $context, array $round, int $won): void
    {
        $paid = $won > 0 ? $context->awardWin($won / 100) : 0.0;
        $context->recordRound(new SpinResult($round['stake'] / 100, $paid, [], [], 'bet', ['jacks' => $round['cards']]),
            json_encode(['cards' => $round['cards'], 'hands' => $round['hands'], 'coins' => $round['coins'], 'value' => $round['value'], 'won' => $won]) ?: '');
        $context->statePut(['jacks' => null]);
    }

    /**
     * The best paying hand, and which cards make it — null under jacks.
     *
     * @param  list<string>  $cards
     * @return array{0: ?string, 1: string}
     */
    public function evaluate(array $cards): array
    {
        $ranks = array_map(fn ($c) => strpos(self::RANKS, $c[0]), $cards);
        $counts = array_count_values($ranks);
        arsort($counts);
        $sorted = $ranks;
        sort($sorted);
        $flush = count(array_unique(array_map(fn ($c) => $c[1], $cards))) === 1;
        $straight = count($counts) === 5 && ($sorted[4] - $sorted[0] === 4 || $sorted === [0, 1, 2, 3, 12]);   // A-2-3-4-5
        $all = 'TTTTT';
        $of = fn (array $want) => implode('', array_map(fn ($r) => in_array($r, $want, true) ? 'T' : 'F', $ranks));
        $groups = array_keys($counts);
        $top = array_values($counts);

        return match (true) {
            $straight && $flush && $sorted[0] === 8 => ['ROYAL_STRAIGHT_FLUSH', $all],
            $straight && $flush => ['STRAIGHT_FLUSH', $all],
            $top[0] === 4 => ['FOUR_OF_A_KIND', $of([$groups[0]])],
            $top[0] === 3 && $top[1] === 2 => ['FULL_HOUSE', $all],
            $flush => ['FLUSH', $all],
            $straight => ['STRAIGHT', $all],
            $top[0] === 3 => ['THREE_OF_A_KIND', $of([$groups[0]])],
            $top[0] === 2 && $top[1] === 2 => ['TWO_PAIRS', $of([$groups[0], $groups[1]])],
            $top[0] === 2 && $groups[0] >= 9 => ['JACKS_OR_BETTER', $of([$groups[0]])],   // J (9), Q, K, A
            default => [null, 'FFFFF'],
        };
    }

    /**
     * A shuffled deck without `$without`.
     *
     * @param  list<string>  $without
     * @return list<string>
     */
    private function cards(int $for, array $without): array
    {
        if ($this->deck) {
            return ($this->deck)($for);
        }
        $deck = [];
        foreach (str_split(self::RANKS) as $r) {
            foreach (str_split('SHDC') as $s) {
                if (! in_array($r.$s, $without, true)) {
                    $deck[] = $r.$s;
                }
            }
        }
        for ($i = count($deck) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$deck[$i], $deck[$j]] = [$deck[$j], $deck[$i]];
        }

        return $deck;
    }

    private function color(string $card): string
    {
        return in_array($card[1], ['H', 'D'], true) ? 'RED' : 'BLACK';
    }

    private function field(string $init, string $key): string
    {
        foreach (explode('&', $init) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if (urldecode($k) === $key) {
                return urldecode($v);
            }
        }

        return '';
    }

    /** @return array<string, string> */
    private function common(GameContext $context): array
    {
        return ['historybutton' => 'false', 'isJackpotWin' => 'false', 'jackpotcurrency' => '', 'jackpotcurrencyiso' => $context->currency->value,
            'playercurrency' => '', 'playercurrencyiso' => $context->currency->value, 'playforfun' => 'false'];
    }

    private function error(string $message): string
    {
        return NetEntReply::build(['errorcode' => '1', 'errormessage' => $message], '&');
    }
}
