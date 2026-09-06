<?php

namespace App\Enums;

use App\Services\GamePlay\Engine\TumbleEngine;

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
     * Legacy Playtech HTTP protocol: `POST /game/{code}/server?sessionId=…`
     * with a JSON body (`umid`/`ID` housekeeping calls, or a spin's `spinType`/
     * `lines`/`bet`/`index`) → a Socket.IO-v0.9-flavoured plain-text body,
     * multiple `3:::{…}` frames joined by `------` (the legacy platform faked
     * Playtech's real GWT client transport over plain HTTP — no actual
     * WebSocket/socket.io server ever ran). Front-end bundle ships a "platform"
     * GWT app (chrome/login) that hosts a nested "bib" GWT app (the game).
     * NB: legacy game codes with this protocol end in `PT` — not to be
     * confused with real Pragmatic Play (`*PM`), a different legacy protocol.
     */
    case Playtech = 'playtech';

    /**
     * Real Pragmatic Play HTTP protocol: `POST /game/{code}/server?sessionId=…`
     * with a URL-encoded (not JSON) body (`action=doInit|doSpin|slotGamble|…`)
     * → a bespoke `key=value&key2=value2` plain-text body (their own HTML5
     * "gs2c" client wire format — a few sub-actions like gamble reply in JSON
     * instead). Front-end bundle is a self-contained root `index.html`; no
     * WebSocket. Legacy game codes end in `PM` (`*PMM` = mobile).
     */
    case Pragmatic = 'pragmatic';

    /**
     * Real Pragmatic Play's MODERN "gs2c" HTML5 client (the actual current
     * Pragmatic engine — SweetBonanza, GatesofOlympus, WolfGold, … ~100
     * titles, discovered categorised as "Pragmatic" in the legacy DB despite
     * carrying no `PM` suffix). A different generation entirely from
     * {@see self::Pragmatic} (the older `*PM` titles' engine):
     *   - Bundle entry is `gs2c/html5Game.html`, not a root `index.html`.
     *   - Command endpoint is `POST /games/{code}/gs2c/v3/gameService` (baked
     *     into the bundle itself, under the asset path — not our usual
     *     `/game/{code}/server`), body/response shape otherwise similar
     *     (`action=doInit|doSpin|…` → `key=value&…` plain text).
     *   - Math model varies by game family: scatter-pays + tumble/cascade
     *     (Sweet Bonanza-style — wins counted anywhere on the grid, winning
     *     symbols removed and refilled, repeat until no more matches) for
     *     some titles, classic paylines + bespoke features (money-collect,
     *     pick-bonus, …) for others. Each legacy game folder ships its OWN
     *     copy of a `PragmaticLib` math engine (not one shared class) — this
     *     protocol currently only implements the tumble family
     *     ({@see TumbleEngine}), piloted on
     *     SweetBonanza.
     */
    case PragmaticTumble = 'pragmatic_tumble';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard (HTTP JSON)',
            self::GamePlatform => 'GamePlatform (WebSocket)',
            self::SlotEvent => 'slotEvent (legacy HTTP)',
            self::Amatic => 'Amatic amarent (WebSocket)',
            self::Playtech => 'Playtech (legacy HTTP)',
            self::Pragmatic => 'Pragmatic Play (legacy HTTP)',
            self::PragmaticTumble => 'Pragmatic Play gs2c (modern HTTP)',
        };
    }

    public function usesWebSocket(): bool
    {
        return $this === self::GamePlatform || $this === self::Amatic;
    }
}
