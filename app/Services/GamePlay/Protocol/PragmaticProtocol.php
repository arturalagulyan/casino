<?php

namespace App\Services\GamePlay\Protocol;

use App\Enums\ClientProtocol;
use App\Http\Controllers\Api\GameServerController;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * The legacy Pragmatic Play HTTP wire protocol — see {@see PragmaticFormatter}
 * for the frame shapes and {@see ClientProtocol::Pragmatic} for the
 * transport. All spin/win math is the generic {@see SlotEngine}; nothing here
 * is game-specific.
 *
 * Returns a raw `3:::{…}------3:::{…}` text body (not JSON) — the caller
 * ({@see GameServerController}) must send it back
 * verbatim, not wrapped in a JSON envelope.
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
        try {
            $frames = match (true) {
                isset($req['spinType']) => $this->spin($ctx, $req),
                isset($req['index']) => [],   // per-title pick-a-prize bonus wheel — not ported generically
                isset($req['umid'], $req['ID']) => $this->formatter->housekeeping($ctx, (int) $req['ID']) ?? [],
                isset($req['umid']) => $this->formatter->housekeeping($ctx, (int) $req['umid']) ?? [],
                isset($req['ID']) => $this->formatter->genericAck($ctx),
                default => [],
            };
        } catch (\Throwable $e) {
            report($e);
            $frames = [];
        }

        return implode('------', $frames);
    }

    // ---- spin -----------------------------------------------------

    private function spin(GameContext $ctx, array $req): array
    {
        $cfg = $ctx->config();
        $denom = $cfg->denomination();
        $isFree = ($req['spinType'] ?? '') === 'free';

        $flags = array_values((array) ($req['lines'] ?? []));
        $lines = 0;
        foreach ($flags as $flag) {
            if ((float) $flag <= 0) {
                break;
            }
            $lines++;
        }
        $lines = max(1, min($lines ?: $cfg->lineCount(), $cfg->lineCount()));

        $state = $ctx->stateGet('features', []);
        $stakeCredits = (float) ($req['bet'] ?? 0) / 100;

        if ($isFree) {
            if ((int) ($state['free_spins_left'] ?? 0) <= 0) {
                return $this->formatter->genericAck($ctx);
            }
            $lines = (int) ($state['last_lines'] ?? $lines);
            $stakeCredits = (float) ($state['last_bet_credits'] ?? $stakeCredits);
            $state['free_spins_used'] = (int) ($state['free_spins_used'] ?? 0) + 1;
            $state['free_spins_left'] = (int) $state['free_spins_left'] - 1;
            $stake = 0.0;
        } else {
            if ($stakeCredits <= 0) {
                return $this->formatter->genericAck($ctx);
            }
            $stake = round($stakeCredits * $denom, 4);
            if ($ctx->balance() < $stake) {
                return $this->formatter->genericAck($ctx);
            }
            $ctx->placeBet($stake);
            $state = [
                'last_bet_credits' => $stakeCredits,
                'last_lines' => $lines,
                'bonus_win' => 0.0,
                'free_spins_left' => 0,
                'free_spins_total' => 0,
                'free_spins_used' => 0,
            ];
        }

        $betline = round($stakeCredits * $denom / $lines, 6);
        $result = $this->engine->spin($ctx, max($stake, $betline * $lines), $lines, $betline, $isFree);
        $result->win = min($result->win, $ctx->maxWin($betline * $lines));

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

        if ($isFree) {
            $state['bonus_win'] = round((float) ($state['bonus_win'] ?? 0) + $result->win, 4);
        }

        $ctx->statePut(['features' => $state]);

        $frames = $this->formatter->spin($ctx, $result, $result->extra['reel_offsets'] ?? []);

        $ctx->recordRound(
            new SpinResult(bet: $isFree ? 0.0 : $stake, win: $result->win, state: $isFree ? 'freespin' : 'bet'),
            json_encode(['responseEvent' => 'spin', 'frames' => $frames]),
        );

        return $frames;
    }
}
