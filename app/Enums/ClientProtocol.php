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

    /**
     * Legacy VanguardLTE `slotEvent` HTTP protocol: `POST /game/{code}/server`
     * with `{slotEvent:getSettings|bet|freespin|slotGamble|…}` → `{responseEvent,
     * serverResponse}`. Novomatic / Greentube front-end engine (js/loader.js +
     * js/core.js, HTML shell synthesised at request time).
     */
    case SlotEvent = 'slot_event';

    /**
     * Legacy Amatic "amarent" protocol: a WebSocket (shared with GamePlatform,
     * behind App\Services\GamePlay\SocketServer) carrying `{"gameData":"A/uNNN,…"}`
     * frames and packed hex-string replies. Front-end bundle is `amarent/index.html`.
     */
    case Amatic = 'amatic';

    /**
     * Legacy Pragmatic Play HTTP protocol: `POST /game/{code}/server?sessionId=…`
     * with a JSON body (`umid`/`ID` housekeeping calls, or a spin's `spinType`/
     * `lines`/`bet`/`index`) → a Socket.IO-v0.9-flavoured plain-text body,
     * multiple `3:::{…}` frames joined by `------` (the legacy platform faked
     * Pragmatic's real GWT client transport over plain HTTP — no actual
     * WebSocket/socket.io server ever ran). Front-end bundle ships a "platform"
     * GWT app (chrome/login) that hosts a nested "bib" GWT app (the game).
     */
    case Pragmatic = 'pragmatic';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard (HTTP JSON)',
            self::GamePlatform => 'GamePlatform (WebSocket)',
            self::SlotEvent => 'slotEvent (legacy HTTP)',
            self::Amatic => 'Amatic amarent (WebSocket)',
            self::Pragmatic => 'Pragmatic Play (legacy HTTP)',
        };
    }

    public function usesWebSocket(): bool
    {
        return $this === self::GamePlatform || $this === self::Amatic;
    }
}
