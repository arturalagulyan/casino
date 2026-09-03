# Game API — launching a game from a shop

How an external operator ("shop") opens one of our games for one of their
players. Companion to [`GAME-ENGINE.md`](GAME-ENGINE.md) (what happens *inside*
the game once it's open).

---

## The model in one paragraph

A **shop** holds an **API key**. The shop calls **`POST /api/game/launch`** with a
player id, a balance and a game code; we return a short-lived **`launch_url`**.
The shop opens that URL in an `<iframe>` on its own site. The game front-end then
talks to our game server (HTTP or WebSocket) for every spin. **We hold the wallet
for the duration of the session** — the balance in the launch call seeds it, and
every bet/win settles on our ledger. There is no per-spin callback to the shop
today (see [Wallet](#wallet)).

```
┌────────┐   POST /api/game/launch        ┌─────────────┐
│  shop  │ ─────────────────────────────▶ │  casino API │
│ backend│   X-Api-Key: <key>             │             │
│        │ ◀───────────────────────────── │  { launch_url, token, … }
└────────┘   launch_url                   └─────────────┘
     │
     │  <iframe src="launch_url">
     ▼
┌──────────────┐  GET /games/{code}?token=…   ┌───────────────────────┐
│   player's   │ ──────────────────────────▶  │ GameAssetController    │
│   browser    │ ◀──────────────────────────  │  · opens a game_session │
│  (game HTML) │   bundle HTML + bootstrap    │  · serves bundle entry  │
└──────────────┘                              └───────────────────────┘
     │
     │  every spin:
     │   · standard games → POST /api/game/{code}/server   (session token)
     │   · EGT games      → WebSocket  (sessionId in the page)
     ▼
   balance / reels / win
```

---

## 1. Authentication

Every call to `/api/game/launch` needs the shop's API key in a header:

```
X-Api-Key: <api_keys.key>
```

(Legacy aliases `api:` header and `api_key` form field are also accepted.)

- The key maps to exactly one shop. Games, players and wallets are all scoped to
  that shop.
- A key may carry an **IP allow-list** (`api_keys.allowed_ips`). If set, requests
  from other IPs get `403`. Empty list = any IP.
- Inactive keys (`api_keys.is_active = false`) get `401`.
- There is **no request signature / HMAC** — the key (+ optional IP pin) is the
  whole auth. Send it over HTTPS only.

---

## 2. `POST /api/game/launch`

### Request (JSON body)

| field         | req | notes |
|---------------|-----|-------|
| `player_id`   | ✔   | Your stable id for the player. We key the player on `(shop, player_id)`. |
| `balance`     | ✔   | The player's current balance, decimal. Seeds our wallet for this session. |
| `currency`    | ✔   | ISO code (`EUR`, `USD`, …). Falls back to the shop currency if unknown. |
| `player_name` |     | Display name / username. Only used on first sight of the player. |
| `email`       |     | Optional, stored on the player. |
| `game`        | ✔   | The game to open — a `game_templates.code` (e.g. `ActionMoneyEGT`) **or** a numeric `games.id`. Must be published (`is_visible`) in your shop. |

> **Game codes keep their provider suffix** (`ActionMoneyEGT`, `AgeOfEgyptPT`).
> The suffix is part of the asset key. The clean name is `game_templates.title`
> ("Action Money") — use that for display, `code` for the API call.

```bash
curl -sX POST https://casino.example.com/api/game/launch \
  -H 'X-Api-Key: sk_live_xxx' \
  -H 'Content-Type: application/json' \
  -d '{
        "player_id": "482913",
        "player_name": "lucky_luke",
        "balance": 250.00,
        "currency": "EUR",
        "game": "ActionMoneyEGT"
      }'
```

### Response `200`

```json
{
  "launch_url": "https://casino.example.com/games/ActionMoneyEGT?token=eyJpdiI6...",
  "token": "eyJpdiI6...",
  "expires_in": 3600,
  "player": { "id": 91234, "username": "lucky_luke", "currency": "EUR" }
}
```

- `launch_url` — open this in an `<iframe>`. Valid for **1 hour**; after that the
  player must be re-launched.
- `token` — the same value, also delivered raw in case you want to build the URL
  yourself. Opaque, encrypted (Laravel `Crypt`, AES-256), stateless — it carries
  `{user_id, game_id, expiry}` and nothing else.
- `expires_in` — always `3600`.

### Errors `422`

```json
{ "error": "Game [SweetBonanza] is not available in this shop." }
```

Other messages: `Invalid launch token.`, validation failures, unknown currency.
Auth failures are `401` / `403` (see §1).

### There is no `language`, `return_url` or `mode` parameter

Demo / fun-play is **not** a launch parameter — it's a separate internal tool
(`/games/demo/{code}`, staff only). Lobby-return URL and locale are not wired yet.

---

## 3. Players

Shop players are **not pre-registered**. The first `launch` for a `player_id`
creates a real user row:

| | |
|---|---|
| keyed on   | `(shop_id, external_player_id)` |
| `external_provider` | `api:<apiKeyId>` |
| role       | `user` |
| password   | random (they never log in directly) |
| wallet     | created automatically, `balance` set from the launch call |

Later launches for the same `player_id` reuse the row and **overwrite the wallet
balance** with the value you send. So: always send the player's current
real balance on every launch.

---

## 4. The launch URL — what the browser gets

`GET /games/{code}?token=…` (`GameAssetController@play`):

1. Verifies the token → `(user, game)`. Bad/expired/for-another-game → `403`.
2. Opens or refreshes a **`game_sessions`** row for `(user, game)` with a fresh
   40-hex **session token** (distinct from the launch token).
3. Serves the game bundle's entry HTML, with a bootstrap injected into `<head>`:

   **Standard games:**
   ```html
   <base href="https://casino.example.com/games/ActionMoneyEGT/<entry-dir>/">
   <script>window.CasinoGame = {
     endpoint: "https://casino.example.com/api/game/ActionMoneyEGT/server",
     session:  "<40-hex session token>",
     currency: "EUR",
     balance:  250.0
   };</script>
   ```

   **EGT / `game_platform` games:** instead get
   ```html
   <script>sessionStorage.setItem('sessionId', '<40-hex session token>');</script>
   ```
   and connect to the game WebSocket — endpoint from `GET /socket_config.json`.

4. Bundle assets are served from `GET /games/{code}/{path}` (static,
   traversal-guarded, `Cache-Control: 1h`).

If a game has no uploaded bundle yet, a built-in demo shell is served so the
pipeline still works.

---

## 5. The play loop

### Standard games — `POST /api/game/{code}/server`

Auth = the **session token** (not the API key), sent as any of:
`Authorization: Bearer <token>` · `session` body field · `X-Game-Session` header.
The `{code}` in the URL must equal the session's game code, else `403`.

```bash
curl -sX POST https://casino.example.com/api/game/ActionMoneyEGT/server \
  -H 'Authorization: Bearer <session-token>' \
  -H 'Content-Type: application/json' \
  -d '{ "command": "spin", "bet": 20, "lines": 10 }'
```

Response shape is per game engine — balance, reel window, win lines, feature
state. `422 { "error": …, "balance": … }` on a rejected command.

### EGT games — WebSocket

`php artisan game:socket` runs a Workerman server. Frames are `:::`-prefixed JSON;
the player+game are resolved from the `sessionId` the page put in
`sessionStorage`. Handshake: `login → settings → subscribe → ping → bet`
(+ `gamble` / `collect` / bonus-pick sub-commands). See
[`GAME-ENGINE.md` § GamePlatform](GAME-ENGINE.md).

### Amatic games — WebSocket (`amarent`)

Same socket as EGT (`ws://…:2087`, `sessionId` from `sessionStorage`), but the
frames are `{"gameData":"A/uNNN,<arg>,<arg>"}` and the replies are packed,
length-prefixed hex strings (`HexFormat(n)` = `strlen(dechex(n)).dechex(n)`),
not JSON — one generic handler (`AmaticProtocol`). `A/u25` init · `A/u250`
resync · `A/u251,<lines>,<betIdx>` spin · `A/u254` collect · `A/u256` free spin ·
`A/u257,<1-6>` gamble (red/black/suit) · `A/u258` half-collect · `A/u350` balance
poll. Same `SlotEngine` maths.

### Novomatic / Greentube games — `POST /game/{code}/server` (`slotEvent`)

Legacy VanguardLTE wire format. The bundle posts to **`/game/{code}/server?sessionId=<token>`**
(no `/api` prefix; the token is the one the launch page wrote to `sessionStorage`)
with a `{ "slotEvent": … }` body and expects `{ "responseEvent": …, "serverResponse": … }`.

| `slotEvent` | does | `responseEvent` |
|---|---|---|
| `getSettings` | whole `SlotSettings` object (name-keyed paytable, reel strips, feature config, `Balance`, `Jackpots`) + a `slotLanguage` label map | `getSettings` |
| `bet` (`slotBet`, `slotLines`) | debit stake, spin, award, grant free spins on 3+ scatter | `spin` |
| `freespin` | stake 0, decrement the free-spin counter, apply `slotFreeMpl` | `spin` |
| `slotGamble` (`gambleChoice`) | red/black double-or-nothing on the last win | `gambleResult` |
| `update` | balance poll | `error` (legacy quirk — carries the balance) |
| bad state / throw | — | `error` (`serverResponse` = message, client `alert()`s it) |

`serverResponse.Balance` in a `spin` is **pre-win** (post-stake); the client
animates the win and reconciles to `afterBalance`. Same `SlotEngine` maths as
standard games; nothing per-game in the handler.

---

## 6. Wallet

**The casino is the wallet of record once a session is open.**

- The `balance` in the launch call is a **one-time seed** of our `wallets` row.
- Every bet: `Ledger` debit + shop `game_banks` pool + jackpot contribution.
- Every win: `Ledger` credit from the pool.
- `game_rounds` / `game_logs` record each spin; `games.total_bet/total_win`
  accumulate.

There is **no debit / credit / balance callback to the shop** and no
reconciliation job. `api_keys.callback_url` and `api_keys.secret` columns exist
(imported from legacy) but nothing reads them yet.

**Integration implication:** after launch, the shop should treat the player's
balance as owned by the casino until the session ends, then read it back. A
proper seamless-wallet callback (shop stays the source of truth, casino calls
`balance` / `debit` / `credit` on the shop per spin) is a planned addition — it is
**not** implemented today.

---

## 7. Reference

| thing | value |
|---|---|
| Launch endpoint | `POST /api/game/launch` |
| Launch auth | `X-Api-Key` header |
| Launch token TTL | 3600 s |
| Play URL | `GET /games/{code}?token=…` |
| Standard play endpoint | `POST /api/game/{code}/server` |
| Standard play auth | session token (bearer / `session` / `X-Game-Session`) |
| EGT play | WebSocket, `sessionId` from `sessionStorage`, endpoint in `/socket_config.json` |
| Amatic play | WebSocket (same as EGT), `{"gameData":"A/uNNN,…"}` frames, hex-string replies |
| Novomatic/Greentube play | `POST /game/{code}/server?sessionId=…`, `{slotEvent}` body |
| Asset URL | `GET /games/{code}/{path}` |
| Player key | `(shop_id, external_player_id)` |
| Wallet | casino-held; launch `balance` seeds it |

Code: `routes/api.php`, `app/Http/Middleware/ResolveApiKey.php`,
`app/Http/Controllers/Api/GameLaunchController.php`,
`app/Services/SeamlessWallet/GameLaunch.php`,
`app/Http/Controllers/GameAssetController.php`,
`app/Http/Controllers/Api/GameServerController.php`,
`app/Services/GamePlay/SocketServer.php`.
