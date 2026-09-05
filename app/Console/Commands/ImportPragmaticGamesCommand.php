<?php

namespace App\Console\Commands;

use App\Enums\BankType;
use App\Enums\ClientProtocol;
use App\Enums\Currency;
use App\Enums\GameEngine;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameBundle;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Services\GamePlay\BundleManager;
use App\Services\Legacy\EgtGameParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Port the legacy Pragmatic Play games into the rebuild as pure DB config —
 * the same pipeline as {@see ImportAmaticGamesCommand} / {@see ImportEgtGamesCommand},
 * since the legacy backend package shape is identical ({@see EgtGameParser} is
 * reused as-is). Two real differences from EGT, shared with Amatic:
 *  - the bet-per-line ladder isn't a `$gameBets` constant in Server.php — it's
 *    read at runtime from the legacy `games.bet` column, same as Amatic.
 *  - `client_protocol` is `pragmatic`, and the bundle has no single HTML
 *    entry at all (the legacy host page was a server-rendered Blade view, not
 *    a bundle file) — `GameAssetController::pragmaticShell()` synthesises it,
 *    so the bundle is registered with a sentinel entry, never resolved from
 *    the zip like every other provider.
 *
 * Desktop titles only (`*PT`, e.g. `GreatBluePT`) — the `*PTM` mobile variants
 * are separate legacy game codes, not yet ported.
 *
 *   php artisan pragmatic:import
 *   php artisan pragmatic:import --only=GreatBluePT,BuffaloBlitzPT
 *   php artisan pragmatic:import --skip-bundles          # config only, keep bundles
 *   php artisan pragmatic:import --fresh-bundles         # re-upload even if present
 */
class ImportPragmaticGamesCommand extends Command
{
    /** Bundles have no discoverable HTML entry — the shell is synthesised at request time. */
    public const string PLATFORM_SHELL = '__pragmatic_platform__';

    protected $signature = 'pragmatic:import
        {--only= : Comma list of game codes to (re)import}
        {--skip-bundles : Do not (re)upload front-end bundles}
        {--fresh-bundles : Re-upload bundles even when one is already active}
        {--dry-run : Parse and report, write nothing}';

    protected $description = 'Import the legacy Pragmatic Play games as DB-driven templates + per-shop games';

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
            $this->warn('Legacy DB unreachable — bet ladders / win-chance tables will fall back to defaults.');
        }

        $pragmatic = Category::firstOrCreate(
            ['slug' => 'pragmatic'],
            ['title' => 'Pragmatic Play', 'position' => 5, 'config' => ['client_protocol' => 'pragmatic']],
        );
        if (data_get($pragmatic->config, 'client_protocol') !== 'pragmatic') {
            $pragmatic->update(['config' => array_merge((array) $pragmatic->config, ['client_protocol' => 'pragmatic'])]);
        }

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
        $codes = $only ?: collect(File::directories($backend))
            ->map(fn ($d) => basename($d))
            ->filter(fn ($c) => Str::endsWith($c, 'PT'))
            ->sort()
            ->values()
            ->all();

        $dry = (bool) $this->option('dry-run');

        $done = $skipped = $failed = 0;
        $report = [];

        foreach ($codes as $code) {
            $dir = $backend.'/'.$code;
            $parser = EgtGameParser::fromDir($dir, $code);

            if (! $parser || ! $parser->isLineSlot()) {
                $this->line("  <fg=gray>—</> {$code} — not a line slot, skipped");
                $skipped++;

                continue;
            }

            try {
                $attrs = $parser->templateAttributes();

                $legacyBets = $legacyOk ? $this->legacyBetOptions($code) : null;
                if ($legacyBets) {
                    $attrs['default_bet_options'] = $legacyBets;
                }

                $winChances = $legacyOk ? $this->winChances($code) : null;

                $title = Str::title(preg_replace('/(?<!^)(?=[A-Z])/', ' ', Str::before($code, 'PT')));
                $poster = $dry ? null : $this->copyPoster($icons, $code);

                $report[$code] = [
                    'reels' => $attrs['reel_count'].'x'.$attrs['row_count'],
                    'syms' => $attrs['symbol_count'],
                    'wild' => $attrs['wild_symbol'],
                    'scatter' => $attrs['scatter_symbol'],
                    'min' => $attrs['min_match'],
                    'lines' => is_array($attrs['paylines']) ? count($attrs['paylines']) : 0,
                    'free' => $attrs['has_free_spins'] ? 'y' : 'n',
                    'bets' => $legacyBets ? 'legacy' : 'default',
                    'wc' => $winChances ? 'set' : 'default',
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
                        'client_protocol' => ClientProtocol::Pragmatic,
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
                            entry: self::PLATFORM_SHELL,
                            notes: 'Pragmatic Play platform+bib front-end (pragmatic:import).',
                        );
                        $phpCopied = $this->copyStaticPhpAssets($src, $bundle);
                        $report[$code]['bundle'] = "v{$bundle->version}/{$bundle->file_count}f (synthesised shell)".($phpCopied ? ", +{$phpCopied} static .php" : '');
                    } else {
                        $report[$code]['bundle'] = 'MISSING SRC';
                        $parser->warnings[] = 'no frontend dir';
                    }
                }

                foreach ($shops as $legacyId => $shop) {
                    $this->upsertGame($template, $shop, $legacyId, $pragmatic, $attrs, $winChances);
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
            ['code', 'grid', 'syms', 'wild', 'scat', 'min', 'lines', 'free', 'bets', 'wc', 'bundle', 'notes'],
            collect($report)->map(fn ($r, $c) => [
                $c, $r['reels'] ?? '', $r['syms'] ?? '', $r['wild'] ?? '', $r['scatter'] ?? '',
                $r['min'] ?? '', $r['lines'] ?? '', $r['free'] ?? '', $r['bets'] ?? '', $r['wc'] ?? '',
                $r['bundle'] ?? '', $r['error'] ?? $r['warn'] ?? '',
            ])->values()->all(),
        );

        $this->info(sprintf('%s done=%d  skipped=%d  failed=%d', $dry ? 'Dry run —' : 'Pragmatic import', $done, $skipped, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Create/refresh the per-shop game row, tagged Pragmatic, with legacy tuning. */
    private function upsertGame(GameTemplate $template, Shop $shop, int $legacyShopId, Category $pragmatic, array $attrs, ?array $winChances): void
    {
        $legacy = $this->legacyGameRow($template->code, $legacyShopId);

        $bets = $this->parseBetList($legacy->bet ?? null) ?? $attrs['default_bet_options'];
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

        $game->categories()->syncWithoutDetaching([$pragmatic->id]);
    }

    // ---- bets (legacy `games.bet`, not a Server.php constant) ---------

    /** @return list<float>|null */
    private function legacyBetOptions(string $code): ?array
    {
        $row = $this->legacyGameRow($code, 13) ?? $this->legacyGameRow($code, 14) ?? $this->legacyGameRow($code, 0);

        return $row ? $this->parseBetList($row->bet ?? null) : null;
    }

    /** @return list<float>|null */
    private function parseBetList(?string $csv): ?array
    {
        if (! $csv) {
            return null;
        }

        $bets = array_values(array_filter(array_map(
            'floatval',
            array_filter(array_map('trim', explode(',', $csv)), fn ($v) => $v !== '' && is_numeric($v)),
        ), fn ($v) => $v > 0));

        return $bets ?: null;
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

    // ---- static ".php" assets ------------------------------------

    /**
     * The Playtech "platform" chrome hardcodes a handful of request paths
     * ending in `.php` (`locked_games.php`, `games_info.php`, `gls_config.php`,
     * `integration.js.php`, per-game `js/gls_config.php`, …) — but on the
     * legacy server every one of them is a static, hand-authored JSON/JS
     * literal with no actual PHP logic (verified: none contain a `<?php` or
     * `<?=` tag). `BundleManager` strips `.php` from every uploaded bundle on
     * principle (untrusted content must never execute server-side) — that's
     * still correct for admin uploads, but blocks these known-static legacy
     * files the client requires by that exact path. Copy them in here, after
     * re-verifying each is tag-free, rather than loosening the general rule.
     *
     * @return int files copied
     */
    private function copyStaticPhpAssets(string $sourceDir, GameBundle $bundle): int
    {
        $absDir = $bundle->disk()->path($bundle->path);
        $copied = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (str_contains($contents, '<?php') || str_contains($contents, '<?=')) {
                $this->warn("  skipped {$file->getFilename()} — contains a PHP tag, not static");

                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($sourceDir))), '/');
            $target = $absDir.'/'.$relative;
            File::ensureDirectoryExists(dirname($target));
            file_put_contents($target, $contents);
            $copied++;
        }

        if ($copied > 0) {
            $bundle->increment('file_count', $copied);
        }

        return $copied;
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
