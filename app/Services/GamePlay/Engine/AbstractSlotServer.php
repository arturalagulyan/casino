<?php

namespace App\Services\GamePlay\Engine;

use App\Services\GamePlay\Contracts\GameServer;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;
use RuntimeException;

/**
 * Shared command loop for line-based slots. Ports the common parts of the
 * legacy per-game Server.php (login / settings / bet / state) so a concrete
 * game only supplies its paytable (config()) and its spin math (spin()).
 */
abstract class AbstractSlotServer implements GameServer
{
    /** Description of the game for the client: symbols, reels, rows, paylines, paytable. */
    abstract public function config(GameContext $context): array;

    /** Produce one spin outcome. The stake is already taken; return the win. */
    abstract protected function spin(GameContext $context, float $stake, int $lines, float $betline): SpinResult;

    public function handle(GameContext $context, array $request): array
    {
        $command = (string) ($request['command'] ?? 'init');

        return match ($command) {
            'init', 'login', 'settings' => $this->init($context),
            'state' => $this->state($context),
            'bet', 'spin' => $this->bet($context, $request),
            'ping' => ['command' => 'ping', 'ok' => true],
            default => throw new RuntimeException("Unknown command [{$command}]."),
        };
    }

    protected function init(GameContext $context): array
    {
        return [
            'command' => 'init',
            'game' => [
                'code' => $context->game->template->code,
                'title' => $context->game->title ?? $context->game->template->title,
            ],
            'config' => $this->config($context),
            'bet_options' => $context->betOptions(),
            'denomination' => $context->denomination(),
            'currency' => $context->currency->value,
            'balance' => round($context->balance(), 4),
            'jackpots' => $context->jackpots()
                ->map(fn ($j) => ['id' => $j->id, 'name' => $j->name, 'balance' => (float) $j->balance])
                ->values(),
            'state' => $context->session()->state,
        ];
    }

    protected function state(GameContext $context): array
    {
        return [
            'command' => 'state',
            'balance' => round($context->balance(), 4),
            'state' => $context->session()->state,
        ];
    }

    protected function bet(GameContext $context, array $request): array
    {
        $lines = max(1, (int) ($request['lines'] ?? count($this->config($context)['paylines'] ?? []) ?: 1));
        $denom = $context->denomination();
        $freeSpin = (int) $context->stateGet('free_spins_left', 0) > 0;

        // A free spin reuses the bet that triggered it and costs nothing.
        $betline = $freeSpin
            ? (float) $context->stateGet('free_spins_betline', $request['bet'] ?? 0)
            : (float) ($request['bet'] ?? $request['betline'] ?? 0);
        $stake = round($lines * $betline * $denom, 4);

        if ($betline <= 0 || $stake <= 0) {
            throw new RuntimeException('Invalid bet.');
        }

        if (! $freeSpin) {
            if ($context->balance() < $stake) {
                throw new RuntimeException('Insufficient balance.');
            }

            $context->placeBet($stake);
            $context->statePut(['free_spins_betline' => $betline]);
        }

        $result = $this->spin($context, $stake, $lines, $betline * $denom);

        if ($freeSpin) {
            $result->bet = 0.0;   // the round is free — nothing wagered
        }

        // Never pay more than the shop's single-win cap.
        $result->win = min($result->win, $context->maxWin($stake));

        if ($result->win > 0) {
            $context->awardWin($result->win);
        }

        $payload = json_encode(['command' => 'bet', 'in' => $request, 'out' => $result->toArray()]);
        $round = $context->recordRound($result, $payload);

        return [
            'command' => 'bet',
            'round_id' => $round->id,
            'bet' => $result->bet,
            'win' => $result->win,
            'reels' => $result->reels,
            'lines' => $result->lines,
            'state' => $result->state,
            'balance' => round($context->balance(), 4),
        ] + $result->extra;
    }
}
