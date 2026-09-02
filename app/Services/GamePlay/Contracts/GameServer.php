<?php

namespace App\Services\GamePlay\Contracts;

use App\Services\GamePlay\GameContext;

/**
 * A game's server side. One instance handles every command for one running
 * game (init / bet / state / …) and returns a JSON-able array.
 *
 * Real provider games implement this directly (porting their reel + win math);
 * anything with engine = internal and no dedicated class falls back to the
 * fully DB-driven App\Services\GamePlay\Engine\LineSlotServer.
 */
interface GameServer
{
    /**
     * @param  array<string, mixed>  $request  decoded command payload (needs a `command` key)
     * @return array<string, mixed>
     */
    public function handle(GameContext $context, array $request): array;
}
