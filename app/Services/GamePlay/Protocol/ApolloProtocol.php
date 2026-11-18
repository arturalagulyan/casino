<?php

namespace App\Services\GamePlay\Protocol;

use App\Models\ApolloRound;
use App\Services\GamePlay\Apollo\ApolloLibrary;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;
use Illuminate\Support\Facades\Log;

/**
 * Apollo Games' casino server, our side: the client's `casino.*` actions
 * (JSON in, JSON out), as tunnelled to `/apollo/{session}/<action>`.
 *
 *   casino.init      the recorded init with our balance, currency and stakes
 *   casino.spin      no round in play: stake taken, a round drawn from the
 *                    outcome library ({@see ApolloLibrary}), its whole win
 *                    paid, its first reply served; a round in play (free
 *                    spins left): its next recorded reply, no stake
 *   casino.gamble    double or nothing on the last win (black / red toss;
 *                    Gangster World: beat the dealer's card) — played by us
 *                    ({@see SlotEngine::gamble}), not recorded
 *   casino.balance / credit / ping / close / …   bookkeeping
 *
 * Rounds are recorded at the game's smallest stake s; served at stake S,
 * every money field is × S/s and `credit` is our balance moved by the
 * recorded change × S/s (the round's last reply: our real balance).
 */
class ApolloProtocol
{
    /** Money fields of a reply's `gameState` (lists / maps: every number in them). */
    private const array STATE_MONEY = ['stake', 'winning', 'winnings', 'lastPaidGameWin', 'lastBonusTotalWinning',
        'currentBonusTotalWinning', 'startingStakeForBonus', 'mysteryValues', 'mysteryWinning'];

    /** Money fields at a reply's top level. */
    private const array TOP_MONEY = ['winval', 'winVal'];

    public function __construct(private readonly ApolloLibrary $library) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: int, 1: array<string, mixed>} HTTP status, reply body
     */
    public function dispatch(GameContext $context, string $action, array $body): array
    {
        $seq = (int) ($body['sequence'] ?? 0) + 1;
        [$status, $reply] = match ($action) {
            'casino.init' => [200, $this->init($context)],
            'casino.spin' => $this->spin($context, (float) ($body['stake'] ?? 0)),
            'casino.gamble' => ! empty($body['getGWODealersCards']) ? $this->dealCard($context)
                : $this->gamble($context, (float) ($body['stake'] ?? 0), (bool) ($body['isBetOnBlack'] ?? true),
                    isset($body['selectedCardIndex']) ? (int) $body['selectedCardIndex'] : null),
            'casino.balance' => [200, $this->money($context, $this->balance($context))],
            'casino.credit' => [200, ['credit' => $this->balance($context), 'creditCurrency' => $context->currency->value]],
            'casino.getTransactionHistory' => [200, ['transactions' => [], 'basicData' => null]],
            'casino.cashBack' => $this->error('INVALID_STAKE'),   // never offered: see noCashBack()
            default => [200, ['basicData' => null]],   // ping, close, session reports, pop-up answers
        };
        if ($status === 200 && $action !== 'casino.ping') {
            $reply['sequence'] = $seq;
        }

        return [$status, $reply];
    }

    /** @return array<string, mixed> */
    private function init(GameContext $context): array
    {
        $context->statePut(['apollo' => null, 'apollo_gamble' => null]);   // a round left unfinished was already paid
        $cfg = $this->config($context);
        $init = (array) ($cfg['init'] ?? []);
        $stakes = $this->stakes($context);
        $f = $stakes[0] / max(0.01, (float) ($cfg['record_stake'] ?? $stakes[0]));
        $init['gameState'] = $this->scaleState((array) ($init['gameState'] ?? []), $f);
        $init['gameState']['numberOfBonusRounds'] = 0;
        $init['gameState']['stake'] = $stakes[0];
        $init['gameState'] = $this->noCashBack($init['gameState']);
        $chance = max(1, $context->config()->gambleWinChance());

        return array_merge($init, $this->money($context, $this->balance($context)), [
            'stakeOptions' => $stakes, 'defaultBet' => $stakes[0], 'minBet' => min($stakes), 'defaultMaxBet' => max($stakes),
            'tossRatePlayerWin' => (int) round(100 / $chance),
            'demo' => false, 'lobbyUrl' => null, 'backUrl' => null, 'lobbyEnabled' => false, 'refreshUrl' => null,
            'externalHistoryUrl' => null, 'maxWinning' => null, 'maxWinningMultiplier' => null,
            'localesData' => ['locales' => ['en_IE' => ['localeSymbol' => $context->currency->symbol(), 'thousandsSeparator' => ',',
                'decimalSeparator' => '.', 'defaultDecimals' => 2, 'format' => 'SYMBOL_VALUE']], 'appliance' => ['DEFAULT' => 'en_IE']],
        ]);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function spin(GameContext $context, float $stake): array
    {
        $state = $context->stateGet('apollo');
        if (is_array($state)) {   // free spins of the round in play
            $round = ApolloRound::query()->find($state['round']);
            if ($round !== null && isset($round->steps[$state['next']])) {
                return [200, $this->serve($context, $round, (int) $state['next'], $state)];
            }
            Log::info("Apollo round {$state['round']}: a spin past the recording's end");
            $context->statePut(['apollo' => null]);
        }

        $cfg = $this->config($context);
        $cents = (int) round($stake * 100);
        if (! in_array($cents, array_map(fn (float $s) => (int) round($s * 100), $this->stakes($context)), true)) {
            return $this->error('INVALID_STAKE');
        }
        if ($stake > $context->balance() + 1e-9) {
            return $this->error('LOW_CREDIT');
        }
        $before = $context->balance();
        $context->placeBet($stake);
        $f = $stake / max(0.01, (float) $cfg['record_stake']);
        $budget = min($context->bankAvailable(), $context->maxWin($stake));
        $round = $this->library->pick((string) $cfg['game_key'], $context->rtpTarget(), (int) floor($budget * 100 / $f));
        $win = round($round->win_cents * $f / 100, 2);
        $paid = $context->awardWin($win);
        $context->recordRound(new SpinResult($stake, $paid, [], [], 'bet', ['apollo_round' => $round->id]),
            json_encode(['round' => $round->id, 'stake' => $stake]) ?: '');
        $context->statePut(['apollo_gamble' => null]);

        return [200, $this->serve($context, $round, 0, ['round' => $round->id, 'f' => $f, 'before' => $before, 'shown' => $before - $stake])];
    }

    /**
     * Serve recorded step `$i` rescaled; keeps or ends the round state.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function serve(GameContext $context, ApolloRound $round, int $i, array $state): array
    {
        $f = (float) $state['f'];
        $reply = $round->steps[$i];
        $last = ! isset($round->steps[$i + 1]);
        $reply['gameState'] = $this->noCashBack($this->scaleState((array) $reply['gameState'], $f));
        foreach (self::TOP_MONEY as $k) {
            if (isset($reply[$k]) && is_numeric($reply[$k])) {
                $reply[$k] = round((float) $reply[$k] * $f, 2);
            }
        }
        $shownBefore = $i === 0 ? (float) $state['before'] : (float) $state['shown'];
        $credit = $last ? $this->balance($context)
            : round((float) $state['before'] + ((float) $reply['credit'] * 100 - $round->start_credit) * $f / 100, 2);
        $reply = array_merge($reply, $this->money($context, $credit), ['balance' => $shownBefore]);
        unset($reply['forceReconnect'], $reply['forceLogout']);
        $reply['wonJackpots'] = null;

        if ($last) {
            $context->statePut(['apollo' => null]);
            $winval = (float) ($reply['winval'] ?? $reply['winVal'] ?? 0);
            $context->statePut(['apollo_gamble' => $winval > 0 ? ['amount' => $winval, 'reply' => $reply] : null]);
        } else {
            $context->statePut(['apollo' => array_merge($state, ['next' => $i + 1, 'shown' => $credit])]);
        }

        return $reply;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function gamble(GameContext $context, float $stake, bool $black, ?int $cardIndex = null): array
    {
        $g = $context->stateGet('apollo_gamble');
        if (! is_array($g) || $stake <= 0 || $stake > (float) $g['amount'] + 0.001 || $stake > $context->balance() + 1e-9) {
            return $this->error('INVALID_STAKE');
        }
        $before = $this->balance($context);
        $won = app(SlotEngine::class)->gamble($context, $stake)['won'];
        if ($won) {
            $context->awardWin($stake);
        } else {
            $context->clawback($stake);
        }
        $context->recordRound(new SpinResult($won ? 0.0 : $stake, $won ? $stake * 2 : 0.0, [], [], 'gamble'),
            json_encode(['gamble' => $won ? 'won' : 'lost', 'amount' => $stake]) ?: '');
        $result = $won ? round($stake * 2, 2) : 0.0;

        $reply = (array) $g['reply'];
        $reply['gameState'] = array_merge((array) $reply['gameState'], [
            'stake' => $stake, 'winning' => $result, 'lastPaidGameWin' => $result, 'lastBonusTotalWinning' => $stake,
            'currentBonusTotalWinning' => 0, 'numberOfBonusRounds' => 0, 'wonNumberOfBonusRounds' => 0,
            'tossedSymbol' => ($won === $black) ? 0 : 1,   // 0 black, 1 red
        ]);
        if ($cardIndex !== null) {   // Gangster World's card gamble: the picked card beats the dealer's, or not
            $reply['gameState']['cardTossState'] = $this->cardToss((array) ($g['dealer'] ?? self::card(1, 11)), $cardIndex, $won, $result);
        }
        $reply = array_merge($reply, $this->money($context, $this->balance($context)), ['balance' => $before, 'winval' => $result]);
        if (isset($reply['winVal'])) {
            $reply['winVal'] = $result;
        }
        $context->statePut(['apollo_gamble' => $won ? ['amount' => $result, 'reply' => $reply] : null]);

        return [200, $reply];
    }

    /**
     * Gangster World's card gamble, step 1 (`getGWODealersCards`): the
     * dealer's card, face up — one both outcomes can follow (3 … K). No money moves.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function dealCard(GameContext $context): array
    {
        $g = $context->stateGet('apollo_gamble');
        if (! is_array($g)) {
            return $this->error('INVALID_STAKE');
        }
        $g['dealer'] = self::card(1, 11);
        $context->statePut(['apollo_gamble' => $g]);
        $reply = (array) $g['reply'];
        $reply['gameState']['cardTossState'] = ['dealerCard' => $g['dealer'], 'playersCards' => null, 'selectedCardIndex' => 0,
            'cardTossingResult' => null, 'winning' => null];

        return [200, array_merge($reply, $this->money($context, $this->balance($context)))];
    }

    /**
     * Step 2: four face-down cards, the player's pick higher than the dealer's
     * card on a win, lower on a loss (no ties); the others at random.
     *
     * @param  array{type: string, color: string}  $dealer
     * @return array<string, mixed>
     */
    private function cardToss(array $dealer, int $index, bool $won, float $result): array
    {
        $rank = max(0, (int) array_search($dealer['type'], self::RANKS, true));
        $index = max(0, min(3, $index));
        $cards = [self::card(0, 12), self::card(0, 12), self::card(0, 12), self::card(0, 12)];
        $cards[$index] = $won ? self::card(min(12, $rank + 1), 12) : self::card(0, max(0, $rank - 1));

        return ['dealerCard' => $dealer, 'playersCards' => $cards, 'selectedCardIndex' => $index,
            'cardTossingResult' => $won ? 'WIN' : 'LOSS', 'winning' => $result];
    }

    private const array RANKS = ['CARD_2', 'CARD_3', 'CARD_4', 'CARD_5', 'CARD_6', 'CARD_7', 'CARD_8', 'CARD_9', 'CARD_10',
        'CARD_J', 'CARD_Q', 'CARD_K', 'CARD_A'];

    /** @return array{type: string, color: string} a random card of rank index $lo … $hi */
    private static function card(int $lo, int $hi): array
    {
        return ['type' => self::RANKS[random_int($lo, $hi)], 'color' => ['CLUBS', 'DIAMOND', 'HEART', 'SPADES'][random_int(0, 3)]];
    }

    /**
     * Money fields scaled, at any depth: sub-spins (`respins`, `gameSpins`,
     * `spins`) carry their own `winning` / `winnings`; Mystery Joker's
     * `mysteryValues` maps positions to amounts.
     *
     * @param  array<string, mixed>  $gs
     * @return array<string, mixed>
     */
    private function scaleState(array $gs, float $f): array
    {
        foreach ($gs as $k => $v) {
            if (is_string($k) && in_array($k, self::STATE_MONEY, true)) {
                $gs[$k] = $this->scaleAll($v, $f);
            } elseif (is_array($v)) {
                $gs[$k] = $this->scaleState($v, $f);
            }
        }

        return $gs;
    }

    private function scaleAll(mixed $v, float $f): mixed
    {
        if (is_array($v)) {
            return array_map(fn ($x) => $this->scaleAll($x, $f), $v);
        }

        return is_int($v) || is_float($v) ? round($v * $f, 2) : $v;
    }

    /**
     * Midnight Fruits 81's "cash back": a payout Apollo offers a demo player
     * whose credit ran out (`isCashBackWinReady`, claimed by `casino.cashBack`).
     * A recorded demo state, not part of the game's maths — never offered here.
     *
     * @param  array<string, mixed>  $gs
     * @return array<string, mixed>
     */
    private function noCashBack(array $gs): array
    {
        foreach (['isCashBackWinReady' => false, 'isWonCashBack' => false, 'cashBackProposedWin' => 0] as $k => $v) {
            if (array_key_exists($k, $gs)) {
                $gs[$k] = $v;
            }
        }

        return $gs;
    }

    /** @return array<string, mixed> the balance / credit fields of a reply */
    private function money(GameContext $context, float $credit): array
    {
        $iso = $context->currency->value;

        return ['balance' => $credit, 'balanceCurrency' => $iso, 'balanceCurrencyDefaultFractionDigits' => 2,
            'credit' => $credit, 'creditCurrency' => $iso, 'creditCurrencyDefaultFractionDigits' => 2];
    }

    private function balance(GameContext $context): float
    {
        return round($context->balance(), 2);
    }

    /**
     * The stakes a player may pick: the game's bet options, else Apollo's own.
     *
     * @return list<float>
     */
    public function stakes(GameContext $context): array
    {
        $options = array_values(array_filter(array_map('floatval', $context->betOptions()), fn ($v) => $v > 0));
        if ($options === []) {
            $options = array_map('floatval', (array) ($this->config($context)['stakes'] ?? [0.5]));
        }
        sort($options);

        return $options;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function error(string $code): array
    {
        return [400, ['category' => 'WARN', 'code' => $code, 'title' => $code, 'detailed' => '']];
    }

    /** @return array<string, mixed> the template's captured Apollo setup */
    private function config(GameContext $context): array
    {
        return (array) ($context->config()->bonusConfig()['apollo'] ?? []);
    }
}
