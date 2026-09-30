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
use App\Services\GamePlay\AmaticCdnFetcher;
use App\Services\GamePlay\AmaticCdnGame;
use App\Services\GamePlay\BundleManager;
use App\Services\Legacy\AmaticLegacyData;
use App\Services\Legacy\EgtGameParser;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * One-shot import of an Amatic "gmsl/mpp" game straight from a live Amatic
 * game URL:
 *
 *  1. mirror every front-end file off the CDN, in the CDN's own hierarchy
 *     ({@see AmaticCdnFetcher}) — `storage/app/game-fetch/<Code>/gmsl/mpp/...`
 *  2. zip it — `storage/app/game-zips/<Code>.zip`
 *  3. create/refresh the GameTemplate — math (paytable, reels, lines, wild/
 *     scatter, free spins) from the legacy `<GameId>AM` backend package, bet
 *     ladder + win chances from the legacy DB / its bundled export, exactly
 *     like `amatic:import`
 *  4. upload the zip as the template's new active bundle (the same
 *     BundleManager::store() path the admin "Upload bundle" action uses)
 *  5. create/refresh a Game in each shop, tagged `amatic`
 *
 *   php artisan amatic:fetch "https://cdn02.cdn.amatic.com/gmsl/amanet/game.html?game=aztecsecret&config=1861&currency=EUR"
 *   php artisan amatic:fetch "<url>" --shop="Web Casino" --code=AztecSecretNew
 *   php artisan amatic:fetch "<url>" --fetch-only        # just mirror + zip
 *
 * Re-running is safe: the template/games are updated in place and the bundle
 * becomes a new (active) version.
 */
class FetchAmaticGameCommand extends Command
{
    protected $signature = 'amatic:fetch
        {url : Amatic game URL (…/gmsl/amanet/game.html?game=<key>&config=<id>… or …/gmsl/mpp/amarent/<key>.html?config=<id>)}
        {--code= : Template code / asset key (default: <GameId>New, e.g. AztecSecretNew)}
        {--title= : Display title (default: the engine\'s own title)}
        {--legacy= : Legacy backend package to take the math from (default: <GameId>AM)}
        {--shop=* : Shop id or name to add the game to (repeatable; default: every shop)}
        {--currency= : Pricing currency of the bet ladder (default: the URL\'s currency=, else USD)}
        {--rtp=90 : Per-shop RTP %}
        {--max-win=50 : Per-shop max win (× bet)}
        {--fetch-only : Mirror + zip only, don\'t touch the database}';

    protected $description = 'Download an Amatic gmsl/mpp game from its CDN URL, bundle it and register it as a playable template + games';

    public function __construct(private readonly AmaticLegacyData $legacy)
    {
        parent::__construct();
    }

    public function handle(AmaticCdnFetcher $fetcher, BundleManager $bundles): int
    {
        $url = (string) $this->argument('url');
        $staging = storage_path('app/game-fetch/_incoming');

        // 1. mirror
        $this->info('Fetching '.$url);
        try {
            $game = $fetcher->fetch($url, $staging, fn (string $m) => $this->line("  <fg=gray>·</> {$m}"));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $code = (string) ($this->option('code') ?: $game->gameId.'New');
        $title = (string) ($this->option('title') ?: $game->title);
        $dir = storage_path('app/game-fetch/'.$code);
        File::deleteDirectory($dir);
        File::moveDirectory($staging, $dir);

        $this->reportFetch($game, $dir);

        // 2. zip
        $zip = $this->zip($dir, $code);
        $this->info(sprintf('Bundle zip: %s (%.1f MB)', $zip, filesize($zip) / 1048576));

        if ($this->option('fetch-only')) {
            return self::SUCCESS;
        }

        $shops = $this->shops();
        if ($shops === null) {
            return self::FAILURE;
        }

        // 3. template
        $legacyCode = (string) ($this->option('legacy') ?: $game->gameId.'AM');
        $attrs = $this->mathFromLegacy($legacyCode);
        $winChances = $this->legacy->winChances($legacyCode);
        $currency = Currency::tryFrom(strtoupper((string) ($this->option('currency') ?: $game->currency ?: 'USD'))) ?? Currency::USD;
        $existing = GameTemplate::query()->where('code', $code)->first();
        // Launcher-only page params (e.g. classic=true) — GameAssetController
        // re-adds them to the page URL, since our launch never goes through
        // Amatic's launcher.
        $layout = array_merge((array) ($existing->layout ?? []), ['url_params' => $game->urlParams]);

        $template = GameTemplate::updateOrCreate(
            ['code' => $code],
            array_merge($attrs, array_filter([
                'title' => $title,
                'engine' => GameEngine::Internal,
                'device' => 'both',
                'bank_type' => BankType::Slots,
                'client_protocol' => ClientProtocol::Amatic,
                'pricing_currency' => $currency,
                'default_denomination' => 1,
                'poster_path' => $this->copyPoster($legacyCode, $code),
                'win_chances' => $winChances,
                'layout' => $layout,
                'is_active' => true,
            ], fn ($v) => $v !== null)),
        );
        $this->info(($template->wasRecentlyCreated ? 'Created' : 'Updated')." template #{$template->id} {$code} — {$title}");

        // 4. bundle
        $bundle = $bundles->store(
            $template,
            new UploadedFile($zip, basename($zip), 'application/zip', null, true),
            entry: $game->entry,
            notes: Str::limit("Amatic CDN mirror (amatic:fetch) of {$game->key}: ".strtok($url, '?'), 250),
        );
        $this->info("Bundle v{$bundle->version} active — {$bundle->file_count} files, entry {$bundle->entry}");

        // 5. games
        $amatic = Category::firstOrCreate(
            ['slug' => 'amatic'],
            ['title' => 'Amatic', 'position' => 4, 'config' => ['client_protocol' => 'amatic']],
        );
        foreach ($shops as $shop) {
            $row = Game::updateOrCreate(
                ['shop_id' => $shop->id, 'template_id' => $template->id],
                [
                    'title' => $title,
                    'bank_type' => BankType::Slots,
                    'rtp_percent' => (int) $this->option('rtp'),
                    'max_win_multiplier' => (int) $this->option('max-win'),
                    'reserve_percent' => 4,
                    'bet_options' => $template->default_bet_options,
                    'denomination' => 1,
                    'pricing_currency' => $currency,
                    'win_chances' => $winChances,
                    'is_visible' => true,
                ],
            );
            $row->categories()->syncWithoutDetaching([$amatic->id]);
            $this->line("  <fg=green>✓</> game #{$row->id} in shop {$shop->name}");
        }

        $this->newLine();
        $this->info('Done. Try it: '.url("/games/demo/{$code}"));

        return self::SUCCESS;
    }

    /**
     * Paytable, reel strips, paylines, wild/scatter, free spins and the bet
     * ladder from the legacy `<GameId>AM` package + legacy `games` row — the
     * CDN client only renders, the math always ran server-side.
     *
     * @return array<string, mixed>
     */
    private function mathFromLegacy(string $legacyCode): array
    {
        $dir = config('legacy.games_backend_path').'/'.$legacyCode;
        $parser = EgtGameParser::fromDir($dir, $legacyCode);

        if (! $parser || ! $parser->isLineSlot()) {
            $this->warn("No legacy line-slot package {$legacyCode} — using engine defaults for paytable/reels/lines (pass --legacy=<Code> to pick one).");
            $attrs = ['reel_count' => 5, 'row_count' => 3];
        } else {
            $attrs = $parser->templateAttributes();

            // "Book"-style games pick a free-spin special symbol
            // (`SetGameData('<Code>FreeSym', rand(1, 8))`) that the client
            // expects on the wire — see AmaticFormatter.
            $server = (string) @file_get_contents($dir.'/Server.php');
            if (str_contains($server, 'FreeSym')) {
                $attrs['bonus_config'] = (array) ($attrs['bonus_config'] ?? []);
                $attrs['bonus_config']['free_symbol'] = preg_match("/FreeSym'\s*,\s*rand\(\s*(\d+)\s*,\s*(\d+)\s*\)/", $server, $m)
                    ? range((int) $m[1], (int) $m[2])
                    : true;
                $this->line('  free-spin special symbol: '.json_encode($attrs['bonus_config']['free_symbol']));
            }
            $this->line(sprintf(
                '  math from %s: %dx%d, %d symbols, %d lines, wild=%s scatter=%s, free spins=%s%s',
                $legacyCode, $attrs['reel_count'], $attrs['row_count'], $attrs['symbol_count'],
                is_array($attrs['paylines']) ? count($attrs['paylines']) : 0,
                $attrs['wild_symbol'] ?? '-', $attrs['scatter_symbol'] ?? '-', $attrs['has_free_spins'] ? 'yes' : 'no',
                $parser->warnings ? ' ('.implode('; ', $parser->warnings).')' : '',
            ));
        }

        if ($bets = $this->legacy->betOptions($legacyCode)) {
            $attrs['default_bet_options'] = $bets;
        }
        $this->line('  bet ladder: '.json_encode($attrs['default_bet_options'] ?? null).($bets ? ' (legacy)' : ' (default)'));

        return $attrs;
    }

    /** @return list<Shop>|null */
    private function shops(): ?array
    {
        $wanted = (array) $this->option('shop');
        if ($wanted === []) {
            return Shop::query()->orderBy('id')->get()->all();
        }

        $shops = [];
        foreach ($wanted as $key) {
            $shop = Shop::query()->where(is_numeric($key) ? 'id' : 'name', $key)->first();
            if (! $shop) {
                $this->error("Shop '{$key}' not found.");

                return null;
            }
            $shops[] = $shop;
        }

        return $shops;
    }

    /** Zip `$dir` under a `<Code>/` wrapper, which BundleManager strips — so the bundle keeps the `gmsl/...` hierarchy. */
    private function zip(string $dir, string $code): string
    {
        $path = storage_path("app/game-zips/{$code}.zip");
        File::ensureDirectoryExists(dirname($path));
        File::delete($path);

        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create {$path}");
        }
        foreach (File::allFiles($dir) as $file) {
            $archive->addFile($file->getPathname(), $code.'/'.str_replace('\\', '/', $file->getRelativePathname()));
        }
        $archive->close();

        return $path;
    }

    private function reportFetch(AmaticCdnGame $game, string $dir): void
    {
        $this->newLine();
        $this->table(['', ''], [
            ['game', "{$game->gameId} — {$game->title}"],
            ['entry', $game->entry],
            ['config', $game->configId ?? '-'],
            ['languages', implode(',', $game->languages)],
            ['page params', http_build_query($game->urlParams) ?: '-'],
            ['files', count($game->files).sprintf(' (%.1f MB)', $game->bytes / 1048576)],
            ['mirrored to', $dir],
        ]);

        foreach ($game->warnings as $w) {
            $this->warn($w);
        }
        if ($game->missing !== []) {
            $this->warn(count($game->missing).' declared resource(s) the CDN did not have — the client may stall/mute on these:');
            foreach ($game->missing as $m) {
                $this->line("  <fg=yellow>✗</> {$m}");
            }
        }
    }

    private function copyPoster(string $legacyCode, string $code): ?string
    {
        $src = config('legacy.games_icons_path').'/'.$legacyCode.'.jpg';
        if (! is_file($src)) {
            return null;
        }

        $rel = 'game-posters/'.$code.'.jpg';
        Storage::disk('public')->put($rel, (string) file_get_contents($src));

        return $rel;
    }
}
