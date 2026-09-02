<?php

namespace App\Services\GamePlay\Engine;

use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * HTTP command loop for standard-protocol slots (the demo shell + generic
 * bundles). Thin wrapper: {@see SlotEngine} does the reel + win math, this adds
 * the free-spin / bonus-trigger / gamble bookkeeping and the JSON shape.
 *
 * There is no per-game code — everything comes from GameConfig. EGT
 * WebSocket games use an App\Services\GamePlay\Client protocol adapter instead.
 */
class LineSlotServer extends AbstractSlotServer
{
    public function __construct(private readonly SlotEngine $engine) {}

    public function config(GameContext $context): array
    {
        return $context->config()->toClientArray();
    }

    protected function spin(GameContext $context, float $stake, int $lines, float $betline): SpinResult
    {
        $cfg = $context->config();
        $freeLeft = (int) $context->stateGet('free_spins_left', 0);
        $isFree = $freeLeft > 0;

        $result = $this->engine->spin($context, $stake, $lines, $betline, $isFree);
        $scatters = $result->extra['scatters'] ?? [];

        if ($isFree) {
            $mult = max(1, $cfg->freeSpinsMultiplier());
            $result->win = round($result->win * $mult, 4);
            $context->statePut(['free_spins_left' => --$freeLeft]);
            $result->state = 'freespin';
            $result->extra['free_spins_left'] = $freeLeft;
            $result->extra['multiplier'] = $mult;
        } elseif ($cfg->hasFreeSpins() && $cfg->scatterSymbol() !== null) {
            $count = (int) ($scatters[$cfg->scatterSymbol()] ?? 0);
            if ($count >= 3) {
                $granted = $cfg->freeSpinsFor($count);
                $context->statePut(['free_spins_left' => $granted]);
                $result->extra['free_spins_awarded'] = $granted;
                $result->state = 'bonus';
            }
        }

        $context->statePut(['gamble_amount' => $result->win]);

        return $result;
    }

    public function handle(GameContext $context, array $request): array
    {
        if (($request['command'] ?? null) === 'gamble' && $context->config()->hasGamble()) {
            return $this->gamble($context, $request);
        }

        return parent::handle($context, $request);
    }

    private function gamble(GameContext $context, array $request): array
    {
        $amount = (float) ($request['amount'] ?? $context->stateGet('gamble_amount', 0));

        if ($amount <= 0) {
            return ['command' => 'gamble', 'error' => 'Nothing to gamble.', 'balance' => round($context->balance(), 4)];
        }

        $g = $this->engine->gamble($context, $amount, isset($request['guess']) ? (int) $request['guess'] : null);

        if ($g['won']) {
            $context->awardWin($amount);
            $context->statePut(['gamble_amount' => $amount * 2]);
        } else {
            $context->clawback($amount);
            $context->statePut(['gamble_amount' => 0]);
        }

        $context->recordRound(
            new SpinResult(bet: $g['won'] ? 0.0 : $amount, win: $g['won'] ? $amount * 2 : 0.0, state: 'gamble'),
            json_encode(['gamble' => $g['won'] ? 'won' : 'lost', 'amount' => $amount]),
        );

        return [
            'command' => 'gamble',
            'won' => $g['won'],
            'amount' => round($g['after'], 4),
            'balance' => round($context->balance(), 4),
        ];
    }
}
