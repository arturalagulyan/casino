<?php

namespace App\Console\Commands;

use App\Enums\BankType;
use App\Enums\ClientProtocol;
use App\Enums\Currency;
use App\Enums\GameEngine;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameBundle;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Services\GamePlay\Amatic\AmaticLiveClient;
use App\Services\GamePlay\Amatic\AmaticMathResolver;
use App\Services\GamePlay\Amatic\AmaticWildcatMath;
use App\Services\GamePlay\AmaticCdnFetcher;
use App\Services\GamePlay\AmaticCdnGame;
use App\Services\GamePlay\BundleManager;
use App\Services\Legacy\AmaticLegacyData;
use App\Services\Legacy\Bridge\LegacyServerBridge;
use App\Services\Legacy\EgtGameParser;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
        {--legacy= : Legacy backend package to take the math from (default: <GameId>AM, else the one <GameId>*AM package)}
        {--shop=* : Shop id or name to add the game to (repeatable; default: every shop)}
        {--currency= : Pricing currency of the bet ladder (default: the URL\'s currency=, else USD)}
        {--rtp=90 : Per-shop RTP %}
        {--max-win=50 : Per-shop max win (× bet)}
        {--spins=40 : Live free-play spins sampled on Amatic\'s server to verify the maths}
        {--category=* : Extra category (by title, created if missing) for the games — besides "Amatic"}
        {--plain-code : Default the template code to the game id itself (e.g. FortuneJoker10, not FortuneJoker10New)}
        {--reuse-mirror : Use an earlier mirror of the same game instead of downloading it again}
        {--keep-bundle : Keep the template\'s active bundle (only update maths/games)}
        {--best-fit : When no rule set reproduces the live spins, import the closest one, UNVERIFIED — its games are created hidden}
        {--poster= : Poster image URL (e.g. the operator lobby\'s tile image)}
        {--fetch-only : Mirror + zip only, don\'t touch the database}';

    private const string MIRROR_MANIFEST = '.amatic-game.json';

    /** engine id → the id the operator's live server expects (read off the real client's `A/u25` command) */
    private const array LIVE_IDS = ['LuckyEgypt' => 'LuckyEgyptLinked'];

    /** Amatic table games (CDN key → handler type in AmaticProtocol) — no reels to solve. */
    private const array TABLE_GAMES = [
        'rouletteroyal' => 'roulette',
        'rouletteprestige' => 'roulette',
        'blackjack' => 'legacy:BlackJackAM',
        'fruitcardpoker' => 'legacy:FruitPokerAM',
        'keno' => 'keno',
    ];

    protected $description = 'Download an Amatic gmsl/mpp game from its CDN URL, bundle it and register it as a playable template + games';

    public function __construct(private readonly AmaticLegacyData $legacy)
    {
        parent::__construct();
    }

    public function handle(AmaticCdnFetcher $fetcher, BundleManager $bundles, AmaticMathResolver $resolver): int
    {
        $url = (string) $this->argument('url');

        // 1. mirror (or reuse an earlier one of the same page)
        $reused = $this->option('reuse-mirror') ? $this->existingMirror($url) : null;
        if ($reused !== null) {
            [$game, $dir] = $reused;
            $code = (string) ($this->option('code') ?: basename($dir));
            $this->info("Reusing the mirror at {$dir}");
        } else {
            $this->info('Fetching '.$url);
            // per process, so several imports can run side by side
            $staging = storage_path('app/game-fetch/_incoming-'.getmypid());
            try {
                $game = $fetcher->fetch($url, $staging, fn (string $m) => $this->line("  <fg=gray>·</> {$m}"));
            } catch (RuntimeException $e) {
                File::deleteDirectory($staging);
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $code = (string) ($this->option('code') ?: $game->gameId.($this->option('plain-code') ? '' : 'New'));
            $dir = storage_path('app/game-fetch/'.$code);
            File::deleteDirectory($dir);
            File::moveDirectory($staging, $dir);
            File::put($dir.'/'.self::MIRROR_MANIFEST, json_encode(get_object_vars($game), JSON_UNESCAPED_SLASHES));
            $this->reportFetch($game, $dir);
        }
        $title = (string) ($this->option('title') ?: $game->title);
        // the manifest's id may differ in case from the engine class's own (`Wild7boost` vs `Wild7Boost`) —
        // Amatic's server only knows the engine's spelling
        if (preg_match('/\w+\(\)\{this\.\w+=this\.\w+="('.preg_quote($game->gameId, '/').')"/i', $this->engineJs($dir, $game), $id) && $id[1] !== $game->gameId) {
            $this->line("  engine id {$id[1]} (manifest says {$game->gameId})");
            $game = new AmaticCdnGame(...array_merge(get_object_vars($game), ['gameId' => $id[1]]));
        }
        // ids the operator's server knows under another name (seen in the real client's init command)
        if (isset(self::LIVE_IDS[$game->gameId])) {
            $this->line('  live id '.self::LIVE_IDS[$game->gameId]);
            $game = new AmaticCdnGame(...array_merge(get_object_vars($game), ['gameId' => self::LIVE_IDS[$game->gameId]]));
        }

        if ($this->option('fetch-only')) {
            $zip = $this->zip($dir, $code);
            $this->info(sprintf('Bundle zip: %s (%.1f MB)', $zip, filesize($zip) / 1048576));

            return self::SUCCESS;
        }

        $shops = $this->shops();
        if ($shops === null) {
            return self::FAILURE;
        }

        // 3. maths, checked against Amatic's own server — refuse rather than
        // import a game whose pays we couldn't verify
        $legacyCode = $this->option('legacy') ? (string) $this->option('legacy') : $this->guessLegacyCode($game->gameId);
        $tableType = self::TABLE_GAMES[$game->key] ?? null;
        // WildCat Gaming titles (gmsl/wcg) keep their reels server-side: their own maths family
        $wildcat = str_contains($game->entry, '/wcg/');
        $legacyAttrs = $legacyCode === null || $tableType !== null || $wildcat ? null : $this->mathFromLegacy($legacyCode);
        try {
            $math = match (true) {
                $wildcat => $this->wildcatGame($game, $dir),
                $tableType !== null => $this->tableGame($tableType, $game, $dir),
                default => $resolver->resolve($game->gameId, $this->engineJs($dir, $game), $legacyAttrs, (int) $this->option('spins'), fn (string $m) => $this->line("  {$m}"), bestFit: (bool) $this->option('best-fit')),
            };
        } catch (RuntimeException $e) {
            $this->error($e->getMessage().". Bundle mirror kept at {$dir}.");

            return self::FAILURE;
        }
        $attrs = $math['attrs'];
        $this->line("  maths source: {$math['source']}".($legacyCode ? " (legacy package {$legacyCode})" : ''));
        foreach ($math['notes'] as $note) {
            $this->warn("  note: {$note}");
        }
        $winChances = $legacyCode !== null ? $this->legacy->winChances($legacyCode) : null;
        $currency = Currency::tryFrom(strtoupper((string) ($this->option('currency') ?: $game->currency ?: 'USD'))) ?? Currency::USD;
        $existing = GameTemplate::query()->where('code', $code)->first();
        // Launcher-only page params (e.g. classic=true) — GameAssetController
        // re-adds them to the page URL, since our launch never goes through
        // Amatic's launcher.
        $layout = array_merge((array) ($existing->layout ?? []), [
            'url_params' => $game->urlParams,
            'amatic' => ['key' => $game->key, 'game_id' => $game->gameId, 'url' => $url, 'maths' => $math['source'], 'notes' => $math['notes']],
        ]);

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
                'poster_path' => $this->option('poster') ? $this->downloadPoster((string) $this->option('poster'), $code) : null,
                'win_chances' => $winChances,
                'layout' => $layout,
                'is_active' => true,
            ], fn ($v) => $v !== null)),
        );
        $this->info(($template->wasRecentlyCreated ? 'Created' : 'Updated')." template #{$template->id} {$code} — {$title}");

        // 4. bundle (zip → new active version), unless only the maths changed
        $active = GameBundle::query()->where('game_template_id', $template->id)->where('is_active', true)->first();
        if ($this->option('keep-bundle') && $active !== null) {
            $this->info("Bundle v{$active->version} kept");
        } else {
            $zip = $this->zip($dir, $code);
            $bundle = $bundles->store(
                $template,
                new UploadedFile($zip, basename($zip), 'application/zip', null, true),
                entry: $game->entry,
                notes: Str::limit("Amatic CDN mirror (amatic:fetch) of {$game->key}: ".strtok($url, '?'), 250),
            );
            File::delete($zip);
            $this->info("Bundle v{$bundle->version} active — {$bundle->file_count} files, entry {$bundle->entry}");
        }

        // 5. games
        $amatic = Category::firstOrCreate(
            ['slug' => 'amatic'],
            ['title' => 'Amatic', 'position' => 4, 'config' => ['client_protocol' => 'amatic']],
        );
        $categoryIds = [$amatic->id];
        foreach ((array) $this->option('category') as $name) {
            $categoryIds[] = Category::firstOrCreate(
                ['slug' => Str::slug((string) $name)],
                ['title' => (string) $name, 'position' => 10],
            )->id;
        }
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
                    // unverified maths stays out of the lobby until someone reviews it
                    'is_visible' => ! str_contains($math['source'], 'unverified'),
                ],
            );
            $row->categories()->syncWithoutDetaching($categoryIds);
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
     * @return array<string, mixed>|null null when there's no usable package
     */
    private function mathFromLegacy(string $legacyCode): ?array
    {
        $dir = config('legacy.games_backend_path').'/'.$legacyCode;
        $parser = EgtGameParser::fromDir($dir, $legacyCode);

        if (! $parser || ! ($parser->isLineSlot() || $parser->isWaysSlot())) {
            if ($parser && ($why = $parser->waysUnsupported())) {
                $this->warn("  {$legacyCode}: {$why}");
            }

            return null;
        }

        $attrs = $parser->templateAttributes();
        if ($ways = $attrs['bonus_config']['ways'] ?? null) {
            $this->line(sprintf(
                '  ways pays: %d paying symbols, doubler=%s, free spins pay on any reel=%s, free spins table=%s',
                count($ways['symbols']), $ways['doubler'] ?? '-', $ways['free_any_reel'] ? 'yes' : 'no',
                json_encode($attrs['free_spins_table']),
            ));
        }

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

        if ($bets = $this->legacy->betOptions($legacyCode)) {
            $attrs['default_bet_options'] = $bets;
        }
        $this->line('  bet ladder: '.json_encode($attrs['default_bet_options'] ?? null).($bets ? ' (legacy)' : ' (default)'));

        return $attrs;
    }

    /**
     * A table game: its own handler plays it ({@see AmaticProtocol}); what the
     * client needs from us is Amatic's own init frame (chips, table limits,
     * history), captured from the live server.
     *
     * @return array{attrs: array<string, mixed>, source: string, notes: list<string>}
     */
    private function tableGame(string $type, AmaticCdnGame $game, string $dir): array
    {
        $base = [
            'reel_count' => 1, 'row_count' => 1, 'symbol_count' => 1, 'symbols' => [0], 'min_match' => 1,
            'paytable' => [[0, 0]], 'reel_strips' => [], 'paylines' => null,
            'has_free_spins' => false, 'has_gamble' => false,
            'default_bet_options' => [0.1, 0.5, 1, 2, 5, 10],
        ];
        // games whose rules live in a legacy backend package: run that package
        if (str_starts_with($type, 'legacy:')) {
            $package = substr($type, 7);
            app(LegacyServerBridge::class)->prepare($package);   // compiled into storage for the game server
            $this->line("  table game — runs the legacy backend {$package}");

            return [
                'attrs' => $base + [
                    'default_bet_options' => $this->legacy->betOptions($package) ?: $base['default_bet_options'],
                    'bonus_config' => ['table' => ['type' => 'legacy', 'package' => $package]],
                ],
                'source' => 'legacy',
                'notes' => [],
            ];
        }
        $live = new AmaticLiveClient;
        $init = $live->init($game->gameId);
        $live->close();
        $this->line("  table game ({$type}) — init captured from Amatic's server");

        return [
            'attrs' => $base + [
                'bonus_config' => ['table' => array_filter([
                    'type' => $type,
                    'init' => $init,
                    // keno: the client's own pay table, picks × hits → × stake
                    'paytable' => $type === 'keno' ? $this->kenoTable($this->engineJs($dir, $game)) : null,
                ], fn ($v) => $v !== null)],
            ],
            'source' => 'table',
            'notes' => [],
        ];
    }

    /**
     * A WildCat Gaming title: paytable from its engine, Amatic's own init
     * frame captured live, our strips — {@see AmaticWildcatMath}.
     *
     * @return array{attrs: array<string, mixed>, source: string, notes: list<string>}
     */
    private function wildcatGame(AmaticCdnGame $game, string $dir): array
    {
        $live = new AmaticLiveClient;
        $init = $live->init(AmaticWildcatMath::engineId($game->gameId));   // the live id may differ (operator config)
        $live->close();
        $built = app(AmaticWildcatMath::class)->build($game->gameId, $this->engineJs($dir, $game), $init);
        $table = $built['attrs']['bonus_config']['table'];
        if ($table['mechanic'] === 'holdwin') {
            $hw = $table['holdwin'];
            $this->line(sprintf(
                '  WildCat Hold & Win game: %dx%d, %d lines, wild=%s collector=%s (reel %d) coins %d-%d (reels %s), cash pots %s, buy %s',
                count($hw['strips']), $hw['rows'], count($hw['lines']), $hw['wild'], $hw['collector'], $hw['collector_reel'] + 1,
                $hw['coin_first'], $hw['coin_first'] + $hw['coin_slots'] - 1, implode('/', array_map(fn ($r) => $r + 1, $hw['coin_reels'])),
                json_encode($hw['jackpots']), json_encode($table['buy']),
            ));

            return ['attrs' => $built['attrs'], 'source' => 'wildcat', 'notes' => $built['notes']];
        }
        if (in_array($table['mechanic'], ['lines', 'puffer', 'wildrush', 'dinopups', 'sweetcandy'], true)) {
            $l = $table['lines'];
            $this->line(sprintf(
                '  WildCat line slot: %dx%d, %d lines, scatter=%s substitutes=%s, bonus spins %s, feature %s, buy %s',
                count($l['strips']), $l['rows'], count($l['lines']), $l['scatter'], json_encode($l['substitutes']),
                json_encode($l['free_spins']), $l['feature'] ?? $table['mechanic'], json_encode($table['buy']),
            ));

            return ['attrs' => $built['attrs'], 'source' => 'wildcat', 'notes' => $built['notes']];
        }
        $tc = $built['attrs']['tumble_config'];
        $this->line(sprintf(
            '  WildCat tumble game: %dx%d, pays at %s, wild=%s multiplier=%s bonus=%s, bonus spins %s, buy %s',
            $built['attrs']['reel_count'], $built['attrs']['row_count'], implode('/', $tc['tiers']),
            $tc['wild_symbol'] ?? '-', $tc['multiplier_symbol'] ?? '-', $built['attrs']['scatter_symbol'] ?? '-',
            json_encode($built['attrs']['bonus_config']['table']['free_spins']), json_encode($built['attrs']['bonus_config']['table']['buy']),
        ));

        return ['attrs' => $built['attrs'], 'source' => 'wildcat', 'notes' => $built['notes']];
    }

    /**
     * Keno's pay table in its engine: an 11×11 nested array (picks 0–10 ×
     * hits 0–10).
     *
     * @return list<list<int|float>>
     */
    private function kenoTable(string $js): array
    {
        preg_match_all('/=(\[\[[0-9.Ee,\[\]]+\]\])/', $js, $m);
        foreach ($m[1] as $raw) {
            $t = json_decode((string) preg_replace('/(\d)E(\d)/', '$1e$2', $raw), true);
            if (is_array($t) && count($t) === 11 && array_filter($t, fn ($r) => is_array($r) && count($r) === 11) === $t) {
                return $t;
            }
        }
        throw new RuntimeException('No keno pay table found in the engine');
    }

    /**
     * An earlier mirror of the same game page (its `.amatic-game.json`).
     *
     * @return array{0: AmaticCdnGame, 1: string}|null
     */
    private function existingMirror(string $url): ?array
    {
        $key = preg_match('/[?&]game=([^&]+)/', $url, $m) ? strtolower($m[1])
            : (preg_match('#/amarent/([a-z0-9_]+)\.html#i', $url, $m) ? strtolower($m[1]) : null);
        foreach (glob(storage_path('app/game-fetch/*/'.self::MIRROR_MANIFEST)) ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true);
            if (is_array($data) && ($data['key'] ?? null) === $key) {
                return [new AmaticCdnGame(...$data), dirname($manifest)];
            }
        }

        return null;
    }

    /**
     * The mirrored engine scripts (not the shared loader/config) — where the
     * client declares its paytable and paylines.
     */
    private function engineJs(string $dir, AmaticCdnGame $game): string
    {
        $js = '';
        foreach ($game->files as $rel) {
            if (preg_match('#/src/[^/]+\.js$#', $rel) && ! str_contains($rel, '/amarent/')) {
                $js .= "\n".@file_get_contents($dir.'/'.$rel);
            }
        }

        return $js;
    }

    /**
     * Amatic's CDN ids are often shorter than the legacy package names
     * (`admiral` → `AdmiralNelsonAM`): try `<GameId>AM`, then a unique
     * `<GameId>*AM` package. Newer Amatic titles that are reskins of a legacy
     * game (same base reels, verified against Amatic's live init frame) map
     * to that game's package.
     */
    private function guessLegacyCode(string $gameId): ?string
    {
        $reskins = [
            'BlazingCoins100' => 'HotFruits100AM',   // Hot Fruits 100 strips + coin symbols 8–16
        ];
        $root = (string) config('legacy.games_backend_path');
        if (isset($reskins[$gameId]) && is_dir($root.'/'.$reskins[$gameId])) {
            $this->line("  legacy package: {$reskins[$gameId]} (base game of '{$gameId}')");

            return $reskins[$gameId];
        }
        if (is_dir($root.'/'.$gameId.'AM')) {
            return $gameId.'AM';
        }

        $candidates = array_values(array_filter(
            array_map('basename', File::directories($root)),
            fn (string $name) => str_starts_with(strtolower($name), strtolower($gameId)) && str_ends_with($name, 'AM'),
        ));
        if (count($candidates) === 1) {
            $this->line("  legacy package: {$candidates[0]} (matched '{$gameId}')");

            return $candidates[0];
        }
        if ($candidates) {
            $this->warn('Several legacy packages match — pass --legacy=<Code>: '.implode(', ', $candidates));
        }

        return null;
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
        foreach (File::allFiles($dir, true) as $file) {
            if ($file->getFilename() === self::MIRROR_MANIFEST) {
                continue;
            }
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

    /** Poster from a URL (the operator's own tile image) → public disk. */
    private function downloadPoster(string $url, string $code): ?string
    {
        try {
            $response = Http::withUserAgent('Mozilla/5.0')->timeout(30)->get($url);
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            $this->warn("  poster {$url} not available");

            return null;
        }
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'png';
        $rel = 'game-posters/'.$code.'.'.$ext;
        Storage::disk('public')->put($rel, $response->body());

        return $rel;
    }
}
