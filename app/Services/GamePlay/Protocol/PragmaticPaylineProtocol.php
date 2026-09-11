<?php

namespace App\Services\GamePlay\Protocol;

use App\Enums\ClientProtocol;
use App\Services\GamePlay\Engine\PaylineEngine;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * The modern Pragmatic Play "gs2c" classic-payline wire protocol — see
 * {@see PragmaticPaylineFormatter} for the reply shapes and
 * {@see ClientProtocol::PragmaticPayline} for the transport. All
 * spin/payline/coin-value math is {@see PaylineEngine}; nothing here is
 * game-specific.
 */
class PragmaticPaylineProtocol
{
    public function __construct(
        private readonly PaylineEngine $engine,
        private readonly PragmaticPaylineFormatter $formatter,
    ) {}

    /** @param  array<string,mixed>  $req  the decoded POST body */
    public function dispatch(GameContext $ctx, array $req): string
    {
        $action = (string) ($req['action'] ?? '');

        try {
            return match ($action) {
                'doInit' => $this->doInit($ctx),
                'doSpin' => $this->doSpin($ctx, $req),
                'doMysteryScatter' => $this->doMysteryScatter($ctx),
                'update' => $this->formatter->balanceOnly($ctx),
                'doCollect', 'doCollectBonus' => $this->formatter->balanceOnly($ctx),
                default => $this->formatter->error($action, 'unknown action'),
            };
        } catch (\Throwable $e) {
            report($e);

            return $this->formatter->error($action, $e->getMessage());
        }
    }

    private function doInit(GameContext $ctx): string
    {
        $cfg = $ctx->config();
        $board = $this->engine->freshBoard($cfg, false);

        return $this->formatter->init($ctx, $cfg, $board);
    }

    private function doSpin(GameContext $ctx, array $req): string
    {
        $cfg = $ctx->config();
        $denom = $cfg->denomination();
        $lines = $cfg->lineCount();
        $betline = (float) ($req['c'] ?? 0);
        $index = (int) ($req['index'] ?? 1);
        $counter = (int) ($req['counter'] ?? 1);

        $prevState = $ctx->stateGet('payline');
        $inFreeSpins = $prevState !== null && ($prevState['FreeState'] ?? null) === 'FreeSpin';

        $stake = 0.0;
        if (! $inFreeSpins) {
            if ($betline <= 0) {
                return $this->formatter->error('doSpin', 'invalid bet state');
            }
            $betline *= $denom;
            $stake = round($betline * $lines, 4);
            if ($ctx->balance() < $stake) {
                return $this->formatter->error('doSpin', 'invalid balance');
            }
            $ctx->placeBet($stake);
        } else {
            $betline = (float) ($prevState['LastBet'] ?? $betline * $denom);
            $lines = (int) ($prevState['LastLines'] ?? $lines);
        }

        $state = $this->engine->step($cfg, $prevState, $betline, $lines);

        $win = min((float) $state['Win'], $ctx->maxWin($stake > 0 ? $stake : $betline * $lines));
        if ($win > 0) {
            $ctx->awardWin($win);
        }

        $ctx->statePut(['payline' => $state]);

        $body = $this->formatter->spin($ctx, $cfg, $state, $betline, $lines, $index, $counter);

        $ctx->recordRound(
            new SpinResult(bet: $stake, win: $win, state: $inFreeSpins ? 'freespin' : 'bet'),
            json_encode(['action' => 'doSpin', 'body' => $body]),
        );

        return $body;
    }

    private function doMysteryScatter(GameContext $ctx): string
    {
        $state = $this->engine->mysteryScatter($ctx->config());
        $ctx->statePut(['payline' => $state]);

        return $this->formatter->mysteryScatter($state);
    }
}
