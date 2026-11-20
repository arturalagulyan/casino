<?php

namespace App\Enums;

use App\Services\GamePlay\Engine\PaylineEngine;
use App\Services\GamePlay\Engine\TumbleEngine;
use App\Services\GamePlay\Protocol\ApolloProtocol;
use App\Services\GamePlay\Protocol\IgtProtocol;
use App\Services\GamePlay\Protocol\NetEntProtocol;
use App\Services\GamePlay\Protocol\NovolineProtocol;
use App\Services\GamePlay\Protocol\PlayNGoProtocol;

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

    /**
     * The other big branch of the modern Pragmatic "gs2c" family (see
     * {@see self::PragmaticTumble} for the split rationale) — classic
     * paylines (5x3, wild substitution, count-indexed-from-the-end paytable)
     * plus a "money/coin" symbol carrying a weighted-random cash value
     * summed into the win every spin, and a separate `doMysteryScatter`
     * action that grants an ungated free-spins round (legacy quirk, ported
     * as-is). No tumble/cascade — each `doSpin` is one independent board
     * draw; free spins are just a persisted counter. Same transport as
     * {@see self::PragmaticTumble} (`POST /games/{code}/gs2c/v3/gameService`),
     * different math entirely — {@see PaylineEngine},
     * piloted on AztecKing. Per-title extras this doesn't cover (WolfGold's
     * "hold and spin" money-collect bonus round, mystery-symbol stacking
     * during free spins, pick-a-prize wheels) are the same class of gap as
     * every other provider's bespoke bonus mechanics.
     */
    case PragmaticPayline = 'pragmatic_payline';

    /**
     * Play'n GO's line protocol (`d=<seq>\r\n<session>\r\n<command lines>`)
     * over plain HTTP POST to `/playngo/{session}/server` — the client's
     * `defaultProtocol: http` transport (Play'n GO's own servers also carry it
     * over a SignalR hub). The page is a synthesised ContainerLauncher shell;
     * its config endpoints live under `/playngo/{session}/`. Spins are served
     * from an outcome library of rounds recorded on Play'n GO's practice
     * server ({@see PlayNGoProtocol}).
     */
    case PlayNGo = 'playngo';

    /**
     * NetEnt's classic HTML5 client protocol: `GET {server}servlet/
     * CasinoGameServlet;jsession=<session>?action=init|paytable|spin|freespin…`
     * → a flat `key=value&…` body. Our page hands the client
     * `server=/netent/{session}/` and `sessId=<session>` in its query string
     * (the parameters NetEnt's launcher would add). Spins are served from an
     * outcome library of rounds recorded on a NetEnt demo session
     * ({@see NetEntProtocol}).
     */
    case NetEnt = 'netent';

    /**
     * Apollo Games' casino server protocol: `casino.init` / `casino.spin` /
     * `casino.gamble` / … with JSON bodies. Apollo's client sends them as
     * WebSocket text frames (`name=<action>\n<headers>\n\n<json>`); our page
     * replaces the browser's WebSocket for that one URL with a shim that
     * POSTs each frame to `/apollo/{session}/<action>` and hands the reply back
     * as a frame. Spins are served from an outcome library of rounds recorded
     * on a Betboy demo session ({@see ApolloProtocol}).
     */
    case Apollo = 'apollo';

    /**
     * IGT's RGS protocol ("Skateboard" HTML5 clients): `GET|POST
     * {server}/<action>&…` — authenticate, paytable, currency, initstate,
     * play — JSON in and out. Our page sets `server=/igt/{session}/rgs?`.
     * Spins are served from an outcome library of rounds recorded on a
     * Betboy demo session ({@see IgtProtocol}).
     */
    case Igt = 'igt';

    /**
     * Greentube's game server (Novoline HTML5 slots): one WebSocket of text
     * frames (type character + ÿ-separated fields). Our page's WebSocket shim
     * POSTs each frame to /novoline/{session}/ws. Spins are served from an
     * outcome library of rounds recorded on a Betboy demo session
     * ({@see NovolineProtocol}).
     */
    case Novoline = 'novoline';

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
            self::PragmaticPayline => 'Pragmatic Play gs2c payline (modern HTTP)',
            self::PlayNGo => "Play'n GO (line protocol over HTTP)",
            self::NetEnt => 'NetEnt (CasinoGameServlet over HTTP)',
            self::Apollo => 'Apollo Games (casino.* over HTTP)',
            self::Igt => 'IGT (RGS WebEngine over HTTP)',
            self::Novoline => 'Novoline / Greentube (WebSocket shim → HTTP)',
        };
    }

    public function usesWebSocket(): bool
    {
        return $this === self::GamePlatform || $this === self::Amatic;
    }
}
