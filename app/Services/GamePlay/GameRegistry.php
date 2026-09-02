<?php

namespace App\Services\GamePlay;

use App\Enums\GameEngine;
use App\Models\Game;
use App\Models\GameTemplate;
use App\Services\GamePlay\Contracts\GameServer;
use App\Services\GamePlay\Engine\LineSlotServer;
use RuntimeException;

/**
 * Resolves an internal game to the class that runs its HTTP command loop.
 *
 * Almost everything lands on {@see LineSlotServer} — the universal, fully
 * DB-driven engine (reels, paytable, paylines, symbols, bonus/gamble rules,
 * RTP tables all from GameConfig). There is no per-game code.
 *
 * WebSocket games (EGT GamePlatform, …) don't come through here at all — they
 * are handled by the App\Services\GamePlay\Client protocol adapters.
 */
class GameRegistry
{
    /**
     * Optional explicit overrides, template code => server class. Only needed
     * for a genuinely novel mechanic the generic engine can't express.
     *
     * @var array<string, class-string<GameServer>>
     */
    private array $map = [];

    /** @param class-string<GameServer> $serverClass */
    public function register(string $code, string $serverClass): void
    {
        $this->map[$code] = $serverClass;
    }

    public function for(Game $game): GameServer
    {
        $template = $game->relationLoaded('template') ? $game->template : $game->template()->firstOrFail();

        return $this->resolve($template);
    }

    public function resolve(GameTemplate $template): GameServer
    {
        if ($template->engine === GameEngine::Seamless) {
            throw new RuntimeException('Seamless games are served by the remote provider, not this engine.');
        }

        $override = $this->map[$template->code] ?? null;

        return $override && class_exists($override)
            ? app($override)
            : app(LineSlotServer::class);
    }

    /** True when a registered override handles this game, not the generic engine. */
    public function isNative(GameTemplate $template): bool
    {
        return ! ($this->resolve($template) instanceof LineSlotServer);
    }
}
