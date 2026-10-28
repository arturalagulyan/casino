<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\Amatic\AmaticClientMath;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;
use App\Services\Legacy\Bridge\LegacyServerBridge;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The legacy Amatic "amarent" wire protocol — one handler shared by every `*AM`
 * game (its bundle is `amarent/index.html` + a WebSocket behind SocketServer).
 *
 * The client sends `{"gameData":"A/uNNN,<arg>,<arg>", …}` frames; the reply is a
 * packed hex string ({@see AmaticFormatter}), never JSON except for errors:
 *
 *   A/u25   init / settings          A/u251  spin        A/u257  gamble (red/black/suit)
 *   A/u250  re-sync                  A/u254  collect     A/u258  gamble half-collect
 *   A/u256  free spin (→ A/u251)     A/u350  balance poll
 *   A/u2566 bonus buy (gmsl: `,<lines>,<bet>,…,<n>` — price n of the init tail × the stake)
 *
 * All spin/win maths is the generic {@see SlotEngine}; money mirrors
 * {@see GamePlatformProtocol} (spin balance is pre-win, reconciled to afterBalance).
 */
class AmaticProtocol
{
    public function __construct(
        private readonly SlotEngine $engine,
        private readonly AmaticFormatter $fmt,
        private readonly AmaticRouletteHandler $roulette,
        private readonly LegacyServerBridge $legacy,
        private readonly AmaticKenoHandler $keno,
        private readonly AmaticWildcatHandler $wildcat,
        private readonly AmaticClientMath $clientMath,
    ) {}

    /**
     * @param  array<string, mixed>  $request  one decoded client frame
     * @return list<string> raw wire frames to send back verbatim
     */
    public function dispatch(GameContext $ctx, array $request): array
    {
        // the client leaving (exit / tab closing) — Amatic just ends the session
        if (str_starts_with((string) ($request['gameData'] ?? ''), 'A/u260')) {
            return [];
        }
        // Amatic table games share the socket but not the slot commands
        $table = $ctx->config()->bonusConfig()['table']['type'] ?? null;
        if (in_array($table, ['roulette', 'legacy', 'keno', 'wildcat'], true)) {
            try {
                return match ($table) {
                    'legacy' => [$this->legacy->run($ctx, (string) ($ctx->config()->bonusConfig()['table']['package'] ?? ''), (string) ($request['gameData'] ?? ''))],
                    'keno' => $this->keno->dispatch($ctx, $request),
                    'wildcat' => $this->wildcat->dispatch($ctx, $request),
                    default => $this->roulette->dispatch($ctx, $request),
                };
            } catch (\Throwable $e) {
                report($e);

                return [$this->fmt->error((string) strtok((string) ($request['gameData'] ?? ''), ','), $e->getMessage())];
            }
        }

        $parts = explode(',', (string) ($request['gameData'] ?? ''));
        $cmd = $parts[0];
        $state = (array) $ctx->stateGet('features', []);
        // The newer "gmsl" client generation sends bare `A/u…` frames
        // (SocketServer tags them `raw`) and expects Amatic's current frame tails.
        $gmsl = (bool) ($request['raw'] ?? false);

        try {
            return match ($cmd) {
                'A/u25' => [$this->fmt->settings($ctx, $state, $gmsl)],
                'A/u250' => [$this->fmt->resync($ctx, $state)],
                'A/u251', 'A/u256' => $this->spin($ctx, $parts, $cmd === 'A/u256', $gmsl),
                'A/u2566' => $this->spin($ctx, $parts, false, $gmsl, (int) end($parts)),
                'A/u254' => [$this->fmt->collect($ctx, $state)],
                'A/u257' => $this->gamble($ctx, $parts),
                'A/u258' => $this->gambleHalf($ctx),
                'A/u350' => [$this->fmt->balancePoll($ctx, $state)],
                default => [$this->fmt->error($cmd, 'unknown command')],
            };
        } catch (\Throwable $e) {
            report($e);

            return [$this->fmt->error($cmd, $e->getMessage())];
        }
    }

    // ---- spin ---------------------------------------------------

    /**
     * @param  list<string>  $parts  [cmd, lines, betIndex]
     * @param  int  $buy  bought bonus (index into the client's buy prices), -1 for a plain spin
     */
    private function spin(GameContext $ctx, array $parts, bool $freeCmd, bool $gmsl = false, int $buy = -1): array
    {
        $cfg = $ctx->config();
        $denom = $cfg->denomination();
        $state = (array) $ctx->stateGet('features', []);

        $inFreeSpins = (int) ($state['free_left'] ?? 0) > 0;
        $isFree = $freeCmd || $inFreeSpins;

        // a buy starts the free spins, so only from the base game, on a game that has them
        $offer = $buy >= 0 ? $this->buyOffer($ctx) : ['prices' => [], 'cap' => 0];
        $price = $buy >= 0 ? (float) ($offer['prices'][$buy] ?? 0) : 1.0;
        if ($buy >= 0 && ($price <= 0 || $inFreeSpins || ! $cfg->hasFreeSpins() || $cfg->scatterSymbol() === null)) {
            Log::warning('Amatic bonus buy refused', [
                'template' => $ctx->game->template->code, 'buy' => $buy, 'prices' => $offer['prices'],
                'in_free_spins' => $inFreeSpins, 'has_free_spins' => $cfg->hasFreeSpins(), 'scatter' => $cfg->scatterSymbol(),
            ]);

            return [$this->fmt->error('A/u2566', 'invalid buy')];
        }

        if ($isFree && ! $inFreeSpins) {
            return [$this->fmt->error('A/u256', 'invalid bonus state')];
        }

        if ($isFree) {
            $lines = (int) ($state['last_lines'] ?? $cfg->lineCount());
            $betLine = (float) ($state['last_bet'] ?? 0);   // already re-priced (see below)
            $betIndex = (int) ($state['last_bet_index'] ?? 0);
            $state['free_left'] = (int) $state['free_left'] - 1;
            $stake = $cost = 0.0;
        } else {
            // Ways games send their symbol count as "lines" but stake one bet
            // (legacy `$lines = 1`).
            $lines = $cfg->isWays() ? 1 : max(1, (int) ($parts[1] ?? $cfg->lineCount()));
            $betIndex = max(0, (int) ($parts[2] ?? 0));
            $bets = $ctx->betOptions();
            if (! isset($bets[$betIndex])) {
                return [$this->fmt->error('A/u251', 'invalid bet/lines')];
            }
            // Re-price the selected credit value into the player's currency
            // (see CurrencyScaler) — kept as the round's bet-per-line from
            // here on, so it matches what settings() displayed at that index.
            $betLine = round((float) $bets[$betIndex] * $denom, 8);
            $stake = round($betLine * $lines, 4);
            $cost = round($stake * $price, 4);
            if ($buy >= 0 && $offer['cap'] > 0 && round($cost * 100) > $offer['cap']) {
                return [$this->fmt->error('A/u2566', 'invalid buy')];
            }
            if ($ctx->balance() < $cost) {
                return [$this->fmt->error($buy >= 0 ? 'A/u2566' : 'A/u251', 'invalid balance')];
            }
            $ctx->placeBet($cost);
            $state = [
                'last_bet' => $betLine,
                'last_lines' => $lines,
                'last_bet_index' => $betIndex,
                'frozen_balance' => $ctx->balance(),   // post-stake, pre-win
                'bonus_win' => 0.0,
                'total_win' => 0.0,
                'free_total' => 0,
                'free_left' => 0,
                'free_symbol' => null,
            ];
        }

        $mult = $isFree ? max(1, $cfg->freeSpinsMultiplier()) : 1;
        $result = $this->engine->spin($ctx, max($stake, $betLine * $lines), $lines, $betLine, $isFree, $buy >= 0);
        $result->win = min(round($result->win * $mult, 4), $ctx->maxWin($betLine * $lines));

        if ($result->win > 0) {
            $ctx->awardWin($result->win);
        }

        $scatter = $cfg->scatterSymbol();
        $scatterCount = (int) ($result->extra['scatters'][$scatter] ?? 0);
        // a bought bonus is paid for: the free spins come even if no triggering board was found
        if (($scatterCount >= 3 || $buy >= 0) && $cfg->hasFreeSpins()) {
            $grant = $cfg->freeSpinsFor(max(3, $scatterCount));
            $state['free_left'] = (int) ($state['free_left'] ?? 0) + $grant;
            $state['free_total'] = (int) ($state['free_total'] ?? 0) + $grant;

            // "Book"-style games pick their special symbol once, on the
            // triggering spin; a re-trigger keeps it (legacy `rand(1, 8)`).
            $candidates = $cfg->freeSymbolCandidates();
            if ($candidates !== [] && ($state['free_symbol'] ?? null) === null) {
                $state['free_symbol'] = $candidates[array_rand($candidates)];
            }
        }

        if ($isFree) {
            $state['bonus_win'] = round((float) ($state['bonus_win'] ?? 0) + $result->win, 4);
        }
        $state['total_win'] = $isFree ? $state['bonus_win'] : $result->win;
        $state['gamble_amount'] = $isFree ? 0.0 : $result->win;

        $packet = $this->fmt->spin($ctx, $result, $state, $isFree, $gmsl, $buy >= 0);
        $state['rp'] = $packet['rp'];
        $state['double_answer'] = $packet['double_answer'];
        $state['win_hex'] = $packet['win_hex'];

        $ctx->statePut(['features' => $state]);
        $ctx->recordRound(
            new SpinResult(bet: $isFree ? 0.0 : $cost, win: $result->win, state: $isFree ? 'freespin' : 'bet'),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => ['totalWin' => $result->win] + ($buy >= 0 ? ['buy' => $buy] : [])]),
        );

        return [$packet['frame']];
    }

    /**
     * The bonus-buy prices (× stake) and cost cap the client was offered:
     * stored at import, else read once from the active bundle's engine
     * (games imported before the import kept them).
     *
     * @return array{prices: list<int>, cap: int}
     */
    private function buyOffer(GameContext $ctx): array
    {
        $client = $ctx->config()->amaticClient();
        if ($client['buy'] !== []) {
            return ['prices' => $client['buy'], 'cap' => $client['buy_cap']];
        }
        $bundle = $ctx->game->template->activeBundle;
        if ($bundle === null || $client['init_tail'] === null) {
            return ['prices' => [], 'cap' => 0];
        }

        return Cache::rememberForever("amatic:buy:{$bundle->id}:".md5($client['init_tail']),
            fn () => $this->clientMath->bundleBuyOptions($bundle, $client['init_tail']) ?? ['prices' => [], 'cap' => 0]);
    }

    // ---- gamble ------------------------------------------------

    /** @param list<string> $parts  [cmd, action]  action 1=red 2=black 3-6=suit */
    private function gamble(GameContext $ctx, array $parts): array
    {
        $state = (array) $ctx->stateGet('features', []);
        $amount = (float) ($state['gamble_amount'] ?? 0);
        $action = (int) ($parts[1] ?? 1);

        if ($amount <= 0 || $ctx->balance() < $amount) {
            return [$this->fmt->error('A/u257', 'invalid gamble state')];
        }

        // red/black = 1-in-2 (one coin flip); suit = 1-in-4 (two).
        $factor = $action <= 2 ? 2 : 4;
        $won = $this->engine->gamble($ctx, $amount)['won'];
        if ($won && $factor === 4) {
            $won = $this->engine->gamble($ctx, $amount)['won'];
        }
        $g = ['won' => $won];

        if ($g['won']) {
            $ctx->awardWin($amount * ($factor - 1));   // top up to amount*factor
            $state['gamble_amount'] = $amount * $factor;
            $state['total_win'] = $amount * $factor;
        } else {
            $ctx->clawback($amount);
            $state['gamble_amount'] = 0.0;
            $state['total_win'] = 0.0;
        }
        $ctx->statePut(['features' => $state]);
        $ctx->recordRound(
            new SpinResult(bet: $g['won'] ? 0.0 : $amount, win: $g['won'] ? $amount * $factor : 0.0, state: 'gamble'),
            json_encode(['responseEvent' => 'gambleResult', 'serverResponse' => ['totalWin' => $state['total_win']]]),
        );

        return [$this->fmt->gambleResult($ctx, $state, $action, $g['won'])];
    }

    /** `A/u258` — take half, keep gambling the rest. */
    private function gambleHalf(GameContext $ctx): array
    {
        $state = (array) $ctx->stateGet('features', []);
        $win = (float) ($state['gamble_amount'] ?? 0);
        if ($win <= 0) {
            return [$this->fmt->error('A/u258', 'invalid gamble state')];
        }

        $take = round($win / 2, 2);
        $state['gamble_amount'] = $win - $take;
        $state['total_win'] = $win - $take;
        $ctx->statePut(['features' => $state]);

        return [$this->fmt->gambleHalf($ctx, $state)];
    }
}
