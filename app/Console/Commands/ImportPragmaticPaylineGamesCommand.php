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
use App\Services\GamePlay\BundleManager;
use App\Services\GamePlay\Engine\PaylineEngine;
use App\Services\Legacy\PaylineGameParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Port the modern Pragmatic Play "gs2c" CLASSIC-PAYLINE family — the other
 * big branch alongside {@see ImportPragmaticTumbleGamesCommand} (tumble/
 * scatter-pays); see {@see ClientProtocol::PragmaticPayline} for the full
 * split rationale. Piloted on AztecKing (5x3, wild + scatter-triggered free
 * spins + a weighted-value money symbol, no tumble/cascade). Titles with
 * extra bespoke mechanics on top (WolfGold's money-collect "hold and spin"
 * bonus round, mystery-symbol stacking during free spins, pick-a-prize
 * wheels) will import and play at reduced fidelity — those extras aren't
 * ported (see {@see PaylineEngine}'s docblock).
 *
 *   php artisan pragmatic-payline:import
 *   php artisan pragmatic-payline:import --only=AztecKing,HeartofRio
 *   php artisan pragmatic-payline:import --dry-run
 */
class ImportPragmaticPaylineGamesCommand extends Command
{
    protected $signature = 'pragmatic-payline:import
        {--only= : Comma list of game codes to (re)import}
        {--skip-bundles : Do not (re)upload front-end bundles}
        {--fresh-bundles : Re-upload bundles even when one is already active}
        {--dry-run : Parse and report, write nothing}';

    protected $description = 'Import modern Pragmatic Play "gs2c" classic-payline games (pilot: AztecKing)';

    private const array KNOWN_PAYLINE_TITLES = ['AztecKing'];

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

        /** @var array<int, Shop> $shops */
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
        $codes = $only ?: self::KNOWN_PAYLINE_TITLES;

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
                $parser = new PaylineGameParser($initPath);
                $title = Str::title(preg_replace('/(?<!^)(?=[A-Z0-9])/', ' ', $code));
                $scatterTables = $parser->scatterTables();

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
                    'paylines' => $parser->paylines(),
                    'wild_symbol' => $parser->wildSymbol(),
                    'scatter_symbol' => $parser->scatterSymbol(),
                    'has_free_spins' => true,
                    'default_bet_options' => $parser->betOptions(),
                    'payline_config' => [
                        'money_symbol' => $parser->moneySymbol(),
                        'money_values' => $parser->moneyValues(),
                        'mystery_symbol_min' => 3,
                        'mystery_symbol_max' => 11,
                        'fs_by_scatter_count' => array_map('intval', $scatterTables['fs']),
                        'fsmul_by_scatter_count' => array_map('intval', $scatterTables['fsmul']),
                        'needaddfs' => $parser->needFreeSpins(),
                        'addfs' => $parser->needAddFreeSpins(),
                        'raw' => $parser->raw(),
                    ],
                ];

                $report[$code] = [
                    'grid' => $attrs['reel_count'].'x'.$attrs['row_count'],
                    'syms' => $attrs['symbol_count'],
                    'lines' => count($attrs['paylines']),
                    'wild' => $attrs['wild_symbol'],
                    'scat' => $attrs['scatter_symbol'],
                    'money' => $attrs['payline_config']['money_symbol'] ?? '-',
                    'bets' => count($attrs['default_bet_options']),
                    'family' => in_array($code, self::KNOWN_PAYLINE_TITLES, true) ? 'payline' : 'UNVERIFIED',
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
                        'client_protocol' => ClientProtocol::PragmaticPayline,
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
                            notes: 'Pragmatic Play "gs2c" HTML5 front-end (pragmatic-payline:import).',
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
            ['code', 'grid', 'syms', 'lines', 'wild', 'scat', 'money', 'bets', 'family', 'bundle', 'notes'],
            collect($report)->map(fn ($r, $c) => [
                $c, $r['grid'] ?? '', $r['syms'] ?? '', $r['lines'] ?? '', $r['wild'] ?? '', $r['scat'] ?? '',
                $r['money'] ?? '', $r['bets'] ?? '', $r['family'] ?? '', $r['bundle'] ?? '', $r['error'] ?? '',
            ])->values()->all(),
        );

        $this->info(sprintf('%s done=%d  failed=%d', $dry ? 'Dry run —' : 'Pragmatic-payline import', $done, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

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
