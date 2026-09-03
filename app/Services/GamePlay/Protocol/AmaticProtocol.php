<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

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
 *
 * All spin/win maths is the generic {@see SlotEngine}; money mirrors
 * {@see GamePlatformProtocol} (spin balance is pre-win, reconciled to afterBalance).
 */
class AmaticProtocol
{
    public function __construct(
        private readonly SlotEngine $engine,
        private readonly AmaticFormatter $fmt,
    ) {}

    /**
     * @param  array<string, mixed>  $request  one decoded client frame
     * @return list<string> raw wire frames to send back verbatim
     */
    public function dispatch(GameContext $ctx, array $request): array
    {
        $parts = explode(',', (string) ($request['gameData'] ?? ''));
        $cmd = $parts[0];
        $state = (array) $ctx->stateGet('features', []);

        try {
            return match ($cmd) {
                'A/u25' => [$this->fmt->settings($ctx, $state)],
                'A/u250' => [$this->fmt->resync($ctx, $state)],
                'A/u251', 'A/u256' => $this->spin($ctx, $parts, $cmd === 'A/u256'),
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

    /** @param list<string> $parts  [cmd, lines, betIndex] */
    private function spin(GameContext $ctx, array $parts, bool $freeCmd): array
    {
        $cfg = $ctx->config();
        $denom = $cfg->denomination();
        $state = (array) $ctx->stateGet('features', []);

        $inFreeSpins = (int) ($state['free_left'] ?? 0) > 0;
        $isFree = $freeCmd || $inFreeSpins;

        if ($isFree && ! $inFreeSpins) {
            return [$this->fmt->error('A/u256', 'invalid bonus state')];
        }

        if ($isFree) {
            $lines = (int) ($state['last_lines'] ?? $cfg->lineCount());
            $betLine = (float) ($state['last_bet'] ?? 0);
            $betIndex = (int) ($state['last_bet_index'] ?? 0);
            $state['free_left'] = (int) $state['free_left'] - 1;
            $stake = 0.0;
        } else {
            $lines = max(1, (int) ($parts[1] ?? $cfg->lineCount()));
            $betIndex = max(0, (int) ($parts[2] ?? 0));
            $bets = $ctx->betOptions();
            if (! isset($bets[$betIndex])) {
                return [$this->fmt->error('A/u251', 'invalid bet/lines')];
            }
            $betLine = (float) $bets[$betIndex];
            $stake = round($betLine * $lines * $denom, 4);
            if ($ctx->balance() < $stake) {
                return [$this->fmt->error('A/u251', 'invalid balance')];
            }
            $ctx->placeBet($stake);
            $state = [
                'last_bet' => $betLine,
                'last_lines' => $lines,
                'last_bet_index' => $betIndex,
                'frozen_balance' => $ctx->balance(),   // post-stake, pre-win
                'bonus_win' => 0.0,
                'total_win' => 0.0,
                'free_total' => 0,
                'free_left' => 0,
            ];
        }

        $mult = $isFree ? max(1, $cfg->freeSpinsMultiplier()) : 1;
        $result = $this->engine->spin($ctx, max($stake, $betLine * $denom * $lines), $lines, $betLine * $denom, $isFree);
        $result->win = min(round($result->win * $mult, 4), $ctx->maxWin($betLine * $denom * $lines));

        if ($result->win > 0) {
            $ctx->awardWin($result->win);
        }

        $scatter = $cfg->scatterSymbol();
        $scatterCount = (int) ($result->extra['scatters'][$scatter] ?? 0);
        if ($scatterCount >= 3 && $cfg->hasFreeSpins()) {
            $grant = $cfg->freeSpinsFor($scatterCount);
            $state['free_left'] = (int) ($state['free_left'] ?? 0) + $grant;
            $state['free_total'] = (int) ($state['free_total'] ?? 0) + $grant;
        }

        if ($isFree) {
            $state['bonus_win'] = round((float) ($state['bonus_win'] ?? 0) + $result->win, 4);
        }
        $state['total_win'] = $isFree ? $state['bonus_win'] : $result->win;
        $state['gamble_amount'] = $isFree ? 0.0 : $result->win;

        $packet = $this->fmt->spin($ctx, $result, $state, $isFree);
        $state['rp'] = $packet['rp'];
        $state['double_answer'] = $packet['double_answer'];
        $state['win_hex'] = $packet['win_hex'];

        $ctx->statePut(['features' => $state]);
        $ctx->recordRound(
            new SpinResult(bet: $isFree ? 0.0 : $stake, win: $result->win, state: $isFree ? 'freespin' : 'bet'),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => ['totalWin' => $result->win]]),
        );

        return [$packet['frame']];
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
