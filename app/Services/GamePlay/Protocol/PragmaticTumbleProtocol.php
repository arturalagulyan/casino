<?php

namespace App\Services\GamePlay\Protocol;

use App\Enums\ClientProtocol;
use App\Services\GamePlay\Engine\TumbleEngine;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * The modern Pragmatic Play "gs2c" HTTP wire protocol — see
 * {@see PragmaticTumbleFormatter} for the reply shapes and
 * {@see ClientProtocol::PragmaticTumble} for the transport. All
 * spin/tumble/win math is {@see TumbleEngine}; nothing here is game-specific.
 */
class PragmaticTumbleProtocol
{
    public function __construct(
        private readonly TumbleEngine $engine,
        private readonly PragmaticTumbleFormatter $formatter,
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
        $board = $this->engine->freshBoard($cfg);

        return $this->formatter->init($ctx, $cfg, $board);
    }

    private function doSpin(GameContext $ctx, array $req): string
    {
        $cfg = $ctx->config();
        $denom = $cfg->denomination();
        $lines = $cfg->tumbleConfig()['lines'];
        $betline = (float) ($req['c'] ?? 0);
        $index = (int) ($req['index'] ?? 1);
        $counter = (int) ($req['counter'] ?? 1);

        $prevState = $ctx->stateGet('tumble');
        $midTumble = in_array($prevState['State'] ?? null, ['Respin', 'FirstRespin'], true);
        $inFreeSpins = $prevState !== null
            && array_key_exists('FreeSpinNumber', $prevState)
            && ($prevState['FreeState'] ?? null) !== 'LastFreeSpin';
        $needsBet = ! $midTumble && ! $inFreeSpins;

        $stake = 0.0;
        if ($needsBet) {
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
            // Continuing a tumble or mid-free-spins — the betline that
            // started this round, not whatever the client happens to send.
            $betline = (float) ($prevState['LastBet'] ?? $betline * $denom);
        }

        $state = $this->engine->step($cfg, $prevState, $betline);
        $state['LastBet'] = $betline;

        $win = min((float) $state['Win'], $ctx->maxWin($stake > 0 ? $stake : $betline * $lines));
        if ($win > 0) {
            $ctx->awardWin($win);
        }
        if (($state['MultiplierExtra'] ?? 0) > 0) {
            $ctx->awardWin((float) $state['MultiplierExtra']);
        }

        $ctx->statePut(['tumble' => $state]);

        $body = $this->formatter->spin($ctx, $cfg, $state, $betline, $index, $counter);

        $ctx->recordRound(
            new SpinResult(bet: $stake, win: $win + (float) ($state['MultiplierExtra'] ?? 0), state: $inFreeSpins ? 'freespin' : 'bet'),
            json_encode(['action' => 'doSpin', 'body' => $body]),
        );

        return $body;
    }
}
