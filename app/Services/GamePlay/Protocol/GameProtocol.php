<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\GameContext;

/**
 * A wire protocol a game front-end speaks over the socket.
 *
 * Legacy game bundles don't share one format — EGT does a login/settings/bet
 * handshake, Wazdan does setup/resume, Playtech uses socket.io framing, etc.
 * Each distinct format is one handler here, selected per game from its resolved
 * config (`client_protocol`, usually inherited from a category). Every handler
 * formats the same Engine\SlotEngine output — no per-game or per-provider code.
 */
interface GameProtocol
{
    /**
     * @param  array<string, mixed>  $request  one decoded client frame
     * @return list<array<string, mixed>> messages to frame back (one JSON object each)
     */
    public function dispatch(GameContext $context, array $request): array;
}
