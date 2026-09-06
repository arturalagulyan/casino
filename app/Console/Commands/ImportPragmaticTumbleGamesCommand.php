<?php

namespace App\Console\Commands;

use App\Enums\BankType;
use App\Enums\ClientProtocol;
use App\Enums\Currency;
use App\Enums\GameEngine;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Services\GamePlay\BundleEntryResolver;
use App\Services\GamePlay\BundleManager;
use App\Services\Legacy\TumbleGameParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Port the MODERN Pragmatic Play "gs2c" game family — SweetBonanza,
 * GatesofOlympus, WolfGold, TheDogHouse, … ~100 titles, discovered
 * categorised as "Pragmatic" in the legacy DB despite carrying no `PM`
 * suffix (that's a different, older engine — {@see ImportPragmaticGamesCommand}).
 * Each legacy game folder ships its own copy of a `PragmaticLib` math engine
 * rather than one shared class, and the math model itself varies by family
 * (scatter-pays + tumble/cascade for Sweet-Bonanza-style titles, classic
 * paylines + bespoke features for Wolf-Gold-style titles, megaways for
 * others) — so this command, for now, only knows the tumble family
 * ({@see TumbleEngine}), piloted on SweetBonanza. Running it against a title
 * from a different family will import config that {@see TumbleEngine}
 * cannot play correctly; `--only` defaults to the known-good pilot list.
 *
 * Package shape: `games-backend/<Code>/{Server.php,init.php}` (no
 * SlotSettings.php / reels.txt — {@see EgtGameParser} doesn't apply here),
 * `games-frontend/<Code>/gs2c/html5Game.html` (entry auto-resolves via
 * {@see BundleEntryResolver}). `client_protocol` is
 * `pragmatic_tumble`.
 *
 *   php artisan pragmatic-tumble:import
 *   php artisan pragmatic-tumble:import --only=SweetBonanza,FruitParty
 *   php artisan pragmatic-tumble:import --dry-run
 */
class ImportPragmaticTumbleGamesCommand extends Command
{
    protected $signature = 'pragmatic-tumble:import
        {--only= : Comma list of game codes to (re)import}
        {--skip-bundles : Do not (re)upload front-end bundles}
        {--fresh-bundles : Re-upload bundles even when one is already active}
        {--dry-run : Parse and report, write nothing}';

    protected $description = 'Import modern Pragmatic Play "gs2c" tumble-family games (pilot: SweetBonanza)';

    /** Titles confirmed scatter-pays/tumble (same family as the SweetBonanza pilot) — the only ones this importer's math actually fits so far. */
    private const array KNOWN_TUMBLE_TITLES = ['SweetBonanza'];

    /** Legacy shop id → rebuild shop name, with the per-shop win cap (× bet) to apply. */
    private const array SHOP_MAP = [
        13 => ['name' => 'Bilion07', 'max_win_multiplier' => 50],
        14 => ['name' => 'Better365', 'max_win_multiplier' => 20],
    ];

    public function handle(BundleManager $bundles): int
    {
        $backend = (string) config('legacy.games_backend_path');
        $frontend = (string) config('legacy.games_frontend_path');
        $icons = (string) config('legacy.games_icons_path');

        if (! is_dir($backend)) {
            $this->error("Legacy backend games not found at {$backend} (LEGACY_GAMES_BACKEND_PATH).");

            return self::FAILURE;
        }

        $legacyOk = $this->legacyReachable();
        if (! $legacyOk) {
            $this->warn('Legacy DB unreachable — bet ladders will fall back to the game\'s own `sc=` config.');
        }

        $pragmatic = Category::firstOrCreate(
            ['slug' => 'pragmatic'],
            ['title' => 'Pragmatic Play', 'position' => 6],
        );

        /** @var array<int, Shop> $shops legacy-shop-id => rebuild Shop */
        $shops = [];
        foreach (self::SHOP_MAP as $legacyId => $meta) {
            $shop = Shop::query()->where('name', $meta['name'])->first();
            if (! $shop) {
                $this->warn("Rebuild shop '{$meta['name']}' not found — skipping its games.");

                continue;
            }
            $shops[$legacyId] = $shop;
            /** @var GameBank $bank */
            $bank = $shop->banks()->firstOrCreate(['currency' => $shop->currency->value]);
            if ((float) $bank->slots < 50_000) {
                $bank->forceFill(['slots' => 250_000])->save();
            }
        }

        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $codes = $only ?: self::KNOWN_TUMBLE_TITLES;

        $dry = (bool) $this->option('dry-run');
        $done = $failed = 0;
        $report = [];

        foreach ($codes as $code) {
            $initPath = $backend.'/'.$code.'/init.php';
            if (! is_file($initPath)) {
                $this->line("  <fg=red>✗</> {$code} — no init.php at {$initPath}");
                $failed++;

                continue;
            }

            try {
                $parser = new TumbleGameParser($initPath);
                $mult = $parser->multiplier();
                $title = Str::title(preg_replace('/(?<!^)(?=[A-Z0-9])/', ' ', $code));

                $reelStrips = [];
                foreach ($parser->reelStrips(0) as $i => $strip) {
                    $reelStrips['reelStrip'.($i + 1)] = $strip;
                }
                foreach ($parser->reelStrips(1) as $i => $strip) {
                    $reelStrips['reelStripBonus'.($i + 1)] = $strip;
                }

                $attrs = [
                    'reel_count' => $parser->reelCount(),
                    'row_count' => $parser->rowCount(),
                    'symbol_count' => count($parser->symbols()),
                    'symbols' => $parser->symbols(),
                    'paytable' => $parser->paytable(),
                    'reel_strips' => $reelStrips,
                    'scatter_symbol' => $parser->scatterSymbol(),
                    'has_free_spins' => true,
                    'free_spins_count' => $parser->freeSpinsCount(),
                    'default_bet_options' => $parser->betOptions(),
                    'tumble_config' => [
                        'multiplier_symbol' => $mult['symbol'],
                        'multiplier_values' => $mult['values'],
                        'lines' => $parser->lines(),
                        'needaddfs' => $parser->needAddFreeSpins(),
                        'addfs' => $parser->addFreeSpins(),
                        'free_spins' => $parser->freeSpinsCount(),
                        'scatter_paytable' => $parser->scatterPaytable(),
                        'raw' => $parser->raw(),
                    ],
                ];

                $report[$code] = [
                    'grid' => $attrs['reel_count'].'x'.$attrs['row_count'],
                    'syms' => $attrs['symbol_count'],
                    'scat' => $attrs['scatter_symbol'],
                    'mult' => $mult['symbol'],
                    'fs' => $attrs['free_spins_count'],
                    'bets' => count($attrs['default_bet_options']),
                    'family' => in_array($code, self::KNOWN_TUMBLE_TITLES, true) ? 'tumble' : 'UNVERIFIED',
                ];

                if ($dry) {
                    $done++;

                    continue;
                }

                $poster = $this->copyPoster($icons, $code);

                $template = GameTemplate::updateOrCreate(
                    ['code' => $code],
                    array_merge($attrs, array_filter([
                        'title' => $title,
                        'engine' => GameEngine::Internal,
                        'device' => 'both',
                        'bank_type' => BankType::Slots,
                        'client_protocol' => ClientProtocol::PragmaticTumble,
                        'pricing_currency' => Currency::USD,
                        'default_denomination' => 1,
                        'poster_path' => $poster,
                        'is_active' => true,
                    ], fn ($v) => $v !== null)),
                );

                if (! $this->option('skip-bundles') && ($this->option('fresh-bundles') || ! $template->activeBundle)) {
                    $src = $frontend.'/'.$code;
                    if (is_dir($src)) {
                        $bundle = $bundles->storeFromDirectory(
                            $template, $src,
                            notes: 'Pragmatic Play "gs2c" HTML5 front-end (pragmatic-tumble:import).',
                        );
                        $report[$code]['bundle'] = "v{$bundle->version}/{$bundle->file_count}f".($bundle->entry ? " ({$bundle->entry})" : ' (unresolved entry)');
                    } else {
                        $report[$code]['bundle'] = 'MISSING SRC';
                    }
                }

                foreach ($shops as $legacyId => $shop) {
                    $this->upsertGame($template, $shop, $legacyId, $pragmatic, $attrs, $legacyOk);
                }

                $done++;
                $this->line("  <fg=green>✓</> {$code} — {$title}");
            } catch (\Throwable $e) {
                $failed++;
                $this->line("  <fg=red>✗</> {$code} — {$e->getMessage()}");
                $report[$code]['error'] = $e->getMessage();
            }
        }

        $this->newLine();
        $this->table(
            ['code', 'grid', 'syms', 'scat', 'mult', 'fs', 'bets', 'family', 'bundle', 'notes'],
            collect($report)->map(fn ($r, $c) => [
                $c, $r['grid'] ?? '', $r['syms'] ?? '', $r['scat'] ?? '', $r['mult'] ?? '',
                $r['fs'] ?? '', $r['bets'] ?? '', $r['family'] ?? '', $r['bundle'] ?? '', $r['error'] ?? '',
            ])->values()->all(),
        );

        $this->info(sprintf('%s done=%d  failed=%d', $dry ? 'Dry run —' : 'Pragmatic-tumble import', $done, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Create/refresh the per-shop game row, tagged Pragmatic, with legacy tuning. */
    private function upsertGame(GameTemplate $template, Shop $shop, int $legacyShopId, Category $pragmatic, array $attrs, bool $legacyOk): void
    {
        $legacy = $legacyOk ? $this->legacyGameRow($template->code, $legacyShopId) : null;
        $rtp = (int) ($legacyOk ? ($this->legacyShopPercent($legacyShopId) ?? 90) : 90);

        $game = Game::updateOrCreate(
            ['shop_id' => $shop->id, 'template_id' => $template->id],
            [
                'title' => $template->title,
                'bank_type' => BankType::Slots,
                'rtp_percent' => $rtp,
                'max_win_multiplier' => self::SHOP_MAP[$legacyShopId]['max_win_multiplier'],
                'reserve_percent' => (int) ($legacy->rezerv ?? 4) ?: 4,
                'bet_options' => $attrs['default_bet_options'],
                'denomination' => 1,
                'pricing_currency' => Currency::USD,
                'is_visible' => (bool) ($legacy->view ?? true),
            ],
        );

        $game->categories()->syncWithoutDetaching([$pragmatic->id]);
    }

    // ---- legacy DB (bet ladder / shop RTP only — everything else comes from init.php) ---

    private function legacyReachable(): bool
    {
        try {
            DB::connection('legacy')->table('games')->limit(1)->get();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private array $legacyRowCache = [];

    private function legacyGameRow(string $code, int $shopId): ?object
    {
        $key = $code.'@'.$shopId;
        if (! array_key_exists($key, $this->legacyRowCache)) {
            try {
                $this->legacyRowCache[$key] = DB::connection('legacy')->table('games')
                    ->where('name', $code)->where('shop_id', $shopId)->first();
            } catch (\Throwable) {
                $this->legacyRowCache[$key] = null;
            }
        }

        return $this->legacyRowCache[$key];
    }

    private array $shopPercentCache = [];

    private function legacyShopPercent(int $shopId): ?int
    {
        if (! array_key_exists($shopId, $this->shopPercentCache)) {
            try {
                $v = DB::connection('legacy')->table('shops')->where('id', $shopId)->value('percent');
                $this->shopPercentCache[$shopId] = $v !== null ? (int) $v : null;
            } catch (\Throwable) {
                $this->shopPercentCache[$shopId] = null;
            }
        }

        return $this->shopPercentCache[$shopId];
    }

    // ---- poster -------------------------------------------------

    private function copyPoster(string $iconsDir, string $code): ?string
    {
        $src = $iconsDir.'/'.$code.'.jpg';
        if (! is_file($src)) {
            return null;
        }

        $rel = 'game-posters/'.$code.'.jpg';
        Storage::disk('public')->put($rel, (string) file_get_contents($src));

        return $rel;
    }
}
