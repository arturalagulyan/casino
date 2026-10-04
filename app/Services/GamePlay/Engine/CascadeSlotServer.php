<?php

namespace App\Services\GamePlay\Engine;

use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\GameRegistry;
use App\Services\GamePlay\SpinResult;

/**
 * Standard-protocol command loop for cascade (scatter-pays + tumble) games —
 * the RoyalSpin "Candy Royale" family. {@see CascadeEngine} does the math; this
 * adds the free-spin bookkeeping (trigger, retrigger, countdown) on top of the
 * shared {@see AbstractSlotServer} bet / stake / settlement flow.
 *
 * Picked by {@see GameRegistry} for any standard-protocol template that
 * carries a `tumble_config`. The bet is always the fixed `lines` multiplier
 * (cascade games have no paylines to choose).
 */
class CascadeSlotServer extends AbstractSlotServer
{
    public function __construct(private readonly CascadeEngine $engine) {}

    public function config(GameContext $context): array
    {
        $cfg = $context->config();
        $cc = $cfg->cascadeConfig();

        return ['paylines' => [], 'cascade' => $cc] + $cfg->toClientArray();
    }

    public function handle(GameContext $context, array $request): array
    {
        if (in_array($request['command'] ?? null, ['bet', 'spin'], true)) {
            $request['lines'] = $context->config()->cascadeConfig()['lines'];
        }

        return parent::handle($context, $request);
    }

    protected function spin(GameContext $context, float $stake, int $lines, float $betline): SpinResult
    {
        $cc = $context->config()->cascadeConfig();
        $freeLeft = (int) $context->stateGet('free_spins_left', 0);
        $isFree = $freeLeft > 0;

        $result = $this->engine->spin($context, $stake, $betline, $isFree);
        $scatters = (int) ($result->extra['scatters'] ?? 0);

        if ($isFree) {
            $freeLeft--;
            if ($scatters >= $cc['retrigger']) {
                $freeLeft += $cc['retrigger_spins'];
                $result->extra['free_spins_awarded'] = $cc['retrigger_spins'];
            }
            $context->statePut(['free_spins_left' => $freeLeft]);
            $result->state = 'freespin';
            $result->extra['free_spins_left'] = $freeLeft;
        } elseif ($scatters >= $cc['trigger']) {
            $context->statePut(['free_spins_left' => $cc['free_spins']]);
            $result->extra['free_spins_awarded'] = $cc['free_spins'];
            $result->extra['free_spins_left'] = $cc['free_spins'];
            $result->state = 'bonus';
        }

        return $result;
    }
}
