<?php

namespace App\Console\Commands;

use App\Enums\BankType;
use App\Enums\ClientProtocol;
use App\Enums\Currency;
use App\Enums\GameEngine;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Services\GamePlay\BundleEntryResolver;
use App\Services\GamePlay\BundleManager;
use App\Services\Legacy\EgtGameParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Port the legacy EGT "GamePlatform" games into the rebuild as pure DB config.
 *
 * For each `games-backend/<Code>EGT/` package it:
 *   1. parses SlotSettings.php + Server.php + reels.txt  ({@see EgtGameParser})
 *   2. pulls the win-chance tables (lines_percent_config_*) from the legacy DB
 *   3. writes/updates a `game_templates` row (all engine numbers as JSON)
 *   4. uploads the `games-frontend/<Code>` folder as the active bundle
 *   5. copies the `games-icons/<Code>.jpg` poster
 *   6. creates/updates a `games` row per live shop, tagged "Egt", with that
 *      shop's legacy RTP / reserve / bet ladder
 *   7. reads each bundle's real gameType into layout['egt'] (egt:extract-layout)
 *
 *   php artisan egt:import
 *   php artisan egt:import --only=BurningHot20EGT,ShiningCrownEGT
 *   php artisan egt:import --skip-bundles          # config only, keep bundles
 *   php artisan egt:import --fresh-bundles         # re-upload even if present
 */
class ImportEgtGamesCommand extends Command
{
    protected $signature = 'egt:import
        {--only= : Comma list of game codes to (re)import}
        {--skip-bundles : Do not (re)upload front-end bundles}
        {--fresh-bundles : Re-upload bundles even when one is already active}
        {--dry-run : Parse and report, write nothing}';

    protected $description = 'Import the legacy EGT GamePlatform games as DB-driven templates + per-shop games';

    /** Legacy shop id → rebuild shop name, with the per-shop win cap (× bet) to apply. */
    private const array SHOP_MAP = [
        13 => ['name' => 'Bilion07', 'max_win_multiplier' => 50],
        14 => ['name' => 'Better365', 'max_win_multiplier' => 20],
    ];

    /** Symbol-role fixes the generic parser can't infer (multi-trigger games). */
    private const array SYMBOL_OVERRIDES = [
        // Action Money: scatter 10 → pick-multiplier free spins, bonus 9 → bank pick.
        'ActionMoneyEGT' => ['scatter_symbol' => 10, 'bonus_symbol' => 9],
    ];

    /**
     * Games whose feature is richer than "scatter → free spins" — the generic
     * parser can't infer these, so the bonus_config is spelled out here.
     */
    private const array BONUS_OVERRIDES = [
        'ActionMoneyEGT' => [
            'triggers' => [
                '10' => ['flow' => 'pick_multiplier_freespins', 'min' => 3],
                '9' => ['flow' => 'pick_money', 'min' => 3],
            ],
            'pick_multiplier_freespins' => [
                'multiplier_range' => [1, 5], 'multiplier_picks' => 4,
                'free_spins_range' => [5, 12], 'extra_wild_range' => [5, 7], 'freespin_picks' => 8,
            ],
            'pick_money' => [
                'multipliers' => [2, 2, 2, 2, 2, 2, 4, 4, 4, 6, 6, 6, 6, 8, 8, 8, 8, 10, 12, 14],
                'picks' => 3, 'extra_pick_at' => ['4' => 4, '5' => 5],
            ],
            'gamble' => ['type' => 'red_black', 'steps' => 5],
        ],
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
            $this->warn('Legacy DB unreachable — win-chance tables will fall back to defaults.');
        }

        $egt = Category::firstOrCreate(
            ['slug' => 'egt'],
            ['title' => 'Egt', 'position' => 3, 'config' => ['client_protocol' => 'game_platform']],
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
            // Make sure the slots pool can pay.
            /** @var \App\Models\GameBank $bank */
            $bank = $shop->banks()->firstOrCreate(['currency' => $shop->currency->value]);
            if ((float) $bank->slots < 50_000) {
                $bank->forceFill(['slots' => 250_000])->save();
            }
        }

        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $codes = $only ?: collect(File::directories($backend))
            ->map(fn ($d) => basename($d))
            ->filter(fn ($c) => Str::endsWith($c, 'EGT'))
            ->sort()
            ->values()
            ->all();

        $dry = (bool) $this->option('dry-run');
        $resolver = new BundleEntryResolver;

        $done = $skipped = $failed = 0;
        $report = [];

        foreach ($codes as $code) {
            $dir = $backend.'/'.$code;
            $parser = EgtGameParser::fromDir($dir, $code);

            if (! $parser || ! $parser->isLineSlot()) {
                $this->line("  <fg=gray>—</> {$code} — not a line/dice slot, skipped");
                $skipped++;

                continue;
            }

            try {
                $attrs = $parser->templateAttributes();
                if (isset(self::SYMBOL_OVERRIDES[$code])) {
                    $attrs = array_merge($attrs, self::SYMBOL_OVERRIDES[$code]);
                }
                if (isset(self::BONUS_OVERRIDES[$code])) {
                    $attrs['bonus_config'] = self::BONUS_OVERRIDES[$code];
                }

                $winChances = $legacyOk ? $this->winChances($code) : null;

                $title = $resolver->prettyName($code);
                $poster = $dry ? null : $this->copyPoster($icons, $code);

                $report[$code] = [
                    'reels' => $attrs['reel_count'].'x'.$attrs['row_count'],
                    'syms' => $attrs['symbol_count'],
                    'wild' => $attrs['wild_symbol'],
                    'scatter' => $attrs['scatter_symbol'],
                    'min' => $attrs['min_match'],
                    'lines' => is_array($attrs['paylines']) ? count($attrs['paylines']) : 0,
                    'free' => $attrs['has_free_spins'] ? 'y' : 'n',
                    'wc' => $winChances ? 'db' : 'default',
                    'warn' => implode('; ', $parser->warnings),
                ];

                if ($dry) {
                    $done++;

                    continue;
                }

                $template = GameTemplate::updateOrCreate(
                    ['code' => $code],
                    array_merge($attrs, array_filter([
                        'title' => $title,
                        'engine' => GameEngine::Internal,
                        'device' => 'both',
                        'bank_type' => BankType::Slots,
                        'client_protocol' => ClientProtocol::GamePlatform,
                        'pricing_currency' => Currency::USD,
                        'poster_path' => $poster,
                        'win_chances' => $winChances,
                        'is_active' => true,
                    ], fn ($v) => $v !== null)),
                );

                if (! $this->option('skip-bundles') && ($this->option('fresh-bundles') || ! $template->activeBundle)) {
                    $src = $frontend.'/'.$code;
                    if (is_dir($src)) {
                        $bundle = $bundles->storeFromDirectory(
                            $template, $src,
                            entry: 'index.html',
                            notes: 'EGT GamePlatform front-end (egt:import).',
                            fallbackEntryHtml: $this->portalShell($frontend, $code),
                        );
                        $report[$code]['bundle'] = "v{$bundle->version}/{$bundle->file_count}f";
                    } else {
                        $report[$code]['bundle'] = 'MISSING SRC';
                        $parser->warnings[] = 'no frontend dir';
                    }
                }

                foreach ($shops as $legacyId => $shop) {
                    $this->upsertGame($template, $shop, $legacyId, $egt, $attrs, $winChances);
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
            ['code', 'grid', 'syms', 'wild', 'scat', 'min', 'lines', 'free', 'wc', 'bundle', 'notes'],
            collect($report)->map(fn ($r, $c) => [
                $c, $r['reels'] ?? '', $r['syms'] ?? '', $r['wild'] ?? '', $r['scatter'] ?? '',
                $r['min'] ?? '', $r['lines'] ?? '', $r['free'] ?? '', $r['wc'] ?? '',
                $r['bundle'] ?? '', $r['error'] ?? $r['warn'] ?? '',
            ])->values()->all(),
        );

        if (! $dry) {
            $this->call('egt:extract-layout');
        }

        $this->info(sprintf('%s done=%d  skipped=%d  failed=%d', $dry ? 'Dry run —' : 'EGT import', $done, $skipped, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Create/refresh the per-shop game row, tagged Egt, with legacy tuning. */
    private function upsertGame(GameTemplate $template, Shop $shop, int $legacyShopId, Category $egt, array $attrs, ?array $winChances): void
    {
        $legacy = $this->legacyGameRow($template->code, $legacyShopId);

        $bets = $attrs['default_bet_options'];
        $reserve = (int) ($legacy->rezerv ?? 4) ?: 4;
        $rtp = (int) ($this->legacyShopPercent($legacyShopId) ?? 90);

        $game = Game::updateOrCreate(
            ['shop_id' => $shop->id, 'template_id' => $template->id],
            [
                'title' => $template->title,
                'bank_type' => BankType::Slots,
                'rtp_percent' => $rtp,
                'max_win_multiplier' => self::SHOP_MAP[$legacyShopId]['max_win_multiplier'],
                'reserve_percent' => $reserve,
                'bet_options' => $bets,
                'denomination' => 1,
                'pricing_currency' => Currency::USD,
                'win_chances' => $winChances,
                'is_visible' => (bool) ($legacy->view ?? true),
            ],
        );

        $game->categories()->syncWithoutDetaching([$egt->id]);
    }

    // ---- legacy DB ------------------------------------------------

    private function legacyReachable(): bool
    {
        try {
            DB::connection('legacy')->table('games')->limit(1)->get();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{spin: array, bonus: array}|null */
    private function winChances(string $code): ?array
    {
        $row = $this->legacyGameRow($code, 13) ?? $this->legacyGameRow($code, 14) ?? $this->legacyGameRow($code, 0);
        if (! $row) {
            return null;
        }

        $spin = json_decode((string) ($row->lines_percent_config_spin ?? ''), true);
        $bonus = json_decode((string) ($row->lines_percent_config_bonus ?? ''), true);

        if (! is_array($spin) || ! is_array($bonus)) {
            return null;
        }

        $toInt = fn ($t) => collect($t)->map(fn ($bands) => collect($bands)->map(fn ($v) => (int) $v)->all())->all();

        return ['spin' => $toInt($spin), 'bonus' => $toInt($bonus)];
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

    // ---- portal shell ------------------------------------------

    /** @var array{code: string, html: string}|null */
    private ?array $shell = null;

    /**
     * The generic EGT portal `index.html`, for games whose download is missing
     * it. Every EGT bundle ships the same shell bar the game code (`gameName`,
     * the `<base href>` and asset paths) and a stale gin the asset controller
     * rewrites anyway — so template one off a sibling that does have it.
     */
    private function portalShell(string $frontendDir, string $code): ?string
    {
        if ($this->shell === null) {
            foreach (File::directories($frontendDir) as $dir) {
                $src = basename($dir);
                $html = $dir.'/index.html';
                if (Str::endsWith($src, 'EGT') && is_file($html)) {
                    $body = (string) file_get_contents($html);
                    if (str_contains($body, 'gameName')) {
                        $this->shell = ['code' => $src, 'html' => $body];
                        break;
                    }
                }
            }
            $this->shell ??= ['code' => '', 'html' => ''];
        }

        return $this->shell['html'] === ''
            ? null
            : str_replace($this->shell['code'], $code, $this->shell['html']);
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
