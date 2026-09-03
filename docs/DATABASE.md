# Database design — phase 1 (core domain)

This is a ground-up redesign of the legacy **VanguardLTE / "jackpotmatic"** casino
platform (`C:\Users\User\Desktop\jackpotmatic-master`, Laravel 8, MyISAM, `w_`
prefix, no foreign keys). Source of truth for the legacy shape:
`casino` DB on `207.180.253.8` (MariaDB) — 75 tables, ~2.4k users, ~3.2k games,
1.8M spins, 7M raw log rows.

**Scope of this phase:** the tables needed to run an admin panel, load games and
let users play. Bonuses/tournaments/tickets/SMS/pincodes/payment-gateways are
**deferred** (see *Backlog* at the bottom) — they hang off this core cleanly.

## Principles

| Legacy | Now |
|---|---|
| MyISAM, no FKs | InnoDB, foreign keys with explicit `onDelete` |
| `w_` prefix, mixed singular/plural | no prefix, Laravel conventions |
| `int(11)` ids, no unsigned | `bigIncrements` / `foreignId` |
| status/enum as free-text `varchar` | string columns cast to PHP enums |
| comma-separated strings (`bet`, `country`, `os`) | `json` columns / pivot tables |
| money as `decimal(20,4)` (jpg `20,6`) | kept — `decimal(20,4)`, jackpots `decimal(20,6)` |
| balance + 20 bonus columns inline on `users` | identity on `users`, money on `wallets` (still flat — see note) |
| per-shop game rows cloned from `original_id` | `game_templates` (catalog) + `games` (per-shop instance) |
| `w_game_log` never pruned (7M rows) | `game_logs` documented as retention-managed |
| spin financials in `w_stat_game`, money moves in `w_statistics` | `game_rounds` (per spin) + `transactions` (ledger) |

**Note on `wallets`:** the legacy `w_users` table carries `balance` plus nine
bonus-bucket balances (`tournaments`, `happyhours`, …) and nine matching
`count_*` wagering counters. That logic is deeply wired into `User::addBalance()`
and the bonus engine. To keep the phase‑2 business-logic port mechanical, the
`wallets` table keeps those as explicit typed columns rather than normalising
them into a `bonus_wallets` child table now. Normalising is a fast-follow once
the ported behaviour is locked by tests.

## IDs and data import

Every table keeps an integer surrogate `id`. Legacy row ids can be preserved on
import (they fit in `bigint`). Columns that legacy stored as an *index into a PHP
array* (`jpg.start_balance`, `jpg.pay_sum`, `games.bet`) are converted to real
values/ranges — the ETL will need a small lookup map (documented per-column
below). No ETL script in this phase.

---

## A. Identity, tenancy, access control

### `shops`  ← `w_shops`
The tenant. A shop is one casino site with its own players, games, banks, theme.

| column | type | note / legacy |
|---|---|---|
| id | bigint pk | |
| name | string | |
| slug | string unique | new — URL/theme key, seeded from `frontend` |
| frontend | string | legacy `frontend` — theme folder name |
| currency | char(3) | legacy `currency` (`''`→`EUR`) |
| status | string→`ShopStatus` | `active` / `blocked` / `pending` (from `is_blocked`,`pending`) |
| rtp_percent | unsignedTinyInt | legacy `percent` (90/84/74/60) — target payout % |
| max_win_multiplier | unsignedInt | legacy `max_win` |
| player_limit | decimal(20,4) | legacy `shop_limit` — bank overflow ceiling |
| order_by | string→`GameOrder` | legacy `orderby` enum (`AZ`/`Rand`/`RTP`/`Count`/`Date`) |
| owner_id | fk→users nullable | legacy `user_id` |
| allowed_countries / allowed_os / allowed_devices | json | legacy csv columns + empty `w_shops_*` tables |
| required_rules | json | legacy `rules_*` int flags |
| happy_hours_enabled … wheel_fortune_enabled | boolean | legacy `*_active` flags (kept for admin toggles) |
| timestamps | | |

### `users`  ← `w_users` (identity / profile / auth / hierarchy only)
Players **and** staff. Staff sit in a 6-level tree (`user < cashier < manager <
distributor < agent < admin`) via `role` + `parent_id`.

| column | type | note / legacy |
|---|---|---|
| id | bigint pk | |
| shop_id | fk→shops nullable | null for admin/agent/distributor above a single shop |
| role_id | fk→roles | legacy `role_id` (kept denormalised alongside `role_user`) |
| parent_id | fk→users nullable | legacy `parent_id` (hierarchy) |
| inviter_id | fk→users nullable | legacy `inviter_id` |
| username | string | unique per shop → `unique(shop_id, username)` |
| email | string nullable | legacy `NOT NULL DEFAULT ''` |
| password | string | |
| first_name / last_name / phone / birthday / avatar | | |
| phone_verified_at | timestamp nullable | legacy `phone_verified` int |
| language | char(5) default `en` | |
| currency | char(3) nullable | |
| rating | unsignedInt default 0 | loyalty level / progress rating |
| status | string→`UserStatus` | `active`/`unconfirmed`/`banned`/`inactive` (legacy free-text) |
| is_blocked / is_demo_agent / free_demo | boolean | |
| agreed_at | timestamp nullable | legacy `agreed` int |
| external_provider / external_player_id / external_token | string nullable | legacy `api` / `player` / `api_token` — seamless-wallet players |
| two_factor_secret / two_factor_enabled | | legacy `google2fa_*` |
| current_session_id | string nullable | legacy `session` mediumtext — single-session enforcement |
| confirmation_token / sms_token / sms_token_at | | |
| last_login_at / last_online_at / last_bet_at / last_progress_at / last_daily_entry_at / last_wheel_at | timestamp nullable | legacy `last_*` — drive bonus cooldowns |
| remember_token | | |
| timestamps + softDeletes | | legacy hard-deletes with a 30-table manual cascade |

### `wallets`  ← money columns of `w_users`
One row per user (multi-currency deferred). All money the player holds.

| group | columns | legacy |
|---|---|---|
| real | `balance` | `balance` |
| bonus balances | `bonus_tournaments`, `bonus_happy_hours`, `bonus_refunds`, `bonus_progress`, `bonus_daily`, `bonus_invite`, `bonus_welcome`, `bonus_sms`, `bonus_wheel` | `tournaments`, `happyhours`, `refunds`, `progress`, `daily_entries`, `invite`, `welcomebonus`, `smsbonus`, `wheelfortune` |
| wagering remaining | `wager_total`, `wager_tournaments`, `wager_happy_hours`, `wager_refunds`, `wager_progress`, `wager_daily`, `wager_invite`, `wager_welcome`, `wager_sms`, `wager_wheel` | `count_balance`, `count_*` |
| locked | `locked` | `address` |
| lifetime | `total_deposited`, `total_withdrawn` | `total_in`, `total_out` |

FK `user_id` unique. `currency` char(3).

### `roles` ← `w_roles` · `permissions` ← `w_permissions`
Kept close to the legacy `jeremykenedy/laravel-roles` shape (the app leans on
`role.level` for the hierarchy and `hasRole('slug')` everywhere).

- `roles`: `name`, `slug` unique, `description`, `level` int, timestamps, softDeletes
- `permissions`: `name`, `slug` unique, `description`, `group` (legacy `group_id`), `sort` (legacy `rank`), timestamps, softDeletes
- `role_user` ← `w_role_user`: `role_id`,`user_id`, unique, timestamps
- `permission_role` ← `w_permission_role`
- `permission_user` ← `w_permission_user` (direct grants)

Seeded roles (from live DB): `user`(1) `cashier`(2) `manager`(3) `distributor`(4) `agent`(5) `admin`(6).

### `shop_user` ← `w_shops_user`
Staff ↔ shops they can operate (a distributor/manager working several shops).
`shop_id`,`user_id`, unique, timestamps.

---

## B. Game catalog

### `game_templates` ← `w_games` rows with `shop_id = 0`, + `w_game_path`
The master catalogue — one row per real game package (≈136 live).

| column | type | note |
|---|---|---|
| code | string unique | legacy `name` e.g. `PragmaticSweetBonanza` |
| code | string unique | legacy game name, provider suffix kept (`ActionMoneyEGT`) — keys the bundle asset paths |
| title | string | clean display name (`Action Money`); `games:normalize-titles` backfills it. No `provider` column — grouping is a Category. |
| engine | string→`GameEngine` | `internal` / `merkur` / `seamless` — how the server side runs |
| package_path | string nullable | legacy `w_game_path.path` — server code location |
| client_path | string nullable | legacy field, unused — front-end bundles live in the `game_bundles` table (`disk`/`path`/`entry`) |
| device | string→`Device` | `desktop` / `mobile` / `both` (legacy `device` 1/2) |
| bank_type | string→`BankType` | default pool: `slots`/`little`/`table`/`bonus`/`fish` (legacy `gamebank`) |
| default_bet_options | json | legacy `bet` / `bet_ALL` |
| default_denomination | decimal(20,4) | |
| default_lines_config | json | legacy `lines_percent_config_*` blobs |
| default_jackpot_chances | json | legacy `chanceFirepot1..3`, `fireCount1..3` |
| default_advanced | json | legacy `advanced` |
| scale_mode / view_state | string→enum | legacy `scaleMode` / `slotViewState` |
| is_active | boolean | |
| timestamps | | |

### `games` ← `w_games` rows with `shop_id > 0`
A template made available in one shop, with that shop's tuning. (≈3.2k live.)

| column | type | note |
|---|---|---|
| shop_id | fk→shops | |
| template_id | fk→game_templates | legacy `original_id` |
| jackpot_id | fk→jackpots nullable | legacy `jpg_id` |
| title | string nullable | per-shop override |
| label | string→`GameLabel` nullable | `new` / `hot` / `exclusive` |
| bank_type | string→`BankType` | overrides template (legacy `gamebank`) |
| reserve_percent | unsignedTinyInt | legacy `rezerv` |
| cask | unsignedInt | legacy `cask` |
| lines_config_spin / _spin_bonus / _bonus / _bonus_bonus | json | legacy 4 text blobs |
| jackpot_chances | json | legacy `chanceFirepot*` / `fireCount*` |
| advanced | json | legacy `advanced` |
| bet_options | json | legacy `bet` / `bet_ALL` (array) |
| denomination | decimal(20,4) | |
| scale_mode / view_state | enum | |
| is_visible | boolean | legacy `view` |
| sort_order | int | |
| total_bet / total_win | decimal(20,4) | legacy `stat_in` / `stat_out` (running) |
| rounds_count | unsignedBigInt | legacy `bids` |
| timestamps | | |

Unique `(shop_id, template_id)`.

### `categories` ← `w_categories` · `category_game` ← `w_game_categories` · `category_shop` ← `w_shop_categories`
- `categories`: `shop_id` (nullable = global), `parent_id` self-fk, `title`, `slug` (legacy `href`), `position`, `template_id` (legacy `original_id`)
- `category_game`: `category_id`,`game_id`, unique
- `category_shop`: `category_id`,`shop_id`,`position`, unique

---

## C. Liquidity — banks

### `game_banks` ← `w_game_bank` (+ `w_fish_bank` merged in)
Shop-wide pools that fund wins. One row per `(shop, currency)`.

`shop_id`, `currency` char(3), `slots`, `little`, `table_bank`, `bonus`, `fish`
(all `decimal(20,4)`), `temp_rtp` decimal nullable (manual RTP override),
unique `(shop_id, currency)`, timestamps.

Behaviour (from `Lib\Banker` + `GameBank::boot`): each spin's `stake_to_bank`
increments a pool; wins decrement it; when a pool exceeds `shops.player_limit`
the surplus is swept to profit via a `transactions` row (`source = game_bank`,
`direction = debit`).

### `user_banks` ← **reconstructed** (legacy `w_user_bank` not present in the DB)
Optional per-player pool for individual-RTP control — the standard "personal
bank" feature these platforms grow into. Mirrors `game_banks` keyed by user.

`user_id`, `shop_id`, `currency`, `slots`, `little`, `table_bank`, `bonus`,
`fish`, `temp_rtp` nullable, `is_active` boolean (when true, this player's spins
settle against this bank instead of the shop bank), unique `(user_id, currency)`,
timestamps.

> ⚠️ This table is my reconstruction. If `w_user_bank` exists in another install,
> send `SHOW CREATE TABLE w_user_bank` and I'll reconcile.

---

## D. Jackpots

### `jackpots` ← `w_jpg`
| column | type | note |
|---|---|---|
| shop_id | fk→shops nullable | 0 = global template |
| name | string | |
| balance | decimal(20,6) | legacy `balance` (6dp) |
| contribution_percent | decimal(5,2) | legacy `percent` — accrual rate |
| seed_min / seed_max | decimal(20,4) | legacy `start_balance` (was an index into a range array) |
| payout_min / payout_max | decimal(20,4) | legacy `pay_sum` (ditto) |
| last_winner_id | fk→users nullable | legacy `last_winner` (was a name string) |
| last_won_at / last_won_amount | | |
| is_active | boolean | |
| timestamps | | |

### `jackpot_wins` ← new (legacy only kept `last_winner` + a `transactions` row)
`jackpot_id`, `user_id`, `shop_id`, `game_id` nullable, `round_id` nullable,
`amount`, `balance_before`, `won_at`, timestamps.

---

## E. Gameplay

### `game_rounds` ← `w_stat_game`  (one row per spin — financial)
Append-only. ~1.8M legacy rows; grows fast.

| column | type | legacy |
|---|---|---|
| shop_id / user_id | fk | |
| game_id | fk→games nullable | legacy stored game **name** string; we FK + keep `game_code` |
| game_code | string | fallback / legacy join key |
| currency | char(3) | |
| bet / win / balance_after | decimal(20,4) | `bet` / `win` / `balance` |
| stake_to_bank / stake_to_jackpot / stake_to_profit | decimal(20,4) | `in_game` / `in_jpg` / `in_profit` |
| denomination | decimal(20,4) | |
| bank_snapshot | json | `slots_bank`/`bonus_bank`/`fish_bank`/`table_bank`/`little_bank`/`total_bank` |
| status | unsignedTinyInt | |
| played_at | timestamp index | `date_time` |

Indexes: `(shop_id, played_at)`, `(user_id, played_at)`, `(game_id, played_at)`.
No `updated_at`.

### `game_logs` ← `w_game_log`  (raw round payload)
`shop_id`, `user_id`, `game_id` nullable, `ip` string(45), `payload` longText
(legacy `str`), `created_at` only.
Indexes `(user_id, created_at)`, `(game_id, created_at)`, `(shop_id, created_at)`.

**Retention:** legacy never pruned this (7M rows). New: a scheduled job deletes
rows older than N days (config `casino.game_log_retention_days`, default 7).
Candidate for monthly partitioning / a separate archive connection later.

### `game_sessions` ← `w_subsessions`
Tracks the active game browser-tab per user (legacy "check_active_tab").
`user_id`, `token` (legacy `subsession`), `is_active`, `last_seen_at`, timestamps.

---

## F. Money ledger

### `transactions` ← `w_statistics` (+ `w_statistics_add` folded to `accounting` json)
Every balance movement, for any account. This is the audit trail.

| column | type | legacy |
|---|---|---|
| shop_id | fk nullable | |
| user_id | fk | whose balance moved |
| counterparty_id | fk→users nullable | `payeer_id` — who performed it |
| direction | string→`TxnDirection` | `credit` / `debit` (legacy `type` add/out) |
| source | string→`TxnSource` | legacy `system` enum + `bet` / `win` (which legacy kept only in stat_game) |
| amount | decimal(20,4) | `sum` |
| balance_before | decimal(20,4) | `old` |
| secondary_amount | decimal(20,4) nullable | `sum2` (happy-hour) |
| multiplier | unsignedInt default 1 | `hh_multiplier` |
| reference_type / reference_id | nullable | polymorphic (`item_id`) |
| title | string nullable | |
| status | unsignedTinyInt default 1 | |
| context | json | `ip_address`,`user_agent`,`country`,`city`,`os`,`device`,`browser` |
| accounting | json nullable | `w_statistics_add`: `agent_in/out`,`distributor_in/out`,`credit_in/out`,`money_in/out`,`type_in/out` |
| created_at (+ nullable updated_at) | | append-only |

Indexes: `(shop_id, created_at)`, `(user_id, created_at)`, `(source)`,
`(counterparty_id)`, `(reference_type, reference_id)`.

---

## G. Integrations (seamless wallet)

### `api_keys` ← `w_apis`
`shop_id`, `name`, `key` unique, `secret`, `allowed_ips` json (legacy single `ip`),
`callback_url` (legacy `endpoint`), `is_active` (legacy `status`), `last_used_at`,
timestamps.

### `operators` ← `w_operators`
`shop_id` nullable, `operator_ref` (legacy `opid`), `user_check_url` (legacy
`ucurl`), `callback_url` (legacy `cburl`), timestamps.

---

## H. Laravel plumbing

- `sessions` — Laravel's table **+** `country`, `city`, `os`, `device`, `browser`
  (legacy `w_sessions` had these). In the users migration.
- `cache`, `jobs`, `job_batches`, `failed_jobs` — Laravel defaults, unchanged.

---

## Enums (`app/Enums`)

`ShopStatus`, `GameOrder`, `UserStatus`, `GameEngine`, `Device`, `BankType`,
`GameLabel`, `ScaleMode`, `ViewState`, `TxnDirection`, `TxnSource`.

## Models (`app/Models`)

`Shop`, `User`, `Wallet`, `Role`, `Permission`, `Category`, `GameTemplate`,
`Game`, `GameBank`, `UserBank`, `Jackpot`, `JackpotWin`, `GameRound`, `GameLog`,
`GameSession`, `Transaction`, `ApiKey`, `Operator`.

---

## Backlog (next phases, not built yet)

Bonus engine (`happyhours`, `progress` + `progress_users`, `invites` + `rewards`,
`welcomebonuses`, `sms_bonuses` + items, `wheelfortune`), `tournaments` (+ games,
categories, bots, prizes, stats), cashier shifts (`open_shift`), `pincodes`,
payments (`payments`, `credits`, `coinpayment_transactions`, `withdraw_funds`,
`pay_tickets`, `payment_settings`), support (`tickets` + answers), CMS
(`articles`, `faqs`, `rules`, `info`), `notifications`, `messages`, `securities`
(auto-block rules), `user_activity` audit, `settings`, `atm` / NV10 integration,
`tasks`.
