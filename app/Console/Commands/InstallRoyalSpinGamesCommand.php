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
use App\Services\GamePlay\BundleManager;
use App\Services\GamePlay\Engine\CascadeSlotServer;
use App\Services\GamePlay\Engine\LineSlotServer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Install the platform's own "RoyalSpin" games — first-party titles with our
 * own front-end engine and our own server math, no legacy provider behind them.
 *
 * Source lives in the repo under resources/games/royalspin:
 *   engine/                 shared front-end engine (index.html, js, css, sounds)
 *   games/<Code>/math.json  server math → the game_templates row
 *   games/<Code>/game.json  client theme (symbol art, names, colours)
 *   games/<Code>/img/…      symbol / background / logo art
 *   games/<Code>/poster.svg lobby poster
 *
 * Each game's bundle = engine/ + games/<Code>/ (minus math.json), uploaded via
 * {@see BundleManager} exactly like a provider bundle. Line games run on
 * {@see LineSlotServer}, cascade games (tumble_config) on
 * {@see CascadeSlotServer}; both speak the standard JSON protocol.
 * Idempotent: an unchanged bundle is not re-uploaded.
 *
 *   php artisan royalspin:install
 *   php artisan royalspin:install --only=CandyRoyaleRS --fresh-bundles
 *   php artisan royalspin:install --shop=4
 */
class InstallRoyalSpinGamesCommand extends Command
{
    protected $signature = 'royalspin:install
        {--only= : Comma list of game codes to (re)install}
        {--shop=* : Shop id(s) to add the games to (default: every shop)}
        {--fresh-bundles : Re-upload bundles even when unchanged}
        {--take-back : Also reinstall games an admin re-designed in the Game Builder}';

    protected $description = 'Build + register the first-party RoyalSpin games (templates, bundles, per-shop games)';

    /** Template columns math.json may set. */
    private const array MATH_KEYS = [
        'reel_count', 'row_count', 'symbol_count', 'symbols', 'wild_symbol', 'scatter_symbol', 'wild_multiplier',
        'min_match', 'paytable', 'paylines', 'reel_strips', 'has_bonus', 'has_free_spins', 'free_spins_count',
        'free_spins_table', 'free_spins_multiplier', 'has_gamble', 'gamble_win_chance', 'volatility', 'win_chances',
        'default_bet_options', 'default_denomination', 'tumble_config',
    ];

    public function handle(BundleManager $bundles): int
    {
        $root = resource_path('games/royalspin');
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

        $category = Category::firstOrCreate(
            ['slug' => 'royalspin'],
            ['title' => 'RoyalSpin', 'position' => 1],
        );
        $category->update(['config' => array_merge((array) $category->config, ['client_protocol' => ClientProtocol::Standard->value])]);

        $shopIds = array_map('intval', (array) $this->option('shop'));
        $shops = Shop::query()->when($shopIds !== [], fn ($q) => $q->whereIn('id', $shopIds))->get();

        $rows = [];
        foreach (File::directories($root.'/games') as $dir) {
            $code = basename($dir);
            if ($only !== [] && ! in_array($code, $only, true)) {
                continue;
            }
            if (! is_file($dir.'/math.json') || ! is_file($dir.'/game.json')) {
                $this->warn("  {$code}: missing math.json / game.json — skipped");

                continue;
            }

            // re-skinned / re-tuned in the admin Game Builder — the builder owns it now
            $managed = GameTemplate::where('code', $code)->first()?->layout['builder_design'] ?? null;
            if ($managed && ! $this->option('take-back')) {
                $this->line("  <fg=yellow>•</> {$code}: managed by Game Builder design #{$managed} — skipped (--take-back to overwrite)");

                continue;
            }

            $math = json_decode((string) file_get_contents($dir.'/math.json'), true, flags: JSON_THROW_ON_ERROR);
            $theme = json_decode((string) file_get_contents($dir.'/game.json'), true, flags: JSON_THROW_ON_ERROR);

            $template = GameTemplate::updateOrCreate(['code' => $code], array_merge(
                array_fill_keys(['tumble_config', 'free_spins_table', 'wild_symbol', 'scatter_symbol'], null),
                array_intersect_key($math, array_flip(self::MATH_KEYS)),
                [
                    'title' => $math['title'] ?? $theme['title'] ?? $code,
                    'engine' => GameEngine::Internal,
                    'device' => 'both',
                    'bank_type' => BankType::Slots,
                    'client_protocol' => ClientProtocol::Standard,
                    'pricing_currency' => Currency::EUR,
                    'poster_path' => $this->publishPoster($dir, $code),
                    'layout' => ['provider' => 'RoyalSpin', 'mechanic' => isset($math['tumble_config']) ? 'cascade' : 'lines'],
                    'is_active' => true,
                ],
            ));

            $bundleNote = $this->syncBundle($bundles, $template, $root, $dir);

            foreach ($shops as $shop) {
                $game = Game::updateOrCreate(
                    ['shop_id' => $shop->id, 'template_id' => $template->id],
                    [
                        'title' => $template->title,
                        'bank_type' => BankType::Slots,
                        'bet_options' => $template->default_bet_options,
                        'denomination' => $template->default_denomination,
                        'pricing_currency' => Currency::EUR,
                        'reserve_percent' => (int) ($math['gamble_win_chance'] ?? 2),
                        'is_visible' => true,
                    ],
                );
                $game->categories()->syncWithoutDetaching([$category->id]);
            }

            $rows[] = [$code, $template->title, $template->reel_count.'x'.$template->row_count, $template->layout['mechanic'], $bundleNote, $shops->count()];
            $this->line("  <fg=green>✓</> {$code}");
        }

        $this->table(['code', 'title', 'grid', 'mechanic', 'bundle', 'shops'], $rows);

        return self::SUCCESS;
    }

    /** Assemble engine + game files into one bundle; upload only when the content changed. */
    private function syncBundle(BundleManager $bundles, GameTemplate $template, string $root, string $gameDir): string
    {
        $files = [];
        foreach ([$root.'/engine', $gameDir] as $base) {
            foreach (File::allFiles($base) as $file) {
                $rel = str_replace('\\', '/', $file->getRelativePathname());
                if (in_array($rel, ['math.json', 'poster.svg'], true)) {
                    continue;
                }
                $files[$rel] = $file->getPathname();
            }
        }
        ksort($files);

        $hasher = hash_init('sha256');
        foreach ($files as $rel => $path) {
            hash_update($hasher, $rel."\0".hash_file('sha256', $path)."\n");
        }
        $hash = substr(hash_final($hasher), 0, 16);

        $active = $template->activeBundle;
        if (! $this->option('fresh-bundles') && $active && str_contains((string) $active->notes, $hash)) {
            return "v{$active->version} (unchanged)";
        }

        $build = storage_path('app/royalspin-build/'.$template->code);
        File::deleteDirectory($build);
        foreach ($files as $rel => $path) {
            File::ensureDirectoryExists(dirname($build.'/'.$rel));
            if ($rel === 'index.html') {
                // cache-bust every asset URL per build (assets are served with max-age)
                File::put($build.'/'.$rel, str_replace('{{BUILD}}', $hash, (string) file_get_contents($path)));
            } else {
                File::copy($path, $build.'/'.$rel);
            }
        }

        $bundle = $bundles->storeFromDirectory($template, $build, entry: 'index.html', notes: "RoyalSpin build {$hash}");
        File::deleteDirectory($build);

        return "v{$bundle->version} ({$bundle->file_count} files)";
    }

    private function publishPoster(string $dir, string $code): ?string
    {
        if (! is_file($dir.'/poster.svg')) {
            return null;
        }
        $rel = 'game-posters/'.$code.'.svg';
        Storage::disk('public')->put($rel, (string) file_get_contents($dir.'/poster.svg'));

        return $rel;
    }
}
