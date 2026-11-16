<?php

namespace App\Services\GamePlay\NetEnt\Table;

use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\NetEnt\NetEntReply;
use App\Services\GamePlay\SpinResult;

/**
 * NetEnt's Baccarat (Professional Series), served by our own shoe.
 *
 *   action=deal   `playerbet`, `bankerbet`, `tiebet` (cents), `selectedchip`
 *   reply         per bet `<bet>.cents`, each hand `<side>hand.hand` (`KS,9H`,
 *                 T for ten) and `.value`, per bet `<bet>win.cents` — what it
 *                 returns, stake included: Player 2×, Banker 1.95× (5 %
 *                 commission, fractional cents shown as such), Tie 9× with
 *                 Player / Banker returned; then `credit`, which NetEnt floors —
 *                 the commission's fraction of a cent stays with the house
 *
 * Punto banco: eight decks, the standard drawing rules. A deal the bank
 * could not pay is dealt again.
 */
final class NetEntBaccarat
{
    private const string RANKS = 'A23456789TJQK';

    private const string SUITS = 'SHDC';

    /** @param  (\Closure(): list<string>)|null  $shoe  the cards in dealing order; tests fix it */
    public function __construct(private readonly ?\Closure $shoe = null) {}

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>  $cfg
     */
    public function dispatch(GameContext $context, array $query, array $cfg): string
    {
        return match ((string) ($query['action'] ?? '')) {
            'init' => NetEntTable::init($context, (string) ($cfg['init'] ?? '')),
            'reloadbalance' => NetEntReply::build(['clientaction' => 'reloadbalance', 'credit' => NetEntTable::cents($context->balance()),
                'playercurrencyiso' => $context->currency->value, 'gameover' => 'true']),
            'deal' => $this->deal($context, $query, (string) ($cfg['init'] ?? '')),
            default => NetEntTable::error((string) ($query['action'] ?? ''), 'Unknown action'),
        };
    }

    /** @param  array<string, string>  $query */
    private function deal(GameContext $context, array $query, string $init): string
    {
        $limits = NetEntTable::numbers($init);
        $bets = [];
        foreach (['player', 'banker', 'tie'] as $side) {
            $v = (string) ($query["{$side}bet"] ?? '0');
            if (! ctype_digit($v) || (int) $v > ($limits['max_bet'] ?? PHP_INT_MAX)) {
                return NetEntTable::error('deal', 'Invalid bet');
            }
            $bets[$side] = (int) $v;
        }
        $total = array_sum($bets);
        if ($total < max(1, $limits['min_bet'] ?? 1)) {
            return NetEntTable::error('deal', 'No bet');
        }
        if ($total / 100 > $context->balance() + 1e-9) {
            return NetEntTable::error('deal', 'Insufficient funds');
        }
        $context->placeBet($total / 100);

        $bank = NetEntTable::bankCents($context);
        for ($try = 0; ; $try++) {
            [$player, $banker] = $this->hands();
            $returns = $this->returns($bets, $this->value($player), $this->value($banker));
            if (array_sum($returns) <= $bank || $try >= 50) {
                break;
            }
        }
        $paidCents = (int) floor(array_sum($returns) + 1e-9);
        $paid = $paidCents > 0 ? $context->awardWin($paidCents / 100) : 0.0;
        $context->recordRound(new SpinResult($total / 100, $paid, [], [], 'bet', ['baccarat' => [implode(',', $player), implode(',', $banker)]]),
            json_encode(['player' => $player, 'banker' => $banker, 'bets' => $bets]) ?: '');

        return NetEntReply::build([
            'clientaction' => 'deal',
            'bankerbet.cents' => $bets['banker'], 'bankerhand.hand' => implode(',', $banker), 'bankerhand.value' => $this->value($banker),
            'bankerwin.cents' => NetEntReply::amount($returns['banker']),
            'playerbet.cents' => $bets['player'], 'playerhand.hand' => implode(',', $player), 'playerhand.value' => $this->value($player),
            'playerwin.cents' => NetEntReply::amount($returns['player']),
            'tiebet.cents' => $bets['tie'], 'tiewin.cents' => NetEntReply::amount($returns['tie']),
            'g4mode' => 'false', 'gameover' => 'true', 'historybutton' => 'false', 'isJackpotWin' => 'false',
            'jackpotcurrency' => '', 'jackpotcurrencyiso' => $context->currency->value,
            'playercurrency' => '', 'playercurrencyiso' => $context->currency->value, 'playforfun' => 'false',
            'credit' => NetEntTable::cents($context->balance()),
        ]);
    }

    /**
     * What each bet returns, stake included, in cents (Banker's may be fractional).
     *
     * @param  array{player: int, banker: int, tie: int}  $bets
     * @return array{player: float, banker: float, tie: float}
     */
    public function returns(array $bets, int $player, int $banker): array
    {
        if ($player === $banker) {
            return ['player' => (float) $bets['player'], 'banker' => (float) $bets['banker'], 'tie' => $bets['tie'] * 9.0];
        }

        return ['player' => $player > $banker ? $bets['player'] * 2.0 : 0.0, 'banker' => $banker > $player ? $bets['banker'] * 1.95 : 0.0, 'tie' => 0.0];
    }

    /** @return array{0: list<string>, 1: list<string>} the player's and the banker's cards */
    public function hands(): array
    {
        $shoe = $this->shoe ? ($this->shoe)() : $this->shuffledShoe();
        $player = [array_shift($shoe), array_shift($shoe)];
        $banker = [array_shift($shoe), array_shift($shoe)];
        $p = $this->value($player);
        $b = $this->value($banker);
        if ($p >= 8 || $b >= 8) {   // a natural: no more cards
            return [$player, $banker];
        }
        $third = null;
        if ($p <= 5) {
            $player[] = $third = array_shift($shoe);
        }
        $t = $third === null ? null : $this->points($third);
        $bankerDraws = $t === null ? $b <= 5 : match (true) {
            $b <= 2 => true,
            $b === 3 => $t !== 8,
            $b === 4 => $t >= 2 && $t <= 7,
            $b === 5 => $t >= 4 && $t <= 7,
            $b === 6 => $t === 6 || $t === 7,
            default => false,
        };
        if ($bankerDraws) {
            $banker[] = array_shift($shoe);
        }

        return [$player, $banker];
    }

    /** @param  list<string>  $cards */
    public function value(array $cards): int
    {
        return array_sum(array_map(fn (string $c) => $this->points($c), $cards)) % 10;
    }

    private function points(string $card): int
    {
        return min(10, strpos(self::RANKS, $card[0]) + 1) % 10;
    }

    /** @return list<string> */
    private function shuffledShoe(): array
    {
        $shoe = [];
        for ($deck = 0; $deck < 8; $deck++) {
            foreach (str_split(self::RANKS) as $r) {
                foreach (str_split(self::SUITS) as $s) {
                    $shoe[] = $r.$s;
                }
            }
        }
        for ($i = count($shoe) - 1; $i > 0; $i--) {   // Fisher–Yates on a CSPRNG
            $j = random_int(0, $i);
            [$shoe[$i], $shoe[$j]] = [$shoe[$j], $shoe[$i]];
        }

        return $shoe;
    }
}
