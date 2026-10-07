<?php

namespace App\Services\GamePlay\Engine;

use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\GameRegistry;
use App\Services\GamePlay\SpinResult;
use RuntimeException;

/**
 * Standard-protocol command loop for cascade (scatter-pays + tumble) games —
 * the RoyalSpin "Candy Royale" / "Olympus" family. {@see CascadeEngine} does
 * the math; this adds the free-spin bookkeeping (trigger, retrigger,
 * countdown, the running total multiplier) and the two optional side bets on
 * top of the shared {@see AbstractSlotServer} bet / stake / settlement flow:
 *
 *   ante: true  stake × `ante_bet`, feature `ante_bonus_factor` × likelier
 *   buy:  true  stake × `buy_feature`, the round always triggers free spins
 *
 * Picked by {@see GameRegistry} for any standard-protocol template that
 * carries a `tumble_config`. The bet is always the fixed `lines` multiplier
 * (cascade games have no paylines to choose).
 */
class CascadeSlotServer extends AbstractSlotServer
{
    /** The bet request being handled (side-bet flags for spin()). */
    private array $request = [];

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
            $this->request = $request;
        }

        return parent::handle($context, $request);
    }

    protected function stakeFactor(GameContext $context, array $request): float
    {
        $cc = $context->config()->cascadeConfig();

        if (! empty($request['buy'])) {
            if ($cc['buy_feature'] <= 0) {
                throw new RuntimeException('This game has no feature buy.');
            }

            return $cc['buy_feature'];
        }
        if (! empty($request['ante'])) {
            if ($cc['ante_bet'] <= 0) {
                throw new RuntimeException('This game has no ante bet.');
            }

            return $cc['ante_bet'];
        }

        return 1.0;
    }

    protected function spin(GameContext $context, float $stake, int $lines, float $betline): SpinResult
    {
        $cc = $context->config()->cascadeConfig();
        $freeLeft = (int) $context->stateGet('free_spins_left', 0);
        $isFree = $freeLeft > 0;

        $result = $this->engine->spin($context, $stake, $betline, $isFree, [
            'ante' => ! $isFree && ! empty($this->request['ante']),
            'buy' => ! $isFree && ! empty($this->request['buy']),
            'total_multiplier' => $isFree ? (int) $context->stateGet('free_spins_multiplier', 0) : 0,
        ]);
        $scatters = (int) ($result->extra['scatters'] ?? 0);

        if ($isFree) {
            $freeLeft--;
            if ($scatters >= $cc['retrigger']) {
                $freeLeft += $cc['retrigger_spins'];
                $result->extra['free_spins_awarded'] = $cc['retrigger_spins'];
            }
            $context->statePut([
                'free_spins_left' => $freeLeft,
                'free_spins_multiplier' => $freeLeft > 0 ? (int) ($result->extra['total_multiplier'] ?? 0) : 0,
            ]);
            $result->state = 'freespin';
            $result->extra['free_spins_left'] = $freeLeft;
        } elseif ($scatters >= $cc['trigger']) {
            $context->statePut(['free_spins_left' => $cc['free_spins'], 'free_spins_multiplier' => 0]);
            $result->extra['free_spins_awarded'] = $cc['free_spins'];
            $result->extra['free_spins_left'] = $cc['free_spins'];
            $result->state = 'bonus';
        }

        if (! $cc['multiplier_accumulate']) {
            unset($result->extra['total_multiplier']);
        }

        return $result;
    }
}
