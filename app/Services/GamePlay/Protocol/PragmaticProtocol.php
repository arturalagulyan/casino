<?php

namespace App\Services\GamePlay\Protocol;

use App\Enums\ClientProtocol;
use App\Http\Controllers\Api\GameServerController;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * The real Pragmatic Play HTTP wire protocol — see {@see PragmaticFormatter}
 * for the reply shapes and {@see ClientProtocol::Pragmatic} for
 * the transport. All spin/win math is the generic {@see SlotEngine}; nothing
 * here is game-specific.
 *
 * Returns a raw plain-text body (query-string-shaped, occasionally JSON for
 * gamble/errors) — the caller ({@see GameServerController})
 * sends it back verbatim, never wrapped in a JSON envelope.
 */
class PragmaticProtocol
{
    public function __construct(
        private readonly SlotEngine $engine,
        private readonly PragmaticFormatter $formatter,
    ) {}

    /** @param  array<string,mixed>  $req  the decoded POST body */
    public function dispatch(GameContext $ctx, array $req): string
    {
        $action = (string) ($req['action'] ?? '');

        try {
            return match ($action) {
                'doInit' => $this->doInit($ctx),
                'doSpin' => $this->doSpin($ctx, $req),
                'update' => $this->formatter->balanceOnly($ctx),
                'slotGamble' => $this->slotGamble($ctx, $req),
                'doCollect', 'doCollectBonus' => $this->formatter->balanceOnly($ctx),
                // Per-title bonus mechanics (pick-a-prize wheel, mystery
                // scatter respin, card-draw gamble) — not ported generically.
                'doBonus', 'doMysteryScatter', 'gamble5GetUserCards', 'gamble5GetDealerCard' => $this->formatter->balanceOnly($ctx),
                default => $this->formatter->error($action, 'unknown action'),
            };
        } catch (\Throwable $e) {
            report($e);

            return $this->formatter->error($action, $e->getMessage());
        }
    }

    // ---- init -------------------------------------------------------

    private function doInit(GameContext $ctx): string
    {
        $cfg = $ctx->config();
        $free = (int) $ctx->stateGet('free_spins_left', 0) > 0;
        $board = $this->engine->spinReels($cfg, $free, false, $offsets);

        return $this->formatter->init($ctx, $board, $offsets);
    }

    // ---- spin -----------------------------------------------------

    private function doSpin(GameContext $ctx, array $req): string
    {
        $cfg = $ctx->config();
        $denom = $cfg->denomination();
        $lines = $cfg->lineCount();
        $betline = (float) ($req['c'] ?? 0);

        $state = $ctx->stateGet('features', []);
        $freeLeft = (int) ($state['free_spins_left'] ?? 0);
        $isFree = $freeLeft > 0;

        if ($isFree) {
            $betline = (float) ($state['last_betline'] ?? $betline);
            $state['free_spins_used'] = (int) ($state['free_spins_used'] ?? 0) + 1;
            $state['free_spins_left'] = $freeLeft - 1;
            $stake = 0.0;
        } else {
            if ($betline <= 0) {
                return $this->formatter->error('doSpin', 'invalid bet state');
            }
            $stake = round($betline * $lines * $denom, 4);
            if ($ctx->balance() < $stake) {
                return $this->formatter->error('doSpin', 'invalid balance');
            }
            $ctx->placeBet($stake);
            $state = ['last_betline' => $betline, 'free_spins_left' => 0, 'free_spins_total' => 0, 'free_spins_used' => 0];
        }

        $result = $this->engine->spin($ctx, max($stake, $betline * $lines * $denom), $lines, $betline * $denom, $isFree);
        $result->win = min($result->win, $ctx->maxWin($betline * $lines * $denom));

        if ($result->win > 0) {
            $ctx->awardWin($result->win);
        }

        $scatter = $cfg->scatterSymbol();
        $scatterCount = (int) ($result->extra['scatters'][$scatter] ?? 0);
        if ($scatterCount >= 3 && $cfg->hasFreeSpins()) {
            $grant = $cfg->freeSpinsFor($scatterCount);
            $state['free_spins_left'] = (int) ($state['free_spins_left'] ?? 0) + $grant;
            $state['free_spins_total'] = (int) ($state['free_spins_total'] ?? 0) + $grant;
        }

        $ctx->statePut(['features' => $state]);

        $body = $this->formatter->spin(
            $ctx, $result, $result->extra['reel_offsets'] ?? [], $isFree,
            (int) ($state['free_spins_total'] ?? 0),
            (int) ($state['free_spins_used'] ?? 0),
        );

        $ctx->recordRound(
            new SpinResult(bet: $isFree ? 0.0 : $stake, win: $result->win, state: $isFree ? 'freespin' : 'bet'),
            json_encode(['action' => 'doSpin', 'body' => $body]),
        );

        return $body;
    }

    // ---- gamble -----------------------------------------------------

    private function slotGamble(GameContext $ctx, array $req): string
    {
        $state = $ctx->stateGet('features', []);
        $amount = (float) ($state['gamble_amount'] ?? 0);

        if ($amount <= 0 || $ctx->balance() < $amount) {
            return $this->formatter->error('slotGamble', 'invalid gamble state');
        }

        $choice = (string) ($req['gambleChoice'] ?? 'red');
        $g = $this->engine->gamble($ctx, $amount, in_array($choice, ['red', '1'], true) ? 1 : 0);

        $before = $ctx->balance();
        if ($g['won']) {
            $ctx->awardWin($amount);
            $state['gamble_amount'] = $amount * 2;
        } else {
            $ctx->clawback($amount);
            $state['gamble_amount'] = 0.0;
        }
        $ctx->statePut(['features' => $state]);

        $ctx->recordRound(
            new SpinResult(bet: $g['won'] ? 0.0 : $amount, win: $g['won'] ? $amount * 2 : 0.0, state: 'gamble'),
            json_encode(['action' => 'slotGamble', 'won' => $g['won']]),
        );

        $red = in_array($choice, ['red', '1'], true);
        $card = $g['won'] ? ($red ? 'D' : 'C') : ($red ? 'C' : 'D');

        return $this->formatter->gamble($ctx, ['won' => $g['won'], 'before' => $before, 'after' => $g['after'], 'card' => $card]);
    }
}
