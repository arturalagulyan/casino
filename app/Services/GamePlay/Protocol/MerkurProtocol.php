<?php

namespace App\Services\GamePlay\Protocol;

use App\Models\MerkurRound;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Merkur\MerkurLibrary;
use App\Services\GamePlay\Merkur\MerkurLiveClient;
use App\Services\GamePlay\SpinResult;
use Illuminate\Support\Facades\Log;

/**
 * Merkur's (Edict "EOC") game server, our side: the launcher's and game's
 * requests to `WebEngine.php?<path>` (JSON in, JSON out), as sent to
 * `/merkur/{session}/engine?<path>`.
 *
 *   req=gameproviderintegrationtype / req=whow-fm / …&type=walletType /
 *   …&type=gateway&/players/…     the launcher's setup: the recorded domain
 *                                  and launcher data, with our currency, our
 *                                  bundle's game URL and no ladder gamble
 *   …/gamesession/init, …/commands/start      session bookkeeping
 *   …/commands/commands  {gameData: {gameActionId, wagers, gameParams}}
 *       INIT           the recorded init: our coins, the largest line set only
 *       PLAY           no round in play: stake taken, a round drawn from the
 *                      outcome library ({@see MerkurLibrary}), its whole win
 *                      paid, its first reply served; in play (free games):
 *                      its next recorded reply, no stake
 *       FINISH_GAME    the round's recorded last reply (its win collected)
 *       RISK_BLACKRED_CHOICE / _RED / _BLACK / RISK_DIVIDE   card gamble on
 *                      the win (double or nothing, or bank half) — played by
 *                      us ({@see SlotEngine::gamble}), not recorded
 *   anything else (heartbeats, close, …)   an empty answer
 *
 * Money is in cents. Every spin plays all lines of the game's largest line
 * set at one wager per line; the client's "coin" is that wager, or — games
 * built on Edict's SplitCoinWagerFacade (one fixed line set) — the total bet,
 * split over the lines. Rounds are recorded at the smallest total stake
 * (wager w per line); served at wager W every money field is × W/w and the
 * depot is our balance (mid-round: moved by the recorded change × W/w, less
 * the win not yet collected).
 */
class MerkurProtocol
{
    private const string RESPONSE = 'de.edict.eoc.gaming.comm.GameClientActionResponseDTO';

    /** Money fields of a reply's game data (cents), at any depth. */
    private const array MONEY = ['winSum', 'debit', 'credit', 'uncommittedWinSum', 'lastWagerSum', 'totalWinSum', 'freeGamesWinSum',
        'jackpotWinSum', 'winAmount', 'coinValue', 'cashValue'];

    /** Gamble actions we never offer (the ladder is not played here). */
    private const array HIDDEN = ['RISK_LADDER_CHOICE'];

    public function __construct(private readonly MerkurLibrary $library) {}

    /**
     * @param  string  $query  the raw query string — WebEngine.php's "path"
     * @param  array<string, mixed>  $body
     * @param  array{game_root?: string, launch?: string}  $urls  our bundle's URLs for the game
     * @return array{0: int, 1: array<string, mixed>} HTTP status, reply body
     */
    public function dispatch(GameContext $context, string $query, array $body, array $urls = []): array
    {
        $query = rawurldecode($query);
        $cfg = $this->config($context);

        if (str_starts_with($query, 'req=gameproviderintegrationtype')) {
            return [200, ['gameProviderIntegrationType' => 'edict']];
        }
        if (str_starts_with($query, 'req=') && str_contains($query, 'type=walletType')) {
            return [200, ['value' => 'EGB_WHOW']];
        }
        if (str_starts_with($query, 'req=') && str_contains($query, 'type=gateway')) {
            return [200, ['gameLauncherData' => $this->launcher($context, $urls)]];
        }
        if (str_starts_with($query, 'req=') && ! str_contains($query, 'type=') && ! str_contains($query, '/')) {   // `req=whow-fm&_=<time>`
            return [200, ['domainDTO' => $this->domain($context)]];
        }
        if (str_contains($query, '/gamesession/init')) {
            return [200, ['de.edict.eoc.gaming.comm.ResponseDTO' => []]];
        }
        if (preg_match('#/commands/start(?:[&?]|$)#', $query)) {
            $stakes = $this->stakeCents($context);

            return [200, ['de.edict.eoc.gaming.comm.JoinSessionResponseDTO' => [
                'gameMode' => 'MONEY', 'gameSessionUuid' => $context->session()->token,
                'depot' => $this->depot($this->cents($context->balance())),
                'gameSpecs' => ['minimalStake' => min($stakes), 'maximalStake' => max($stakes)], 'playerSettings' => [],
            ]]];
        }
        if (preg_match('#/commands/commands(?:[&?]|$)#', $query)) {
            $dto = (array) ($body['de.edict.eoc.gaming.comm.GameSessionRequestDTO'] ?? []);
            $data = $dto['gameData'] ?? [];
            $data = is_string($data) ? (array) json_decode($data, true) : (array) $data;

            return $this->command($context, $cfg, $data);
        }

        return [200, ['de.edict.eoc.gaming.comm.ResponseDTO' => (object) []]];   // heartbeats, session alive, close, …
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function command(GameContext $context, array $cfg, array $data): array
    {
        $action = (string) ($data['gameActionId'] ?? '');
        $state = $context->stateGet('merkur');
        $gamble = $context->stateGet('merkur_gamble');

        return match (true) {
            $action === 'INIT' => [200, $this->init($context, $cfg)],
            $action === 'PLAY' && is_array($state) => $this->next($context, $state, 'PLAY'),
            $action === 'PLAY' => $this->spin($context, $cfg, (array) ($data['wagers'] ?? [])),
            $action === 'FINISH_GAME' && is_array($gamble) => [200, $this->finishGamble($context, $gamble)],
            $action === 'FINISH_GAME' && is_array($state) && $this->inDuel($state)
                && (int) ($data['gameParams'][MerkurLiveClient::DUEL_CHOICE] ?? -1) === MerkurLiveClient::DUEL_TAKE => $this->takeOffer($context, $state),
            $action === 'FINISH_GAME' && is_array($state) => $this->next($context, $state, 'FINISH_GAME'),
            $action === 'RISK_BLACKRED_CHOICE' && is_array($state) => $this->gambleOpen($context, $state),
            in_array($action, ['RISK_BLACKRED_RED', 'RISK_BLACKRED_BLACK'], true) && is_array($gamble) => $this->gamble($context, $gamble, $action === 'RISK_BLACKRED_RED'),
            $action === 'RISK_DIVIDE' && is_array($gamble) => $this->divide($context, $gamble),
            default => $this->error(400, 'ILLEGAL_GAME_ACTION', "{$action} is not possible now"),
        };
    }

    /** @return array<string, mixed> */
    private function init(GameContext $context, array $cfg): array
    {
        $context->statePut(['merkur' => null, 'merkur_gamble' => null]);   // a round left unfinished was already paid
        $g = (array) ($cfg['init'] ?? []);
        $positions = $this->positions($cfg);
        $coins = $this->coins($context);
        $n = count($positions);
        $stakes = $this->stakeCents($context);
        $wager = intdiv($stakes[0], $n);
        $settings = json_encode(['coin' => $coins[0], 'countOfActiveWagerPositions' => $n, 'custom' => null]);

        $g['coins'] = $coins;
        $g['wagerPositionSets'] = [['wagerPositionIds' => $positions, 'type' => 'FIXED', 'wagerBounds' => null]];
        if (! empty($cfg['repeat_line_set'])) {   // an older client keeps its coin per line only with sets to choose from
            $g['wagerPositionSets'][] = $g['wagerPositionSets'][0];
        }
        $g['wagers'] = array_map(fn (int $p) => ['wager' => $wager, 'wagerPositionId' => $p, 'initialWager' => $wager], $positions);
        $g['nextGameActions'] = [$this->playAction($context, true)];
        $g['accounting'] = ['debit' => 0, 'credit' => 0, 'debitType' => 'WAGER', 'creditType' => 'WIN'];
        $g['uncommittedWinSum'] = 0;
        $g['lastWagerSum'] = $stakes[0];
        $g['nextGameFlowName'] = 'MAIN_GAME';
        $g['addOnGameResult'] = null;
        if (isset($g['mainGameResult']['parameters']) && is_array($g['mainGameResult']['parameters'])) {
            $g['mainGameResult']['parameters']['CLIENT_SETTINGS'] = $settings;
        }

        return $this->reply($this->cents($context->balance()), true, $g);
    }

    /**
     * @param  list<array{wager?: int, wagerPositionId?: int}>  $wagers
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function spin(GameContext $context, array $cfg, array $wagers): array
    {
        $positions = $this->positions($cfg);
        $wager = (int) ($wagers[0]['wager'] ?? 0);   // per line
        $ids = array_map(fn ($w) => (int) ($w['wagerPositionId'] ?? 0), $wagers);
        sort($ids);
        $stakeCents = $wager * count($positions);
        if ($ids !== $positions || count(array_unique(array_map(fn ($w) => (int) ($w['wager'] ?? 0), $wagers))) !== 1
            || ! in_array($stakeCents, $this->stakeCents($context), true)) {
            return $this->error(400, 'INVALID_WAGER', 'Invalid bet');
        }
        $stake = $stakeCents / 100;
        if ($stake > $context->balance() + 1e-9) {
            return $this->error(400, 'INSUFFICIENT_FUNDS', 'Insufficient funds');
        }
        $before = $this->cents($context->balance());
        $context->placeBet($stake);
        $f = $wager / max(1, (int) ($cfg['record_wager'] ?? 1));
        $budget = min($context->bankAvailable(), $context->maxWin($stake));
        $round = $this->library->pick((string) $cfg['game_key'], $context->rtpTarget(), (int) floor($budget * 100 / $f));
        $win = round($round->win_cents * $f / 100, 2);
        $paid = $context->awardWin($win);
        $context->recordRound(new SpinResult($stake, $paid, [], [], 'bet', ['merkur_round' => $round->id]),
            json_encode(['round' => $round->id, 'stake' => $stake]) ?: '');
        $context->statePut(['merkur_gamble' => null]);

        return [200, $this->serve($context, $round, 0, ['round' => $round->id, 'f' => $f, 'before' => $before, 'stake' => $stakeCents])];
    }

    /**
     * The round's next recorded reply, whatever the client asked (PLAY in free
     * games, FINISH_GAME to collect) — the recording decides.
     *
     * @param  array<string, mixed>  $state
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function next(GameContext $context, array $state, string $action): array
    {
        $round = MerkurRound::query()->find($state['round']);
        $i = (int) $state['next'];
        if ($round === null || ! isset($round->steps[$i])) {
            Log::info("Merkur round {$state['round']}: {$action} past the recording's end");
            $context->statePut(['merkur' => null]);

            return [200, $this->finished($context, null)];
        }
        if (($round->steps[$i]['action'] ?? $action) !== $action) {
            Log::info("Merkur round {$round->id} step {$i}: client sent {$action}, recorded {$round->steps[$i]['action']}");
        }

        return [200, $this->serve($context, $round, $i, $state)];
    }

    /**
     * Serve recorded step `$i` rescaled; keeps or ends the round state.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function serve(GameContext $context, MerkurRound $round, int $i, array $state): array
    {
        $f = (float) $state['f'];
        $step = $round->steps[$i];
        $last = ! isset($round->steps[$i + 1]);
        $g = $this->scale((array) $step['game'], $f);
        $g['nextGameActions'] = $this->actions($context, (array) ($g['nextGameActions'] ?? []));
        $depot = $last ? $this->cents($context->balance())
            : (int) $state['before'] + (int) round(((int) ($step['core']['depot']['balance'] ?? 0) - $round->start_credit) * $f);

        if ($last) {
            $context->statePut(['merkur' => null]);
        } else {
            $context->statePut(['merkur' => array_merge($state, ['next' => $i + 1, 'reply' => $g])]);
        }

        // mid-round as recorded: Baker Street's duel keeps isGameFinished set until it is collected
        return $this->reply($depot, $last || ! empty($step['core']['isGameFinished']), $g);
    }

    /** @param array<string, mixed> $state */
    private function inDuel(array $state): bool
    {
        return ($state['reply']['nextGameFlowName'] ?? '') === 'ADD_ON_GAME'
            && ($state['reply']['addOnGameResult']['creatorName'] ?? $state['reply']['addOnGameInitResult']['creatorName'] ?? '') === 'DUEL';
    }

    /**
     * Baker Street's duel, the player taking the offer (FINISH_GAME
     * {userChoice: 0}) — the recording always went on to the last shot. The
     * offer is the player's current draw factor × the stake; what the round
     * paid at the spin (the recording's duel win) is settled to it, and the
     * round goes on to the recording's collect step.
     *
     * @param  array<string, mixed>  $state
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function takeOffer(GameContext $context, array $state): array
    {
        $round = MerkurRound::query()->find($state['round']);
        $current = (array) $state['reply'];
        $addon = (array) ($current['addOnGameResult'] ?? []);
        $factors = (array) ($current['addOnGameInitResult']['drawFactors'] ?? $addon['drawFactors'] ?? $state['duel_factors'] ?? []);
        $index = $addon['playerConsumedIndex'] ?? null;
        $steps = $round !== null ? $round->steps : [];
        // the recording's duel result: its last step in RISK_CHOICE, before the collect
        $final = null;
        for ($k = count($steps) - 1; $k >= 0; $k--) {
            if (($steps[$k]['game']['nextGameFlowName'] ?? '') === 'RISK_CHOICE') {
                $final = $k;
                break;
            }
        }
        if ($index === null || ! isset($factors[$index]) || $final === null) {
            return $this->error(400, 'ILLEGAL_GAME_ACTION', 'No offer to take');
        }
        $f = (float) $state['f'];
        $g = $this->scale((array) $steps[$final]['game'], $f);
        $recordedWins = (array) ($g['addOnGameResult']['winnings'] ?? []);
        $recordedWin = (int) array_sum(array_column($recordedWins, 'winSum'));
        $baseUnc = (int) ($g['uncommittedWinSum'] ?? 0) - $recordedWin;    // the spin's own win, still to collect
        $stake = (int) $state['stake'];
        $multiplier = max(1, (int) ($addon['multiplier'] ?? 1));
        $take = (int) $factors[$index] * $stake * $multiplier;
        // winFactor as the recording counts it (per factor point)
        $recordedFactor = (int) ($steps[$final]['game']['addOnGameResult']['playerConsumedIndex'] ?? -1);
        $perPoint = isset($recordedWins[0]['winFactor'], $factors[$recordedFactor]) && (int) $factors[$recordedFactor] > 0
            ? (int) $recordedWins[0]['winFactor'] / (int) $factors[$recordedFactor] : 10;

        $delta = ($take - $recordedWin) / 100;
        if ($delta > 0) {
            $context->awardWin($delta);
        } elseif ($delta < 0) {
            $context->clawback(-$delta);
        }
        $context->recordRound(new SpinResult(max(0.0, -$delta), max(0.0, $delta), [], [], 'bonus', ['merkur_round' => $round->id, 'duel_take' => $take]),
            json_encode(['duel' => 'take', 'offer' => $take, 'recorded' => $recordedWin]) ?: '');

        $g['addOnGameResult'] = array_merge($addon, ['winnings' => [array_merge($recordedWins[0] ?? ['wagerPositionId' => 101, 'wagerId' => 2, 'winExtensions' => []],
            ['winFactor' => (int) round($factors[$index] * $perPoint), 'winSum' => $take])]]);
        $g['uncommittedWinSum'] = $baseUnc + $take;
        $g['accounting'] = array_merge((array) ($g['accounting'] ?? []), ['credit' => $baseUnc + $take]);
        $g['nextGameActions'] = $this->actions($context, (array) ($g['nextGameActions'] ?? []));
        $depot = (int) $state['before'] + (int) round(((int) ($steps[$final]['core']['depot']['balance'] ?? 0) - $round->start_credit) * $f);
        $context->statePut(['merkur' => array_merge($state, ['next' => $final + 1, 'reply' => $g])]);

        return [200, $this->reply($depot, true, $g)];
    }

    /**
     * RISK_BLACKRED_CHOICE: the card gamble opens on the win waiting to be
     * collected (already paid into the balance at the spin).
     *
     * @param  array<string, mixed>  $state
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function gambleOpen(GameContext $context, array $state): array
    {
        $g = (array) ($state['reply'] ?? []);
        $amount = (int) ($g['uncommittedWinSum'] ?? 0);
        if ($amount <= 0 || ! in_array('RISK_BLACKRED_CHOICE', array_column((array) ($g['nextGameActions'] ?? []), 'id'), true)) {
            return $this->error(400, 'ILLEGAL_GAME_ACTION', 'No win to gamble');
        }
        $round = MerkurRound::query()->find($state['round']);
        $finish = $round !== null ? ($round->steps[(int) $state['next']] ?? null) : null;
        $gamble = [
            'amount' => $amount, 'initial' => $amount, 'stake' => (int) $state['stake'],
            'cards' => array_map(fn () => $this->card(random_int(0, 3)), range(1, 6)),
            'reply' => $g, 'finish' => $finish !== null ? $this->scale((array) $finish['game'], (float) $state['f']) : null,
        ];
        $context->statePut(['merkur' => null, 'merkur_gamble' => $gamble]);

        return [200, $this->gambleReply($context, $gamble, null, false, 'WAGER', 0, 0)];
    }

    /**
     * RED / BLACK: double or nothing on what is at stake.
     *
     * @param  array<string, mixed>  $gamble
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function gamble(GameContext $context, array $gamble, bool $red): array
    {
        $amount = (int) $gamble['amount'];
        if ($amount <= 0 || $amount * 2 > $this->peak($context, $gamble) || $amount / 100 > $context->balance() + 1e-9) {
            return $this->error(400, 'ILLEGAL_GAME_ACTION', 'Gamble not possible');
        }
        $stake = $amount / 100;
        $won = app(SlotEngine::class)->gamble($context, $stake)['won'];
        if ($won) {
            $context->awardWin($stake);
        } else {
            $context->clawback($stake);
        }
        $context->recordRound(new SpinResult($won ? 0.0 : $stake, $won ? $stake * 2 : 0.0, [], [], 'gamble'),
            json_encode(['gamble' => $won ? 'won' : 'lost', 'amount' => $stake]) ?: '');

        // suits 0, 1 red; 2, 3 black
        $card = $this->card($won === $red ? random_int(0, 1) : random_int(2, 3));
        $gamble['cards'] = array_slice([...$gamble['cards'], $card], -6);
        $gamble['amount'] = $won ? $amount * 2 : 0;
        $context->statePut(['merkur_gamble' => $gamble]);

        return [200, $this->gambleReply($context, $gamble, $card, $won, 'RISK', $won ? $amount * 2 : 0, $amount)];
    }

    /**
     * RISK_DIVIDE: half of what is at stake is banked, the other half stays
     * in play (the money is in the balance already — only the split moves).
     *
     * @param  array<string, mixed>  $gamble
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function divide(GameContext $context, array $gamble): array
    {
        $amount = (int) $gamble['amount'];
        if ($amount < 2) {
            return $this->error(400, 'ILLEGAL_GAME_ACTION', 'Nothing to divide');
        }
        $gamble['amount'] = intdiv($amount, 2);
        $context->statePut(['merkur_gamble' => $gamble]);
        $reply = $this->gambleReply($context, $gamble, null, false, 'RISK', 0, 0);
        $reply[self::RESPONSE]['gameData'] = $this->withAccounting($reply[self::RESPONSE]['gameData'],
            ['debit' => 0, 'credit' => $amount - $gamble['amount'], 'debitType' => 'RISK', 'creditType' => 'PAYOUT']);

        return [200, $reply];
    }

    /**
     * FINISH_GAME after the gamble: what is left at stake is collected.
     *
     * @param  array<string, mixed>  $gamble
     * @return array<string, mixed>
     */
    private function finishGamble(GameContext $context, array $gamble): array
    {
        $context->statePut(['merkur_gamble' => null, 'merkur' => null]);
        $g = is_array($gamble['finish'] ?? null) ? (array) $gamble['finish'] : null;
        if ($g !== null) {
            $g['accounting'] = ['debit' => 0, 'credit' => (int) $gamble['amount'], 'debitType' => 'RISK', 'creditType' => 'PAYOUT'];
        }

        return $this->finished($context, $g);
    }

    /**
     * The round's end: depot = our balance, a new PLAY offered.
     *
     * @param  array<string, mixed>|null  $g  the recorded FINISH_GAME reply, rescaled
     * @return array<string, mixed>
     */
    private function finished(GameContext $context, ?array $g): array
    {
        $g ??= ['mainGameResult' => null, 'accounting' => ['debit' => 0, 'credit' => 0, 'debitType' => 'WAGER', 'creditType' => 'WIN'],
            'lastWagerSum' => 0, 'addOnGameInitResult' => null, 'addOnGameResult' => null, 'responseType' => 'ACTION'];
        $g['uncommittedWinSum'] = 0;
        $g['nextGameActions'] = [$this->playAction($context, false)];
        $g['nextGameFlowName'] = 'MAIN_GAME';

        return $this->reply($this->cents($context->balance()), true, $g);
    }

    /**
     * @param  array<string, mixed>  $gamble
     * @param  array<string, int>|null  $card
     * @return array<string, mixed>
     */
    private function gambleReply(GameContext $context, array $gamble, ?array $card, bool $won, string $debitType, int $winSum, int $wager): array
    {
        $amount = (int) $gamble['amount'];
        $peak = $this->peak($context, $gamble);
        $g = (array) $gamble['reply'];
        $g['uncommittedWinSum'] = $amount;
        $g['accounting'] = $debitType === 'WAGER' ? $g['accounting'] ?? []
            : ['debit' => $won ? $winSum : 0, 'credit' => $winSum, 'debitType' => 'RISK', 'creditType' => 'WIN'];
        $g['nextGameFlowName'] = 'RISK_CHOICE';
        $offer = ['FINISH_GAME'];
        if ($amount > 0 && $amount * 2 <= $peak) {
            array_push($offer, 'RISK_BLACKRED_RED', 'RISK_BLACKRED_BLACK');
            if ($amount >= 2) {
                $offer[] = 'RISK_DIVIDE';
            }
        }
        $g['nextGameActions'] = array_map(fn ($id) => ['id' => $id, 'minTotalWager' => 0, 'maxTotalWager' => 0, 'wagerPositions' => []], $offer);
        $g['addOnGameResult'] = [
            'winnings' => [], 'creatorName' => 'BLACKRED', 'parameters' => [], 'childGameResult' => null,
            'drawnCards' => $gamble['cards'], 'initialWager' => (int) $gamble['initial'], 'peak' => $peak,
            'nextWagerStep' => $amount, 'nextWinSum' => $amount * 2, 'drawnCard' => $card,
            'winSum' => $winSum, 'wager' => $wager, 'isPeakBetStep' => $amount > 0 && $amount * 2 > $peak, 'won' => $won,
        ];

        // the depot leaves out the win still at stake (it is in our balance already)
        return $this->reply($this->cents($context->balance()) - $amount, false, $g);
    }

    /** @param array<string, mixed> $g */
    private function withAccounting(string $gameData, array $accounting): string
    {
        $g = (array) json_decode($gameData, true);
        $g['accounting'] = $accounting;

        return json_encode($g, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /** The gamble limit (cents): our max win for the round's stake. */
    private function peak(GameContext $context, array $gamble): int
    {
        return $this->cents($context->maxWin(((int) $gamble['stake']) / 100));
    }

    /** @return array<string, int> */
    private function card(int $suit): array
    {
        return ['winCard' => false, 'hold' => false, 'rankId' => random_int(0, 12), 'suitId' => $suit];
    }

    /**
     * Ours in place of the recorded actions: never the ladder; PLAY offers our coins.
     *
     * @param  list<array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
     */
    private function actions(GameContext $context, array $actions): array
    {
        $out = [];
        foreach ($actions as $a) {
            $id = (string) ($a['id'] ?? '');
            if (in_array($id, self::HIDDEN, true)) {
                continue;
            }
            if ($id === 'PLAY' && ! empty($a['maxTotalWager'])) {   // a new round's stake, not a free game
                $a = array_merge($a, array_intersect_key($this->playAction($context, false), ['minTotalWager' => 1, 'maxTotalWager' => 1]));
            }
            $out[] = $a;
        }

        return $out;
    }

    /** @return array<string, mixed> the PLAY action of a new round */
    private function playAction(GameContext $context, bool $withPositions): array
    {
        $stakes = $this->stakeCents($context);
        $a = ['id' => 'PLAY', 'minTotalWager' => min($stakes), 'maxTotalWager' => max($stakes)];
        if ($withPositions) {
            $coins = $this->coins($context);
            $a += ['wagerCondition' => 'ADD', 'debitType' => 'WAGER', 'wagerPositions' => array_map(fn (int $p) => ['id' => $p, 'wagerBounds' => [
                'possibleWagers' => $coins, 'minWager' => min($stakes), 'maxWager' => max($stakes), 'wagerStepType' => 'FIXED',
            ]], $this->positions($this->config($context)))];
        }

        return $a;
    }

    /**
     * Money fields × f, at any depth (free games and add-on games carry their own).
     *
     * @param  array<string, mixed>  $g
     * @return array<string, mixed>
     */
    private function scale(array $g, float $f): array
    {
        if ($f == 1.0) {
            return $g;
        }
        foreach ($g as $k => $v) {
            if (is_string($k) && in_array($k, self::MONEY, true) && (is_int($v) || is_float($v))) {
                $g[$k] = (int) round($v * $f);
            } elseif (is_array($v)) {
                $g[$k] = $this->scale($v, $f);
            }
        }

        return $g;
    }

    /**
     * @param  array<string, mixed>  $g
     * @return array<string, mixed>
     */
    private function reply(int $depot, bool $finished, array $g): array
    {
        if (($g['responseType'] ?? '') !== 'INIT') {
            $g = $this->reelsAsObjects($g);
        }

        return [self::RESPONSE => [
            'coreData' => ['depot' => $this->depot($depot), 'isGameFinished' => $finished],
            'gameData' => json_encode($g, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '{}',
        ]];
    }

    /**
     * Merkur's server sends a play's `reels` as {"0": …, "1": …} (an INIT's as
     * a list); decoding the recording made them lists — put the shape back.
     *
     * @param  array<string, mixed>  $g
     * @return array<string, mixed>
     */
    private function reelsAsObjects(array $g): array
    {
        foreach ($g as $k => $v) {
            if ($k === 'reels' && is_array($v) && array_is_list($v) && $v !== []) {
                $g[$k] = (object) $v;
            } elseif (is_array($v)) {
                $g[$k] = $this->reelsAsObjects($v);
            }
        }

        return $g;
    }

    /** @return array{balance: int, limitBalance: int} */
    private function depot(int $cents): array
    {
        return ['balance' => $cents, 'limitBalance' => $cents];
    }

    /** @return array<string, mixed> the recorded launcher data, pointed at our bundle */
    private function launcher(GameContext $context, array $urls): array
    {
        $l = (array) ($this->config($context)['launcher'] ?? []);
        if (isset($urls['launch'])) {
            $l['launchURL'] = $urls['launch'];
        }
        if (isset($urls['game_root'])) {
            $l['gameRootURL'] = $urls['game_root'];
        }
        $l['clientCurrency'] = $context->currency->value;
        $l['clientCurrencySymbol'] = $context->currency->symbol();
        $l['gameMode'] = 'MONEY';
        foreach ((array) ($l['gameClientConfiguration'] ?? []) as $i => $opt) {
            if (($opt['name'] ?? '') === 'ENABLE_RISK_LADDER') {
                $l['gameClientConfiguration'][$i]['value'] = 'false';
            }
        }

        return $l;
    }

    /** @return array<string, mixed> the recorded domain settings, in our currency */
    private function domain(GameContext $context): array
    {
        $d = (array) ($this->config($context)['domain'] ?? []);
        $d['currency'] = $context->currency->value;
        $d['currencyDisplayInfo'] = array_merge((array) ($d['currencyDisplayInfo'] ?? []), [
            'currencyCode' => $context->currency->value, 'currencySymbol' => $context->currency->symbol(),
        ]);

        return $d;
    }

    /** @return list<int> the line (wager position) ids every spin plays: the game's largest set */
    private function positions(array $cfg): array
    {
        $p = array_map('intval', (array) ($cfg['positions'] ?? []));
        sort($p);

        return $p ?: [1];
    }

    /**
     * The coins the client offers (cents): the stakes per line, or the total
     * stakes for a split-coin game.
     *
     * @return list<int>
     */
    public function coins(GameContext $context): array
    {
        $cfg = $this->config($context);
        if (! empty($cfg['split_coins'])) {
            return $this->stakeCents($context);
        }
        $n = count($this->positions($cfg));

        return array_map(fn (int $s) => intdiv($s, $n), $this->stakeCents($context));
    }

    /**
     * The total stakes (cents) a player may pick: the game's bet options that
     * split evenly over its lines, else the ones recorded.
     *
     * @return list<int>
     */
    public function stakeCents(GameContext $context): array
    {
        $cfg = $this->config($context);
        $n = count($this->positions($cfg));
        $stakes = [];
        foreach ($context->betOptions() as $stake) {
            $c = (int) round((float) $stake * 100);
            if ($c > 0 && $c % $n === 0) {
                $stakes[] = $c;
            }
        }
        if ($stakes === []) {
            $stakes = array_map(fn ($s) => (int) round((float) $s * 100), (array) ($cfg['stakes'] ?? [0.1]));
        }
        $stakes = array_values(array_unique($stakes));
        sort($stakes);

        return $stakes;
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function error(int $status, string $code, string $message): array
    {
        return [$status, ['errorResponseDTO' => ['systemErrorCode' => 0, 'returnCode' => $status, 'returnMsg' => $message, 'code' => $code]]];
    }

    /** @return array<string, mixed> the template's captured Merkur setup */
    private function config(GameContext $context): array
    {
        return (array) ($context->config()->bonusConfig()['merkur'] ?? []);
    }
}
