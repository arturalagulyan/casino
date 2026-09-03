<?php

namespace App\Services\Legacy;

use App\Enums\Currency;
use App\Models\ApiKey;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameTemplate;
use App\Models\Jackpot;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migrates a legacy VanguardLTE ("w_" prefix) casino database into the rebuild
 * schema. Reads from the read-only `legacy` connection (see config/database.php)
 * and writes with the normal models.
 *
 * Idempotent: everything is upserted by natural key (shop slug, username,
 * template code, …) so it can be re-run. Legacy ids are kept in a `legacy_id`
 * map column where the schema has one, otherwise resolved by natural key.
 *
 *   php artisan import:legacy [--only=shops,users,…] [--fresh] [--dry-run]
 */
class LegacyImport
{
    /** legacy shop_id => rebuild Shop id */
    private array $shopMap = [];

    /** legacy user_id => rebuild User id */
    private array $userMap = [];

    /** legacy category_id => rebuild Category id */
    private array $categoryMap = [];

    /** legacy jpg_id => rebuild Jackpot id */
    private array $jackpotMap = [];

    /** game code => rebuild GameTemplate id */
    private array $templateMap = [];

    /** legacy shop ids that still exist in w_shops — everything else is orphaned junk from deleted shops */
    private array $liveShopIds = [];

    private bool $dryRun = false;

    public function __construct(private readonly OutputStyle $out) {}

    /** @param list<string> $only  entities to run (empty = all, in order) */
    public function run(array $only = [], bool $dryRun = false): void
    {
        $this->dryRun = $dryRun;

        $steps = [
            'shops' => fn () => $this->shops(),
            'categories' => fn () => $this->categories(),
            'banks' => fn () => $this->gameBanks(),
            'jackpots' => fn () => $this->jackpots(),
            'users' => fn () => $this->users(),
            'apikeys' => fn () => $this->apiKeys(),
            'operators' => fn () => $this->operators(),
            'games' => fn () => $this->games(),
        ];

        // A normal run is NOT wrapped in one big transaction — every write is an
        // idempotent updateOrCreate, and 2k+ users in a single transaction just
        // deadlocks. --dry-run wraps the lot so it can roll back.
        $body = function () use ($steps, $only) {
            $this->loadMaps();

            foreach ($steps as $name => $step) {
                if ($only !== [] && ! in_array($name, $only, true)) {
                    continue;
                }
                $this->out->section("Importing {$name}");
                $step();
            }
        };

        if ($this->dryRun) {
            // DryRunRollback rolls the transaction back and propagates to the command.
            DB::transaction(function () use ($body) {
                $body();
                throw new DryRunRollback;
            });
        }

        $body();
    }

    /**
     * Populate the legacy-id → rebuild-id maps from what's already in the DB,
     * matched by natural key, so a partial re-run (`--only=games`) still resolves
     * cross-references.
     */
    private function loadMaps(): void
    {
        $this->liveShopIds = array_map('intval', $this->legacy('shops')->pluck('id')->all());

        foreach (Shop::pluck('id', 'slug') as $slug => $id) {
            if (preg_match('/-(\d+)$/', (string) $slug, $m)) {
                $this->shopMap[(int) $m[1]] = $id;
            }
        }

        $rebuiltCats = Category::get(['id', 'shop_id', 'slug'])->keyBy(
            fn ($c) => ($c->shop_id ?? 0).'|'.$c->slug,
        );
        foreach ($this->legacy('categories')->get() as $c) {
            $shopId = $c->shop_id ? ($this->shopMap[$c->shop_id] ?? 0) : 0;
            $key = $shopId.'|'.($c->href ?: Str::slug($c->title));
            if (isset($rebuiltCats[$key])) {
                $this->categoryMap[$c->id] = $rebuiltCats[$key]->id;
            }
        }

        $rebuiltJp = Jackpot::get(['id', 'shop_id', 'name'])->keyBy(fn ($j) => ($j->shop_id ?? 0).'|'.$j->name);
        foreach ($this->legacy('jpg')->get() as $j) {
            $shopId = $j->shop_id ? ($this->shopMap[$j->shop_id] ?? 0) : 0;
            $key = $shopId.'|'.($j->name ?: "JPG {$j->id}");
            if (isset($rebuiltJp[$key])) {
                $this->jackpotMap[$j->id] = $rebuiltJp[$key]->id;
            }
        }

        $this->templateMap = GameTemplate::pluck('id', 'code')->all();

        $rebuiltUsers = User::get(['id', 'shop_id', 'username'])->keyBy(fn ($u) => ($u->shop_id ?? 0).'|'.$u->username);
        foreach ($this->legacy('users')->get(['id', 'shop_id', 'username']) as $u) {
            $shopId = $this->shopMap[$u->shop_id] ?? 0;
            $key = $shopId.'|'.($u->username ?: "legacy-{$u->id}");
            if (isset($rebuiltUsers[$key])) {
                $this->userMap[$u->id] = $rebuiltUsers[$key]->id;
            }
        }
    }

    // ---- shops -------------------------------------------------

    private function shops(): void
    {
        foreach ($this->legacy('shops')->get() as $s) {
            $shop = Shop::updateOrCreate(
                ['slug' => Str::slug($s->name).'-'.$s->id],
                [
                    'name' => $s->name,
                    'frontend' => $s->frontend ?: 'default',
                    'currency' => $this->currency($s->currency ?: 'EUR'),
                    'balance' => $s->balance,
                    'status' => $s->is_blocked ? 'blocked' : ($s->pending ? 'pending' : 'active'),
                    'rtp_percent' => max(1, min(100, (int) $s->percent)),
                    'max_win_multiplier' => max(1, (int) $s->max_win),
                    'player_limit' => $s->shop_limit,
                    'order_by' => strtolower($s->orderby ?: 'az'),
                    'allowed_countries' => $this->csv($s->country),
                    'allowed_os' => $this->csv($s->os),
                    'allowed_devices' => $this->csv($s->device),
                    'happy_hours_enabled' => (bool) $s->happyhours_active,
                    'progress_enabled' => (bool) $s->progress_active,
                    'invites_enabled' => (bool) $s->invite_active,
                    'welcome_bonuses_enabled' => (bool) $s->welcome_bonuses_active,
                    'sms_bonuses_enabled' => (bool) $s->sms_bonuses_active,
                    'wheel_fortune_enabled' => (bool) ($s->wheelfortune_active ?? 1),
                ],
            );
            $this->shopMap[$s->id] = $shop->id;
            $this->tick($shop->name);
        }
    }

    // ---- categories -------------------------------------------

    private function categories(): void
    {
        $rows = $this->legacy('categories')->orderBy('parent')->orderBy('position')->get();

        foreach ($rows as $c) {
            $shopId = $c->shop_id ? ($this->shopMap[$c->shop_id] ?? null) : null;
            $cat = Category::updateOrCreate(
                ['shop_id' => $shopId, 'slug' => $c->href ?: Str::slug($c->title)],
                [
                    'title' => $c->title,
                    'position' => $c->position,
                    'parent_id' => $c->parent ? ($this->categoryMap[$c->parent] ?? null) : null,
                    'config' => $this->categoryConfig($c->title),
                ],
            );
            $this->categoryMap[$c->id] = $cat->id;
            $this->tick($c->title);
        }

        // shop <-> category visibility pivot
        foreach ($this->legacy('shop_categories')->where('category_id', '>', 0)->whereIn('shop_id', $this->liveShopIds)->get() as $sc) {
            $shopId = $this->shopMap[$sc->shop_id] ?? null;
            $catId = $this->categoryMap[$sc->category_id] ?? null;
            if ($shopId && $catId && ! $this->dryRun) {
                DB::table('category_shop')->updateOrInsert(
                    ['shop_id' => $shopId, 'category_id' => $catId],
                    ['updated_at' => now(), 'created_at' => now()],
                );
            }
        }
    }

    /** EGT games speak the GamePlatform WebSocket; the rest use the HTTP bridge. */
    private function categoryConfig(string $title): ?array
    {
        return Str::lower($title) === 'egt'
            ? ['client_protocol' => 'game_platform']
            : null;
    }

    // ---- game banks ------------------------------------------

    private function gameBanks(): void
    {
        foreach ($this->legacy('game_bank')->whereIn('shop_id', $this->liveShopIds)->get() as $b) {
            $shopId = $this->shopMap[$b->shop_id] ?? null;
            if (! $shopId) {
                continue;
            }
            $bank = GameBank::updateOrCreate(
                ['shop_id' => $shopId, 'currency' => $this->currency($b->currency ?: 'EUR')],
                [
                    'slots' => $b->slots,
                    'little' => $b->little,
                    'table_bank' => $b->table_bank,
                    'bonus' => $b->bonus,
                    'temp_rtp' => $b->temp_rtp,
                ],
            );
            $this->tick("bank {$bank->currency->value}");
        }
    }

    // ---- jackpots --------------------------------------------

    private function jackpots(): void
    {
        foreach ($this->legacy('jpg')->whereIn('shop_id', $this->liveShopIds)->get() as $j) {
            $shopId = $this->shopMap[$j->shop_id] ?? null;
            if (! $shopId) {
                continue;
            }
            $jp = Jackpot::updateOrCreate(
                ['shop_id' => $shopId, 'name' => $j->name ?: "JPG {$j->id}"],
                [
                    'balance' => $j->balance,
                    'contribution_percent' => $j->percent,
                    'seed_min' => $j->start_balance,
                    'payout_min' => $j->pay_sum,
                    'is_active' => true,
                ],
            );
            $this->jackpotMap[$j->id] = $jp->id;
            $this->tick($jp->name);
        }
    }

    // ---- users ----------------------------------------------

    private function users(): void
    {
        $roleByLevel = Role::pluck('id', 'level')->all();
        $legacyRoleLevel = $this->legacy('roles')->pluck('level', 'id')->all();
        $shopCurrency = Shop::pluck('currency', 'id')->all();
        $roleUserSeen = [];
        foreach (DB::table('role_user')->get(['user_id', 'role_id']) as $ru) {
            $roleUserSeen["{$ru->user_id}-{$ru->role_id}"] = true;
        }

        $this->legacy('users')->orderBy('id')->chunkById(1000, function ($chunk) use ($roleByLevel, $legacyRoleLevel, $shopCurrency, &$roleUserSeen) {
            $roleRows = [];
            $walletRows = [];

            foreach ($chunk as $u) {
                $shopId = $this->shopMap[$u->shop_id] ?? null;
                if (! $shopId && $u->role_id < 6) {
                    continue; // orphaned non-admin
                }
                $level = (int) ($legacyRoleLevel[$u->role_id] ?? 1);
                $roleId = $roleByLevel[$level] ?? null;
                $currency = $u->currency ? Currency::tryFrom(strtoupper(trim($u->currency)))?->value : null;

                $user = User::updateOrCreate(
                    ['shop_id' => $shopId, 'username' => $u->username ?: "legacy-{$u->id}"],
                    [
                        'role_id' => $roleId,
                        'email' => $u->email ?: null,
                        'password' => $u->password,   // already bcrypt
                        'first_name' => $u->first_name ?: null,
                        'last_name' => $u->last_name ?: null,
                        'phone' => $u->phone ?: null,
                        'currency' => $currency,
                        'language' => Str::substr($u->language ?: 'en', 0, 5),
                        'status' => $this->userStatus($u->status),
                        'is_blocked' => (bool) $u->is_blocked,
                        'is_demo_agent' => (bool) $u->is_demo_agent,
                        'free_demo' => (bool) $u->free_demo,
                        'external_provider' => $u->api ? 'legacy-api' : null,
                        'external_player_id' => $u->player ?: null,
                        'last_login_at' => $u->last_login,
                    ],
                );
                $this->userMap[$u->id] = $user->id;

                if ($roleId && ! isset($roleUserSeen["{$user->id}-{$roleId}"])) {
                    $roleRows[] = ['user_id' => $user->id, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()];
                    $roleUserSeen["{$user->id}-{$roleId}"] = $user->id;
                }

                $walletRows[] = [
                    'user_id' => $user->id,
                    'currency' => $currency ?? ($shopCurrency[$shopId] ?? 'EUR'),
                    'balance' => $u->balance,
                    'locked' => $u->address,
                    'total_deposited' => $u->total_in,
                    'total_withdrawn' => $u->total_out,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $this->tick($user->username);
            }

            if ($roleRows) {
                DB::table('role_user')->insertOrIgnore($roleRows);
            }
            if ($walletRows) {
                Wallet::upsert($walletRows, ['user_id'], ['currency', 'balance', 'locked', 'total_deposited', 'total_withdrawn', 'updated_at']);
            }
        });

        // second pass: parent_id hierarchy (legacy parent_id can point forward)
        $updates = [];
        foreach ($this->legacy('users')->whereNotNull('parent_id')->where('parent_id', '>', 0)->get(['id', 'parent_id']) as $u) {
            $id = $this->userMap[$u->id] ?? null;
            $parent = $this->userMap[$u->parent_id] ?? null;
            if ($id && $parent) {
                $updates[$parent][] = $id;
            }
        }
        foreach ($updates as $parent => $ids) {
            User::whereIn('id', $ids)->update(['parent_id' => $parent]);
        }
    }

    // ---- api keys ------------------------------------------

    private function apiKeys(): void
    {
        foreach ($this->legacy('apis')->whereIn('shop_id', $this->liveShopIds)->get() as $a) {
            $shopId = $this->shopMap[$a->shop_id] ?? null;
            if (! $shopId) {
                continue;
            }
            $key = ApiKey::updateOrCreate(
                ['key' => $a->keygen],
                [
                    'shop_id' => $shopId,
                    'name' => 'Legacy API '.$a->id,
                    'allowed_ips' => $this->csv($a->ip),
                    'callback_url' => $a->endpoint ?: null,
                    'is_active' => (bool) $a->status,
                ],
            );
            $this->tick($key->name);
        }
    }

    // ---- operators (seamless-wallet endpoints) -------------

    private function operators(): void
    {
        // legacy w_operators is un-keyed and full of near-dup rows — collapse by opid, last wins
        foreach ($this->legacy('operators')->orderBy('id')->get() as $o) {
            $ref = trim((string) $o->opid);
            if ($ref === '') {
                continue;
            }
            Operator::updateOrCreate(
                ['operator_ref' => $ref],
                [
                    'user_check_url' => $o->ucurl ?: null,
                    'callback_url' => $o->cburl ?: null,
                ],
            );
            $this->tick("operator {$ref}");
        }
    }

    // ---- games (templates + per-shop instances) ------------

    private function games(): void
    {
        $reader = app(LegacyGameReader::class);

        // 1) templates — one per distinct code (prefer the shop_id = 0 "installed" rows)
        $codes = $this->legacy('games')
            ->whereIn('shop_id', array_merge([0], $this->liveShopIds))
            ->orderByRaw('shop_id = 0 DESC')->get()
            ->unique('name')->values();

        foreach ($codes as $g) {
            $spec = $reader->spec($g->name);
            $tpl = GameTemplate::updateOrCreate(
                ['code' => $g->name],
                array_merge([
                    'title' => $g->title ?: $g->name,
                    'engine' => 'internal',
                    'device' => $this->device($g->device),
                    'bank_type' => $this->bankType($g->gamebank),
                    'client_protocol' => Str::endsWith($g->name, 'EGT') ? 'game_platform' : null,
                    'default_bet_options' => $this->betOptions($g->bet),
                    'default_denomination' => $g->denomination ?: 1,
                    'gamble_win_chance' => (int) ($g->rezerv ?: 4),
                    'win_chances' => $this->winChances($g),
                    'default_jackpot_chances' => $this->firepots($g),
                    'scale_mode' => $g->scaleMode ?: null,
                    'view_state' => $g->slotViewState ?: null,
                    'poster_path' => $reader->poster($g->name),
                    'is_active' => true,
                ], $spec),
            );
            $this->templateMap[$g->name] = $tpl->id;
            $this->tick($g->name);
        }

        // 2) per-shop game instances
        foreach ($this->legacy('games')->whereIn('shop_id', $this->liveShopIds)->get() as $g) {
            $shopId = $this->shopMap[$g->shop_id] ?? null;
            $tplId = $this->templateMap[$g->name] ?? null;
            if (! $shopId || ! $tplId) {
                continue;
            }

            $game = Game::updateOrCreate(
                ['shop_id' => $shopId, 'template_id' => $tplId],
                [
                    'title' => $g->title ?: null,
                    'label' => $this->label($g->label),
                    'jackpot_id' => $g->jpg_id ? ($this->jackpotMap[$g->jpg_id] ?? null) : null,
                    'bank_type' => $this->bankType($g->gamebank),
                    'reserve_percent' => (int) ($g->rezerv ?: 0),
                    'cask' => (int) ($g->cask ?: 0),
                    'win_chances' => $this->winChances($g),
                    'jackpot_chances' => $this->firepots($g),
                    'lines_config_spin' => $this->json($g->lines_percent_config_spin),
                    'lines_config_spin_bonus' => $this->json($g->lines_percent_config_spin_bonus),
                    'lines_config_bonus' => $this->json($g->lines_percent_config_bonus),
                    'lines_config_bonus_bonus' => $this->json($g->lines_percent_config_bonus_bonus),
                    'advanced' => $this->maybeSerialized($g->advanced),
                    'bet_options' => $this->betOptions($g->bet_ALL ?: $g->bet),
                    'denomination' => $g->denomination ?: 1,
                    'scale_mode' => $g->scaleMode ?: null,
                    'view_state' => $g->slotViewState ?: null,
                    'is_visible' => (bool) $g->view,
                    'sort_order' => 0,
                    'total_bet' => $g->stat_in,
                    'total_win' => $g->stat_out,
                    'rounds_count' => $g->bids,
                ],
            );

            // category membership (legacy category_temp = "3,35")
            $catIds = collect(explode(',', (string) $g->category_temp))
                ->map(fn ($id) => $this->categoryMap[(int) trim($id)] ?? null)
                ->filter()->all();
            if ($catIds && ! $this->dryRun) {
                $game->categories()->syncWithoutDetaching($catIds);
            }

            $this->tick("{$g->name} @ shop {$g->shop_id}");
        }
    }

    // ---- value mappers -------------------------------------

    /** Legacy lines_percent_config_{spin,bonus} → our {spin:{lineN:{band:N}}, bonus:{…}}. */
    private function winChances(object $g): ?array
    {
        $spin = $this->json($g->lines_percent_config_spin ?? null);
        $bonus = $this->json($g->lines_percent_config_bonus ?? null);
        if (! $spin && ! $bonus) {
            return null;
        }

        return array_filter([
            'spin' => $this->stripBonusKeys($spin),
            'bonus' => $this->stripBonusKeys($bonus),
        ]);
    }

    /** legacy sometimes keys "line10_bonus" — normalise to "line10". */
    private function stripBonusKeys(?array $cfg): ?array
    {
        if (! $cfg) {
            return null;
        }
        $out = [];
        foreach ($cfg as $k => $v) {
            $out[str_replace('_bonus', '', (string) $k)] = array_map('intval', (array) $v);
        }

        return $out;
    }

    private function firepots(object $g): ?array
    {
        $out = [];
        foreach ([1, 2, 3] as $i) {
            $chance = (int) ($g->{"chanceFirepot{$i}"} ?? 0);
            if ($chance > 0) {
                $out["chance{$i}"] = $chance;
                $out["count{$i}"] = (int) ($g->{"fireCount{$i}"} ?? 0);
            }
        }

        return $out ?: null;
    }

    /** "0.10, 0.20, ..., 10.00" or "10, 20, 50, 100" → numeric list. */
    private function betOptions(?string $bet): ?array
    {
        if (! $bet) {
            return null;
        }
        $vals = collect(explode(',', $bet))
            ->map(fn ($v) => (float) trim($v))
            ->filter(fn ($v) => $v > 0)
            ->values();

        return $vals->isEmpty() ? null : $vals->map(fn ($v) => $v == (int) $v ? (int) $v : $v)->all();
    }

    /** Legacy w_games.gamebank ('table_bank') → BankType value ('table'). */
    private function bankType(?string $b): string
    {
        return match ($b) {
            'table_bank', 'table' => 'table',
            'little' => 'little',
            'fish' => 'fish',
            'bonus' => 'bonus',
            default => 'slots',
        };
    }

    private function device(int|string|null $d): string
    {
        return match ((int) $d) {
            1 => 'desktop',
            2 => 'mobile',
            default => 'both',
        };
    }

    private function label(?string $l): ?string
    {
        return match (Str::lower((string) $l)) {
            'new' => 'new',
            'hot' => 'hot',
            'exclusive' => 'exclusive',
            default => null,
        };
    }

    private function userStatus(?string $s): string
    {
        return match (Str::lower((string) $s)) {
            'active' => 'active',
            'banned', 'blocked' => 'banned',
            'inactive' => 'inactive',
            default => 'unconfirmed',
        };
    }

    private function currency(?string $c): string
    {
        $c = strtoupper(trim((string) $c));
        $c = match ($c) {
            'LEK' => 'ALL',
            'XOF', 'XAF' => 'CFA',
            default => $c,
        };

        return (Currency::tryFrom($c) ?? Currency::EUR)->value;
    }

    private function csv(?string $v): ?array
    {
        if (! $v) {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $v))));

        return $parts ?: null;
    }

    private function json(?string $v): ?array
    {
        if (! $v) {
            return null;
        }
        $d = json_decode($v, true);

        return is_array($d) ? $d : null;
    }

    private function maybeSerialized(?string $v): ?array
    {
        if (! $v) {
            return null;
        }
        $d = @unserialize($v, ['allowed_classes' => false]);

        return is_array($d) ? $d : $this->json($v);
    }

    // ---- infra ---------------------------------------------

    private function legacy(string $table): Builder
    {
        return DB::connection('legacy')->table($table);
    }

    private function tick(string $label): void
    {
        if ($this->out->isVerbose()) {
            $this->out->writeln("  <fg=gray>·</> {$label}");
        }
    }
}
