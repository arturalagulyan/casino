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
use App\Services\Legacy\AmaticLegacyData;
use App\Services\Legacy\EgtGameParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Port the legacy Amatic "amarent" games into the rebuild as pure DB config —
 * the same pipeline as {@see ImportEgtGamesCommand}, since the legacy backend
 * package shape is identical (SlotSettings.php Paytable, reels.txt strips,
 * Server.php wild/scatter/paylines — {@see EgtGameParser} is reused as-is).
 *
 * Two real differences from EGT:
 *  - the bet-per-line ladder isn't a `$gameBets` constant in Server.php — Amatic
 *    reads it at runtime from the legacy `games.bet` column (comma-separated),
 *    via `SlotSettings::$Bet = explode(',', $game->bet)`. Pulled from the same
 *    legacy DB row already used for win-chances / reserve / view.
 *  - `client_protocol` is `amatic`, and the bundle entry is the nested
 *    `amarent/index.html` (auto-resolved by {@see BundleEntryResolver}, not
 *    forced like EGT's root `index.html`).
 *
 *   php artisan amatic:import
 *   php artisan amatic:import --only=AdmiralNelsonAM,GrandCasanovaAM
 *   php artisan amatic:import --skip-bundles          # config only, keep bundles
 *   php artisan amatic:import --fresh-bundles         # re-upload even if present
 */
class ImportAmaticGamesCommand extends Command
{
    protected $signature = 'amatic:import
        {--only= : Comma list of game codes to (re)import}
        {--skip-bundles : Do not (re)upload front-end bundles}
        {--fresh-bundles : Re-upload bundles even when one is already active}
        {--dry-run : Parse and report, write nothing}';

    protected $description = 'Import the legacy Amatic amarent games as DB-driven templates + per-shop games';

    /** Legacy shop id → rebuild shop name, with the per-shop win cap (× bet) to apply. */
    private const array SHOP_MAP = [
        13 => ['name' => 'Bilion07', 'max_win_multiplier' => 50],
        14 => ['name' => 'Better365', 'max_win_multiplier' => 20],
    ];

    public function __construct(private readonly AmaticLegacyData $legacy)
    {
        parent::__construct();
    }

    public function handle(BundleManager $bundles): int
    {
        $backend = (string) config('legacy.games_backend_path');
        $frontend = (string) config('legacy.games_frontend_path');
        $icons = (string) config('legacy.games_icons_path');

        if (! is_dir($backend)) {
            $this->error("Legacy backend games not found at {$backend} (LEGACY_GAMES_BACKEND_PATH).");

            return self::FAILURE;
        }

        $legacyOk = $this->legacy->reachable();
        if (! $legacyOk) {
            $this->warn('Legacy DB unreachable — bet ladders / win-chance tables will fall back to defaults.');
        }

        $amatic = Category::firstOrCreate(
            ['slug' => 'amatic'],
            ['title' => 'Amatic', 'position' => 4, 'config' => ['client_protocol' => 'amatic']],
        );
        if (data_get($amatic->config, 'client_protocol') !== 'amatic') {
            $amatic->update(['config' => array_merge((array) $amatic->config, ['client_protocol' => 'amatic'])]);
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
            // Make sure the slots pool can pay.
            /** @var GameBank $bank */
            $bank = $shop->banks()->firstOrCreate(['currency' => $shop->currency->value]);
            if ((float) $bank->slots < 50_000) {
                $bank->forceFill(['slots' => 250_000])->save();
            }
        }

        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $codes = $only ?: collect(File::directories($backend))
            ->map(fn ($d) => basename($d))
            ->filter(fn ($c) => Str::endsWith($c, 'AM'))
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
                $this->line("  <fg=gray>—</> {$code} — not a line slot, skipped");
                $skipped++;

                continue;
            }

            try {
                $attrs = $parser->templateAttributes();

                $legacyBets = $this->legacy->betOptions($code);
                if ($legacyBets) {
                    $attrs['default_bet_options'] = $legacyBets;
                }

                $winChances = $this->legacy->winChances($code);

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
                        'client_protocol' => ClientProtocol::Amatic,
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
                            notes: 'Amatic amarent front-end (amatic:import).',
                        );
                        $report[$code]['bundle'] = "v{$bundle->version}/{$bundle->file_count}f".($bundle->entry ? " ({$bundle->entry})" : ' (unresolved entry)');
                    } else {
                        $report[$code]['bundle'] = 'MISSING SRC';
                        $parser->warnings[] = 'no frontend dir';
                    }
                }

                foreach ($shops as $legacyId => $shop) {
                    $this->upsertGame($template, $shop, $legacyId, $amatic, $attrs, $winChances);
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

        $this->info(sprintf('%s done=%d  skipped=%d  failed=%d', $dry ? 'Dry run —' : 'Amatic import', $done, $skipped, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Create/refresh the per-shop game row, tagged Amatic, with legacy tuning. */
    private function upsertGame(GameTemplate $template, Shop $shop, int $legacyShopId, Category $amatic, array $attrs, ?array $winChances): void
    {
        $legacy = $this->legacy->gameRow($template->code, $legacyShopId);

        $bets = $this->legacy->parseBetList($legacy->bet ?? null) ?? $attrs['default_bet_options'];
        $reserve = (int) ($legacy->rezerv ?? 4) ?: 4;
        $rtp = (int) ($this->legacy->shopPercent($legacyShopId) ?? 90);

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

        $game->categories()->syncWithoutDetaching([$amatic->id]);
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
