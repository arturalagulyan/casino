# Game play — universal backend + front-end bundles

How a game runs in the rebuild, and how the legacy `casino-api` game structure
maps onto it.

## The legacy shape (what we're replacing)

`/var/www/html/casino-api/app/Games` — **1092** game folders, **every one** with
the same five files (`Server.php` 35–126 KB, `SlotSettings.php` ~40 KB,
`GameReel.php`, `reels.txt`, `RTP.G6`). Checked across EGT / AM / GT / PT / KA /
NG / NET … — the shape is the same per provider; only the *values* differ:

| what | where it lived | now |
|---|---|---|
| platform hooks (`GetBalance` / `SetBalance` / `SetBank` / `GetPercent` / `UpdateJackpots` / `SaveLogReport` / `GetSpinSettings`) | copy-pasted into every `SlotSettings.php` | **`App\Games\GameContext`** (one class) |
| command loop (`login` / `settings` / `bet` / `gamble` / `freespinchoice` / …) | every `Server.php` | **`App\Games\Engine\AbstractSlotServer`** |
| reel + win math | every `Server.php` `bet` case | **`App\Games\Engine\LineSlotServer`** (generic) or a provider subclass |
| paytable, reel strips, paylines, wild/scatter/bonus symbols, wild mult, free-spin count/mult, gamble chance, bonus type, `slotReelsConfig` | **hardcoded** in each `SlotSettings.php` | **DB** — `game_templates` columns + JSON |
| `percent`, `rezerv`, `cask`, `gamebank`, `jpg_id`, `chanceFirepot*`, `fireCount*`, `lines_percent_config_*`, `bet`, `denomination` | legacy admin game-edit form | **DB** — `games` per-shop override columns |

Front-end files lived loose in `public/games/<Code>/` (only `ActionMoneyEGT` is
present). Code names carried a provider suffix (`ActionMoney` + `EGT`); dropped —
`code` is clean, `provider` is a separate field.

## The rebuild

### Naming
`game_templates.code` is the **clean** name (`ActionMoney`); `game_templates.provider`
is the family (`egt`, `gaminator`, `amatic`, `playtech`, `merkur`, …). No suffix.

### The platform contract — `App\Games\GameContext`
One object per running game. A game server **never** touches wallets / banks /
jackpots — it asks the context, which routes everything through the phase-B/C
money layer:

| GameContext | ← legacy SlotSettings | does |
|---|---|---|
| `balance()` | `GetBalance` | wallet balance ÷ denom |
| `placeBet($stake)` | `SetBalance(-)` + `SetBank('bet')` | Ledger debit `source=bet`; feed bank pool (`rtp%` of stake) + jackpots (`contribution_percent`); records the split |
| `awardWin($win)` | `SetBalance(+)` + `SetBank` | Ledger credit `source=win`; drain the pool; `Banker::sweepOverflow` |
| `awardJackpot($jp)` | `ClearJackpot` | `Ledger::payoutJackpot` (→ `jackpot_wins`) |
| `recordRound($result, $raw)` | `SaveLogReport` | `game_rounds` + `game_logs` + `games.total_bet/total_win/rounds_count` + `last_bet_at` |
| `rtpTarget()` / `maxWin()` | `GetPercent` / shop `max_win` | game bank `temp_rtp` → shop `rtp_percent`; win capped at `stake × max_win_multiplier` |
| `stateGet/statePut/stateClear` | `GetGameData` / `SaveGameData` | per-game JSON on `game_sessions.state` (legacy `user.session` blob) |

### `App\Games\GameConfig` — the DB-driven engine spec
Merges the **template** (shared "group" config) with the **game**'s per-shop
overrides. One typed accessor per knob; sensible generated defaults when a field
is blank (`defaultPaytable`, `defaultPaylines`, `defaultWinChances` shaped by
volatility, random reel strips). This is the single source of truth every game
server reads.

### `App\Games\Engine\SpinDecider` — the RTP engine (`GetSpinSettings` port)
Decides **win / bonus / none** from the configured 1-in-N win-chance tables
(`win_chances`, keyed by line-count bucket × shop-RTP band, per legacy
`lines_percent_config`), plus the **self-correcting feedback loop**: when a
game's *actual* RTP (`total_win / total_bet`) runs hot it forces small wins /
cold bonuses for a while (`spin_win_limit` / `rtp_control_count` on
`games.engine_state`). Also returns the effective chance so the engine can size
wins to converge on `shops.rtp_percent`.

### Game servers — `App\Games\`
- `Contracts\GameServer::handle(GameContext, array $request): array`
- `Engine\AbstractSlotServer` — shared loop (`init` / `bet` / `state` / `ping`),
  free-spin accounting, single-win cap.
- **`Engine\LineSlotServer`** — the universal engine for every classic line slot
  (EGT / Gaminator / Amatic / Playtech / … same model). Fully `GameConfig`-driven:
  reels, rows, symbols, paytable, paylines, wild substitution + multiplier,
  scatter → free spins (fixed grant *or* per-scatter-count table), gamble
  (double-or-nothing at `gamble_win_chance`). Wins are outcome-driven
  (SpinDecider) then sized by the `win_distribution` curve so hit-rate × mean ≈
  target RTP, and a board is synthesised to show it. **No hardcoded values** —
  even the RNG-shaping constants come from the DB (`volatility` presets them,
  `win_distribution` / `rtp_control` / `rtp_control_window` override).
- `GameRegistry::resolve($template)` order:
  1. `App\Games\Titles\<Code>Server` — a full bespoke port
  2. `App\Games\Providers\<Provider>\<Provider>SlotServer` — a provider family base
  3. **`Engine\LineSlotServer`** (default — no per-game code needed)

  A real provider only needs its own class when its bonus round / reel mechanic
  genuinely differs; it extends `AbstractSlotServer` and the money + RTP side is
  already done.

### What admins control — everything, all DB, all in the Filament forms

Checked against **all 1092** legacy `app/Games/*/SlotSettings.php` — every value
they hardcoded is a field here.

**Game Template** (Games ▸ Game Templates) — the shared engine spec:

| section | fields |
|---|---|
| Grid & symbols | `reel_count`, `row_count`, `symbol_count`, `symbols` (playable ids — legacy `SymbolGame`), `wild_symbol`, `scatter_symbol`, `bonus_symbol`, `wild_multiplier`, `volatility` |
| Bonus & free spins | `has_bonus`, `bonus_type`, `scatter_type`, `has_free_spins`, `free_spins_count` (fixed) **or** `free_spins_table` `[0,0,0,10,15,20]` (per scatter count — legacy `slotFreeCount` array), `free_spins_multiplier`, `split_screen` |
| Gamble | `has_gamble`, `gamble_type`, `gamble_win_chance` (legacy `rezerv`) |
| Defaults for clones | `default_bet_options`, `default_denomination`, `scale_mode`, `view_state` |
| Engine data (JSON) | `paytable`, `reel_strips`, `paylines`, `win_chances` (legacy `lines_percent_config`), `layout` (client: reel positions, key map, sounds, hidden buttons, exit URL — legacy `slotReelsConfig` / `keyController` / `slotSounds` / `hideButtons` / `slotExitUrl`) |
| RTP tuning (JSON) | `rtp_control_window` (legacy `RtpControlCount`), `win_distribution` (win-size curve — `small_prob` / `tail_scale` / `budget_frac` / …), `rtp_control` (`cold_spin_chance` / `cold_bonus_chance` / `correction_max_win` / `clamp_spins`) |

**Game** (Games ▸ Games) — per-shop override, blank = inherit the template:
target RTP %, `max_win_multiplier`, `bank_type` (legacy gamebank), attached
jackpot, `wild_multiplier`, `free_spins_count`, `free_spins_table`,
`gamble_win_chance` (`reserve_percent`), `cask`, `bet_options`, `denomination`,
`jackpot_chances` (firepots — legacy `chanceFirepot*`/`fireCount*`), `win_chances`,
`win_distribution`, categories, label, visibility, sort order.

Anything blank runs on generated defaults (`GameConfig::default*`), so a bare
template still plays.

### Front-end bundles — uploaded by admin
Legacy shipped these in the repo. Now: **`game_templates` → Front-end bundles**
relation manager (Games ▸ Game Templates ▸ a row ▸ *Upload front-end*).

- Accepts a **`.zip`** of the game front-end. A single wrapping folder is
  unwrapped; `index.html` (or a named entry) must be present; **PHP files are
  rejected**.
- Extracted to the `game_bundles` disk — `storage/app/game-bundles/<slug>/<version>/`
  (gitignored — large binary assets stay out of the repo).
- Each upload is a new `game_bundles` **version**; the newest is active. Older
  versions can be re-activated or deleted from the relation manager.

### Serving + launch flow
```
provider  ──POST /api/game/launch (X-Api-Key)──▶  GameLaunch: upsert player,
                                                   issue 1h Crypt token
          ◀── { launch_url: /games/<Code>?token=… }

player    ──GET /games/<Code>?token=… ──▶  GameAssetController@play
                                           · verify token → (user, game)
                                           · open a game_session (random token)
                                           · serve the bundle entry HTML with
                                             <script>window.CasinoGame={endpoint,session,…}</script>
                                           · (no bundle yet → built-in demo shell)

game JS   ──POST /api/game/<Code>/server { session, command, … } ──▶  GameServerController
                                           · resolve game_session → GameContext
                                           · GameRegistry → server->handle()
          ◀── { balance, reels, win, … }

static    ──GET /games/<Code>/<path> ──▶  GameAssetController@asset  (bundle files, traversal-guarded)
```

`resources/views/games/demo-shell.blade.php` is a working reference client (init +
spin against `window.CasinoGame.endpoint`) — it's also the contract a real
uploaded bundle must speak.

### Where the code lives — `app/Services/GamePlay`

| | |
|---|---|
| `GameContext` | the platform contract (balance / placeBet / awardWin / clawback / recordRound / state) |
| `GameConfig` | the DB-driven spec — resolves **game override → template → category `config` → generated defaults** |
| `Engine\SlotEngine` | **the one spin engine** — gates a spin, then rejection-samples the *real* reels until the board matches (no synthesised wins); payout freq/size come from the reel + paytable design |
| `Engine\SpinDecider` | the "garant" — win/bonus/none from the win-chance tables + a smooth `winScale` that clamps payouts while realised RTP runs ahead of target |
| `Engine\LineSlotServer` | HTTP command loop over `SlotEngine` (demo shell + standard bundles) |
| `SocketServer` | the **one** dumb WS bridge (like legacy `Slots.js`) — frame → session → protocol handler |
| `Protocol\GameProtocol` + `Protocol\GamePlatform*` | one handler per **wire format** (not per provider); `GamePlatform*` is the EGT-style login/settings/bet format |
| `App\Enums\BonusFlow` | config-selected feature-round strategies |
| `GameRegistry` | resolves an internal game → its HTTP server (almost always `LineSlotServer`); no per-game / per-provider classes |

**Provider / type grouping is Categories, not code.** "Egt", "Pragmatic",
"Slots", "Arcade"… are `categories` rows (admin-managed, many-to-many with games).
A category carries a `config` JSON its games inherit — e.g. the "Egt" category's
`{"client_protocol":"game_platform"}` routes its games to the WebSocket protocol.
There is no `provider` column.

Legacy game bundles genuinely don't share one wire format (EGT does
login/settings/bet, Wazdan does setup/resume, Playtech uses socket.io framing,
NetGame uses `gameData.cmd`, …) — ~8-10 across all 1092 games. Each is one
`Protocol\<Name>*` handler + one `ClientProtocol` case; every handler sits on the
same `SlotEngine`. There is no per-game or per-provider code.

### GamePlatform — the WebSocket wire format

A game whose resolved `client_protocol` is `game_platform` (normally from its
category) plays over the socket:

```
player  ──GET /games/<Code>?token=… ─▶ GameAssetController@play
                                       · verify launch token → open game_session
                                       · serve bundle index.html + inject
                                         <script>sessionStorage.sessionId = "<session token>"</script>
        ──WS ws(s)://host:2087/slots ─▶ php artisan game:socket  (the `gamesocket` compose service)
                                       · frame `:::{json}` → SocketServer  (the one dumb bridge)
                                       · resolve game_session by sessionId → GameContext
                                       · GamePlatformProtocol.dispatch()
                                           login / settings / subscribe / ping / bet
                                           + bet.gameCommand: gamble / collect /
                                             multiplierchoice / freespinchoice / bonuschoice
                                           · settings paytableCoef/scatterCoef/reels ← GameConfig
                                           · bet → SlotEngine.spin() → GamePlatformFormatter
                                           · pick screens → GamePlatformBonusRounds (from bonus_config)
        ◀── one `:::{json}` frame per message
```

`config/games.php` → socket host/port/workers + the public host served as
`/socket_config.json`. `bonus_config` (template, or inherited from a category)
drives the feature flows:
`{ "triggers": { "<sym>": {"flow":"pick_multiplier_freespins"|"pick_money"|"free_spins", …} }, "<flow>": {…params…}, "gamble": {…} }`.
Adding another wire format (Wazdan, Playtech bundles…) = one new
`Protocol\<Name>*` handler + one `ClientProtocol` case; never per-game or per-category.

### Deferred
- More `BonusFlow` strategies as new EGT mechanics show up (cascades, hold-and-spin, …).
- `wss://` in prod goes through nginx (`web` service) — currently the socket port is exposed directly.
- Bundle CDN / `public` symlink (currently streamed through PHP).
- An ETL to import the 1092 legacy games' paytables/reels into `game_templates`.
