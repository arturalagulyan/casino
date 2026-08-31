# Business-logic review — rebuild vs. legacy `casino-api`

Compared the rebuild (`/var/www/casino`, Filament v5 admin) against the **live
legacy code + DB** on `207.180.253.8` (`/var/www/html/casino-api`, VanguardLTE /
`loshmis/vanguard`, Laravel 8, MariaDB `casino`, 75 `w_*` tables). This is the
codebase that actually runs the current backend at `http://207.180.253.8/backend`
— note it is **newer and different** from the local `Desktop\jackpotmatic-master`
copy (it has the seamless-wallet `PlayersController`, `V2`/`v3` API, ATM layer).

The DB schema (`schema.sql`, 75 tables) matches what `docs/DATABASE.md` already
mapped. No schema surprises. The gaps are in **admin-panel behaviour**, not the
data model.

---

## 1. Currencies  ← the main ask

**Legacy:** `Shop::$currencies` / `Shop::$values['currency']` is a fixed list of
~30 codes (`EUR, USD, GBP, ALL/LEK, BTC, mBTC, ARS, AUD, CAD, NZD, NOK, SEK, ZAR,
INR, RUB, CFA, HRK, HUF, GEL, UAH, RON, BRL, MYR, CNY, JPY, KRW, IDR, VND, THB,
TND`). Rendered as a `<select>` in shop add/edit and as a filter on the shop list
and the cash report. `w_users.currency` is `varchar(50)`, free-text, set by the
seamless-wallet launch call (`PlayersController::getGameLaunch` writes
`$data['currency']` straight through — live data now has junk like `YDR`).
`w_game_bank`, `w_fish_bank`, `w_stat_game` each carry their own `currency` and
default `'EUR'`.

**Rebuild today:** `currency` is a `char(3)` text column on `shops`, `users`,
`wallets`, `game_banks`, `user_banks`, `game_rounds`; every Filament form is a
free `TextInput`. `char(3)` cannot even hold `mBTC`.

**Plan**
- `App\Enums\Currency` (string-backed) — full legacy list + `label()`, `symbol()`,
  `decimals()`, `::options()`.
- Widen every `currency` column to `string('currency', 4)`; default `'EUR'`;
  index the ones we filter/group on (`game_rounds`, `transactions`).
- Add `currency` to `transactions` (legacy `w_statistics` had none, but a shop can
  now hold several bank currencies, so every money move needs one for the
  per-currency shop math).
- Cast `currency => Currency::class` on all six models; `->money()` columns read
  `$record->currency?->value`.
- Filament: `Select::make('currency')->options(Currency::class)` on Shop, User,
  GameBank, UserBank; read-only display elsewhere. A user's `wallet.currency`
  follows `user.currency` on save.
- **Per-currency shop math:** the Cash report and the finance widgets group by
  `(shop, currency)` and never sum across currencies (no FX table yet — a
  `currency_rates` table is future work; noted in Backlog).

---

## 2. Filters — match the legacy panel

| Legacy list | Legacy filters | Rebuild today |
|---|---|---|
| Shops (`shops/list`) | name, balance from/to, `frontend`, RTP from/to, `orderby`, currency, categories[], status (active/blocked), owner-user search | status only |
| Users (`user/list`) | username search, status, role, online (has session) | role, shop, status, blocked, trashed |
| API keys (`api/list`) | search (keygen / ip), status | none |
| Cash (`cash/index`) | date-range, currency | n/a (page doesn't exist) |
| Jackpots (`jpg/list`) | shop-scoped, bulk select | none |
| Games (`game/list`) | shop, category, label, device, visibility, bank | none (generate output) |
| Transactions | system, shop, date | shop only |
| Game rounds (stat) | shop, user, game, date, currency | shop, big-wins, today |

**Plan** — add to every resource table:
- **Shops:** currency, `frontend`, `order_by`, RTP range, balance range,
  category, owner (SelectFilter) — keep name search + status.
- **Users:** currency, online (ternary — `whereHas('gameSessions')`), balance
  range, has-parent; keep role/shop/status/blocked/trashed.
- **API keys:** shop, `is_active`; searchable `key` + `allowed_ips`.
- **Game banks:** currency (+ shop).
- **Jackpots:** shop, `is_active`.
- **Games:** shop, template, category, label, bank type, visible.
- **Game templates:** engine, provider, device, bank type, active.
- **Transactions:** shop, user, source, direction, currency, date range.
- **Game rounds:** shop, user, game, currency, date range (keep big-wins/today).
- **Categories:** shop, parent.

Shared helper `App\Filament\Support\Filters` for the currency + date-range +
amount-range filters so they're identical everywhere.

---

## 3. Business logic that must match the legacy

### Shops (high level — the tenant)
- **Status** is derived in legacy from `is_blocked` + `pending`; rebuild has a
  clean `ShopStatus` enum — keep it, but the block/unblock **actions** and the
  `pending` gate need to exist (legacy `ShopController@shop_block/unblock`,
  `ShopsController@action`).
- **Balance top-up / withdraw** (`ShopsController@balance`) must write a
  `transactions` row (`source = shop_transfer`, `direction`, `balance_before`)
  and move `shops.balance`. Rebuild has no such action yet.
- `player_limit` (legacy `shop_limit`) is the **bank-overflow ceiling** — when a
  `game_bank` pool exceeds it the surplus sweeps to profit. Documented, not
  implemented (belongs to the spin engine, phase 4 — leave a `Banker` stub).
- `rtp_percent` (legacy `percent`, values 90/84/82/74/60) is the target payout;
  the filter uses ranges (`percent_labels`: `90 => "90 - 92"` …).
- Owner (`owner_id` ← `user_id`) + `shop_user` pivot for staff who operate it.

### Users / cash
- **Balance add / out** (`UsersController@updateBalance`, `BalanceController`):
  writes `transactions` **and** an `accounting` json block (legacy
  `w_statistics_add`: `agent_in/out`, `distributor_in/out`, `credit_in/out`,
  `money_in/out`) so the up-line hierarchy P&L reconciles. Rebuild: add a
  `User\AdjustBalance` action + a `Ledger` service that writes both.
- **Limit** (`updateLimit`) — per-user spending cap. Column missing on `users`
  → add `daily_limit` / `limit` nullable.
- Player identity for seamless wallet: legacy `api` (→ `api_keys.id`) + `player`
  (external id). Rebuild has `external_provider` / `external_player_id` — keep,
  but `external_provider` should reference the `api_keys` row, not a free string.
- Hierarchy scoping (`availableUsers()`, `availableShops()`, role `level`) — the
  rebuild's `HasAccessControl` has `roleLevel()` but no "visible subtree" query.
  Add `User::scopeVisibleTo()` and use it in every resource `getEloquentQuery()`.

### Jackpots
- `contribution_percent` (`percent`) accrues per spin; `seed_min/max` +
  `payout_min/max` (legacy `start_balance` / `pay_sum` were indexes into a range
  array — rebuild already stores real ranges).
- **"Pay out now"** (`JPGController@immediately`): credits `last_winner`, resets
  balance to 0, writes a `transactions` row + a `jackpot_wins` row. Add as a
  record action.
- **Global bulk edit** (`JPGController@global_update`): set `pay_sum` / `percent`
  / `start_balance` / `balance` across many jackpots, each balance change logged.
  Add as a Filament bulk action.
- Admin balance edits log a `transactions` row (`source = jackpot`).

### Games / banks / stat
- **Banks screen** (`DashboardController@banks` / `banks_update` /
  `do_banks_update`, admin-only): shows every `(shop, currency)` bank, lets an
  admin move money between pools and in/out of profit — each change a
  `transactions` row. Rebuild has a `GameBank` CRUD resource but no
  "adjust pool" action and no profit counterpart. Add `GameBank\AdjustPool`.
- **Cash** (`CashController`): per-shop `SUM(bet)` / `SUM(win)` / net / payout%
  over a date range, currency filter. Rebuild: none → new `CashReport` page.
- **Game stat** (`DashboardController@game_stat`): per-game in/out/profit/RTP,
  hold %, from `game_rounds`. Rebuild: `Game` has `total_bet`/`total_win` running
  columns but no report → new `GameStatReport` page (or a resource tab).
- `game.reserve_percent` (`rezerv`) and `cask` feed the spin RNG — engine phase.

### API keys (given to providers)
- Legacy `w_apis`: `keygen`, `ip`, `shop_id`, `status`, `endpoint`. The provider
  sends `keygen` in the `api` header to `getGameLaunch`; `endpoint` is where we
  POST bet/win back (`runServer`). Rebuild `api_keys` already models this well
  (`key`, `allowed_ips` json, `callback_url`, `is_active`, `secret`,
  `last_used_at`). Missing: the **"generate key"** button (`ApiController@generate`
  — 25-char alnum) and the list filters. Add both.
- `operators` (`opid`, `ucurl`, `cburl`) — seamless callbacks. Rebuild has the
  model, no resource. Add a thin `OperatorResource`.

### PlayersController (noted "not looking good" — game-play deferred)
`getGameLaunch` is the seamless-wallet entry: provider POSTs
`{id,name,email,balance,currency}` + `api` header → upsert `users` row keyed by
`(player, api)` → return an AES-encrypted launch URL (1-hour TTL). Problems:
hard-coded key/iv in the controller, hard-coded `shop_id => 1` in the
`ShopUser::create`, `role_id => 1` magic number, no validation, `openssl_encrypt`
with identical key/iv. **Not in scope now** — but the admin side must be ready:
`api_keys` per shop, `operators`, and the `users` table's external-player fields.
When we build phase 4 this becomes a `GameLaunchController` + a `SeamlessWallet`
service.

---

## 4. Delivery phases — all landed (uncommitted)

### A — currency + filters
`App\Enums\Currency`, `App\Support\Money::format()` (crypto/non-ISO safe), every
`currency` column widened to `string(4)` + cast, `currency` added to
`transactions`, `shops.balance` restored. Currency `Select` on Shop/User/
GameBank/GameRound/ApiKey. `App\Filament\Support\TableFilters` (currency /
amount-range / date-range) + full filter sets on all 11 resource tables.
`CasinoOverview` widget groups GGR + player funds **by currency**.

### B — money movements
`App\Services\Ledger` — the one place balances move. Ports legacy
`User::addBalance()` + the `Statistic::boot()` accounting derivation
(`w_statistics_add`: agent/distributor/credit/money in-out keyed on source +
direction + actor role). Methods: `adjustPlayer` (cashier deposit drains the
shop float), `adjustStaff` (peer transfer debits the actor unless admin),
`adjustShopCredit`, `adjustBankPool`, `setJackpotBalance`, `payoutJackpot`
(→ `jackpot_wins` row + reset). All row-locked, one `transactions` row each.
Filament actions: `AdjustBalanceAction` (Users), `AdjustShopCreditAction`
(Shops), `AdjustBankPoolAction` (GameBanks), `JackpotActions::payout` /
`setBalance` / `bulkEdit`, `RegenerateApiKeyAction`. New `OperatorResource`.

### C — reports + scoping
`App\Support\Hierarchy` (recursive `parent_id` CTE → visible user / shop ids),
`Model::visibleTo($staff)` scopes (`ScopedToShopHierarchy` on the 8 shop-scoped
models, `User::scopeVisibleTo`, `Shop::scopeVisibleTo`), wired into every
resource via `App\Filament\Concerns\ScopesToViewer`. `CashReport` +
`GameStatReport` pages (Finance nav group, gated on `stats.pay` / `stats.game`) —
per-(shop|game, currency) in/out/net/payout/RTP/hold over a date range.

### D — engine-adjacent (money side only; RNG/serving still deferred)
- `App\Services\Banker` — `settleRound` (stake → pool, win → pool, overflow
  sweep), `sweepOverflow` (pool > `shops.player_limit` → `game_bank` debit via
  Ledger), `contributeToJackpot` (accrual by `contribution_percent`),
  `jackpotReady`, `shopLiquidity`.
- `currency_rates` table + `App\Services\Fx` (`convert` / `toEur`, EUR base,
  seeded with indicative rates). Reports still show per-currency figures;
  conversion is opt-in.
- Seamless wallet: `routes/api.php` + `api.key` middleware
  (`App\Http\Middleware\ResolveApiKey` — key from `X-Api-Key`/`api` header, IP
  allow-list, `last_used_at`), `App\Services\SeamlessWallet\GameLaunch`
  (validated player upsert keyed by `(shop, external id)`, `Crypt` launch token
  with 1h TTL — no hard-coded key/iv), `Api\GameLaunchController` (`launch` +
  `play` stub). Replaces the bad parts of legacy `PlayersController`.

**Tests:** 31 green (`Ledger`, `Hierarchy`, `SeamlessWallet`, `Banker`/`Fx`,
report pages, table filters, every resource index). `pint` clean.

## 5. Still not built (needs the spin engine or a product decision)
- The RNG / win decision and game HTML/websocket serving (`runGame` / `runServer`).
- Live FX feed (rates are seeded static). Confirm if shops need one reporting
  currency on the dashboard.
- `users.limit` / cashier shift (`open_shift`), `securities` auto-block rules,
  pincodes, payments, tickets, CMS, bonuses/tournaments (legacy Backlog).

## Backlog / open questions
- No FX rate table — cross-currency totals are deliberately not shown. Confirm
  whether shops ever need a single "reporting currency".
- `users.limit` / cashier shift (`open_shift`) — port now or with payments phase?
- `securities` (auto-block rules) — admin UI wanted in this rebuild?
