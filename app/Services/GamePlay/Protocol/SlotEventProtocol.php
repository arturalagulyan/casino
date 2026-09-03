<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;
use App\Services\Legacy\LegacyGameReader;

/**
 * The legacy VanguardLTE `slotEvent` HTTP wire protocol — one handler shared by
 * every Novomatic / Greentube game (`js/core.js` front-end). Faithful to the
 * legacy per-game `Server.php`: `{slotEvent}` in, `{responseEvent, serverResponse}`
 * out. All spin/win math is the generic {@see SlotEngine}; nothing here is
 * game-specific (paytable, reels, symbols, features come from GameConfig +
 * {@see SlotEventFormatter}).
 *
 * Money contract mirrors {@see GamePlatformProtocol}: the `Balance` in a spin
 * response is pre-win (post-stake); the client animates the win on top and
 * reconciles to `afterBalance`.
 */
class SlotEventProtocol
{
    public function __construct(
        private readonly SlotEngine $engine,
        private readonly SlotEventFormatter $formatter,
        private readonly LegacyGameReader $legacy,
    ) {}

    /**
     * @param  array<string,mixed>  $req  the decoded POST body
     * @return array<string,mixed> the full `{responseEvent, serverResponse, …}` frame
     */
    public function dispatch(GameContext $ctx, array $req): array
    {
        $event = (string) ($req['slotEvent'] ?? '');

        try {
            return match ($event) {
                'getSettings' => $this->getSettings($ctx),
                'bet', 'freespin', 'respin' => $this->bet($ctx, $req, $event === 'freespin' ? 'freespin' : 'bet'),
                'slotGamble' => $this->gamble($ctx, $req),
                'update' => $this->error('update', (string) $this->formatter->settings($ctx, [])['Balance']),
                default => $this->error($event, 'unknown slot event'),
            };
        } catch (\Throwable $e) {
            report($e);

            return $this->error($event, $e->getMessage());
        }
    }

    // ---- getSettings ----------------------------------------------

    private function getSettings(GameContext $ctx): array
    {
        $state = $ctx->stateGet('features', []);

        $language = $this->legacy->language($ctx->game->template->code);

        return [
            'responseEvent' => 'getSettings',
            'responseType' => 'getSettings',
            'slotLanguage' => $language ?: (object) [],
            'serverResponse' => $this->formatter->settings($ctx, $state),
        ];
    }

    // ---- the spin -----------------------------------------------

    private function bet(GameContext $ctx, array $req, string $mode): array
    {
        $cfg = $ctx->config();
        $denom = $cfg->denomination();
        $lines = min(max(1, (int) ($req['slotLines'] ?? $cfg->lineCount())), max(1, $cfg->lineCount()));
        $betline = (float) ($req['slotBet'] ?? 0);
        $isFree = $mode === 'freespin';

        if (! $isFree && $betline <= 0) {
            return $this->error($mode, 'invalid bet state');
        }

        $state = $ctx->stateGet('features', []);

        if ($isFree) {
            if ((int) ($state['free_spins_left'] ?? 0) <= 0) {
                return $this->error('freespin', 'invalid bonus state');
            }
            $lines = (int) ($state['last_lines'] ?? $lines);
            $betline = (float) ($state['last_bet'] ?? $betline);
            $state['free_spins_used'] = (int) ($state['free_spins_used'] ?? 0) + 1;
            $state['free_spins_left'] = (int) $state['free_spins_left'] - 1;
            $stake = 0.0;
        } else {
            $stake = round($lines * $betline * $denom, 4);
            if ($ctx->balance() < $stake) {
                return $this->error('bet', 'invalid balance');
            }
            $ctx->placeBet($stake);
            $state = [
                'last_bet' => $betline,
                'last_lines' => $lines,
                'stake' => $stake,
                'bonus_win' => 0.0,
                'total_win' => 0.0,
                'free_spins_left' => 0,
                'free_spins_total' => 0,
                'free_spins_used' => 0,
                'frozen_balance' => $ctx->balance(),   // post-stake, pre-win
            ];
        }

        $mult = $isFree ? max(1, $cfg->freeSpinsMultiplier()) : 1;
        $result = $this->engine->spin($ctx, max($stake, $betline * $denom * $lines), $lines, $betline * $denom, $isFree);
        $result->win = round($result->win * $mult, 4);
        $result->win = min($result->win, $ctx->maxWin($betline * $denom * $lines));

        if ($result->win > 0) {
            $ctx->awardWin($result->win);
        }

        // Scatter → free spins.
        $scatter = $cfg->scatterSymbol();
        $scatterCount = (int) (($result->extra['scatters'][$scatter] ?? 0));
        if ($scatterCount >= 3 && $cfg->hasFreeSpins()) {
            $grant = $cfg->freeSpinsFor($scatterCount);
            $state['free_spins_left'] = (int) ($state['free_spins_left'] ?? 0) + $grant;
            $state['free_spins_total'] = (int) ($state['free_spins_total'] ?? 0) + $grant;
        }

        if ($isFree) {
            $state['bonus_win'] = round((float) ($state['bonus_win'] ?? 0) + $result->win, 4);
        }
        $state['total_win'] = $isFree ? $state['bonus_win'] : $result->win;
        $state['gamble_amount'] = $isFree ? 0.0 : $result->win;

        $ctx->statePut(['features' => $state]);

        $serverResponse = $this->formatter->spin($ctx, $result, $state, $mode);

        $round = $ctx->recordRound(
            new SpinResult(bet: $isFree ? 0.0 : $stake, win: $result->win, state: $isFree ? 'freespin' : 'bet'),
            json_encode(['responseEvent' => 'spin', 'responseType' => $mode, 'serverResponse' => $serverResponse]),
        );

        return [
            'responseEvent' => 'spin',
            'responseType' => $mode,
            'roundId' => $round->id,
            'serverResponse' => $serverResponse,
        ];
    }

    // ---- gamble -------------------------------------------------

    private function gamble(GameContext $ctx, array $req): array
    {
        $state = $ctx->stateGet('features', []);
        $amount = (float) ($state['gamble_amount'] ?? 0);

        if ($amount <= 0 || $ctx->balance() < $amount) {
            return $this->error('slotGamble', 'invalid gamble state');
        }

        $choice = (string) ($req['gambleChoice'] ?? 'red');
        $g = $this->engine->gamble($ctx, $amount, in_array($choice, ['red', '1'], true) ? 1 : 0);

        if ($g['won']) {
            $ctx->awardWin($amount);       // credit the doubled half
            $state['gamble_amount'] = $amount * 2;
        } else {
            $ctx->clawback($amount);
            $state['gamble_amount'] = 0.0;
        }
        $ctx->statePut(['features' => $state]);

        $ctx->recordRound(
            new SpinResult(bet: $g['won'] ? 0.0 : $amount, win: $g['won'] ? $amount * 2 : 0.0, state: 'gamble'),
            json_encode(['responseEvent' => 'gambleResult', 'won' => $g['won'], 'amount' => $amount]),
        );

        return [
            'responseEvent' => 'gambleResult',
            'responseType' => 'slotGamble',
            'serverResponse' => $this->formatter->gamble($ctx, $g, $choice, $ctx->config()->denomination()),
        ];
    }

    // ---- helpers -----------------------------------------------

    private function error(string $type, string $message): array
    {
        return [
            'responseEvent' => 'error',
            'responseType' => $type,
            'serverResponse' => $message,
        ];
    }
}
