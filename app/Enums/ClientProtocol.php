<?php

namespace App\Enums;

/**
 * The wire protocol a game's front-end bundle speaks to the server.
 *
 * A game picks one via its resolved config (`client_protocol`, normally
 * inherited from a category — e.g. the "Egt" category → GamePlatform). Each
 * value maps to one handler under App\Services\GamePlay\Protocol; there is no
 * per-provider code. Adding a legacy format (Wazdan, Playtech, …) = one new
 * case + one handler.
 */
enum ClientProtocol: string
{
    /** The rebuild's own JSON contract — POST /api/game/{code}/server (demo shell + generic bundles). */
    case Standard = 'standard';

    /** login / settings / subscribe / bet handshake over a raw `:::`-framed WebSocket (EGT-style bundles). */
    case GamePlatform = 'game_platform';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard (HTTP JSON)',
            self::GamePlatform => 'GamePlatform (WebSocket)',
        };
    }

    public function usesWebSocket(): bool
    {
        return $this === self::GamePlatform;
    }
}
