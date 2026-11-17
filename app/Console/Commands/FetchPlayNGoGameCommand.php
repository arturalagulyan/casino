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
use App\Models\Tag;
use App\Services\GamePlay\BundleManager;
use App\Services\GamePlay\PlayNGo\PlayNGoLibrary;
use App\Services\GamePlay\PlayNGo\PlayNGoLiveClient;
use App\Services\GamePlay\PlayNGo\PlayNGoMirror;
use App\Services\GamePlay\PlayNGo\PlayNGoSite;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Import a Play'n GO game from its practice launcher URL: mirror the client
 * (Play'n GO's CDN build + its ContainerLauncher), capture its configuration,
 * and create the template, bundle and games. Its maths is the outcome library
 * recorded by `playngo:sample` — this command samples a first batch when the
 * library is empty.
 *
 *   php artisan playngo:fetch "https://released.playngonetwork.com/casino/ContainerLauncher?pid=2&gid=tomeofmadness&lang=en_GB&practice=1&channel=desktop&demo=2"
 */
class FetchPlayNGoGameCommand extends Command
{
    protected $signature = 'playngo:fetch
        {url : Play\'n GO launcher URL (…/casino/ContainerLauncher?…&gid=<game>&practice=1…)}
        {--code= : Template code (default: the game name in StudlyCase, e.g. TomeOfMadness)}
        {--title= : Display title (default: Play\'n GO\'s name)}
        {--shop=* : Shop id or name to add the game to (repeatable; default: every shop)}
        {--currency=EUR : Pricing currency of the bet ladder}
        {--rtp=90 : Per-shop RTP %}
        {--max-win=2000 : Per-shop max win (× bet)}
        {--category=* : Tag (by name, created if missing) for the game — the provider category is always "Play\'n GO"}
        {--poster= : Poster image URL}
        {--rounds=500 : Practice rounds to record when the library is empty}
        {--spin= : The spin line to record with, "1 <coins> <lines> <coin value> 1" (default: the game\'s own default bet)}
        {--reuse-mirror : Use an earlier mirror instead of downloading the client again}';

    protected $description = "Import a Play'n GO game: mirror its client, capture its configuration, record its outcome library";

    /**
     * The coins and lines a spin plays: the configuration's default coins
     * (`defaultLimit`) on all its lines (`lines`, 0 for games without a line
     * choice: one), or `1 <coins> <lines> …` given.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: int, 1: int}
     */
    public static function spinOf(array $config, ?string $spin = null): array
    {
        if ($spin !== null && preg_match('/^1 (\d+) (\d+) /', $spin, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [max(1, (int) ($config['defaultLimit'] ?? 10)), max(1, (int) ($config['lines'] ?? 0))];
    }

    /**
     * Every stake: the game's own coin values (its Denominations) × the coins a spin plays.
     *
     * @param  array<string, mixed>  $config
     * @return list<float>
     */
    public static function ladder(array $config, int $betCoins): array
    {
        preg_match_all('/Value="([\d.]+)"/', (string) app(PlayNGoSite::class)->settingsXml($config), $m);
        $values = array_map('floatval', $m[1]) ?: [0.01, 0.02, 0.05, 0.1, 0.2, 0.5, 1];
        $out = array_values(array_unique(array_map(fn (float $v) => round($v * $betCoins, 2), $values)));
        sort($out);

        return $out;
    }

    public function handle(PlayNGoSite $site, PlayNGoMirror $mirror, BundleManager $bundles, PlayNGoLibrary $library): int
    {
        $url = (string) $this->argument('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $gid = strtolower((string) ($q['gid'] ?? ''));
        if ($gid === '') {
            $this->error('The URL names no game (gid=…).');

            return self::FAILURE;
        }
        $shops = $this->shops();
        if ($shops === null) {
            return self::FAILURE;
        }

        // 1. the game and its configuration, as Play'n GO serves them
        $game = $site->game($gid);
        $config = ['desktop' => $site->configuration($game['id'], 'desktop'), 'mobile' => $site->configuration($game['id'], 'mobile')];
        $prelaunch = $site->prelaunch($game['id']);
        $build = trim((string) parse_url((string) $config['desktop']['resourceRoot'], PHP_URL_PATH), '/');
        // the bet the library is recorded at: the client's default coins × lines
        // (line games — Book of Dead 5 × 10; grid games 20 × 1). Wins sit in game-
        // specific places of the replies, so only the coin value is the player's.
        [$spinCoins, $spinLines] = self::spinOf($config['desktop'], $this->option('spin') ?: null);
        $betCoins = $spinCoins * $spinLines;
        // no gamble: a player's guess is no recorded outcome
        foreach (['desktop', 'mobile'] as $channel) {
            $config[$channel]['neverGamble'] = true;
        }
        $ladder = self::ladder($config['desktop'], $betCoins);
        $this->info("{$game['name']} (#{$game['id']}, {$game['type']}) — build {$build}");

        // 2. the client: Play'n GO's CDN files a real session loads, plus what their manifests name
        $dir = storage_path("app/game-fetch/playngo-{$gid}");
        if (! $this->option('reuse-mirror') || ! is_dir("{$dir}/cdn")) {
            $page = 'https://released.playngonetwork.com/casino/ContainerLauncher?pid=2&gid='.$gid.'&lang=en_GB&practice=1&demo=2&channel=';
            $r = $mirror->mirror([$page.'desktop', $page.'mobile'], $dir, fn (string $m) => $this->line("  {$m}"));
            if ($r['missing'] !== []) {
                $this->warn('  '.count($r['missing']).' named file(s) the CDN did not have (other quality levels / channels are normal): '
                    .implode(', ', array_slice($r['missing'], 0, 5)));
            }
        }
        $launcher = $this->patchLauncher($dir);

        // the game's own init state (Play'n GO's `104` reply beyond the plumbing)
        $live = new PlayNGoLiveClient;
        try {
            $initLines = PlayNGoLiveClient::initExtras($live->open($game['id'], $game['name'], app(PlayNGoSite::class)->settingsXml($config)));
        } finally {
            $live->close();
        }
        $this->line('  init state: '.($initLines === [] ? 'none' : implode(' | ', $initLines)));

        // 3. template — the maths is the outcome library, the columns just describe the grid
        $code = (string) ($this->option('code') ?: FetchNetEntGameCommand::codeFor($game['name']));   // letters and digits: a URL segment
        $title = (string) ($this->option('title') ?: $game['name']);
        $currency = Currency::tryFrom(strtoupper((string) $this->option('currency'))) ?? Currency::EUR;
        $template = GameTemplate::updateOrCreate(['code' => $code], array_filter([
            'title' => $title,
            'engine' => GameEngine::Internal,
            'device' => 'both',
            'bank_type' => BankType::Slots,
            'client_protocol' => ClientProtocol::PlayNGo,
            'pricing_currency' => $currency,
            'default_denomination' => 1,
            'default_bet_options' => $ladder,
            'reel_count' => 5, 'row_count' => 5, 'min_match' => 4,
            'symbols' => range(0, 12), 'symbol_count' => 13, 'paytable' => [], 'reel_strips' => [], 'paylines' => null,
            'has_free_spins' => true, 'has_gamble' => false,
            'bonus_config' => ['playngo' => [
                'gid' => $game['gid'], 'game_id' => $game['id'], 'name' => $game['name'], 'game_type' => $game['type'],
                'build' => $build, 'launcher' => $launcher, 'bet_coins' => $betCoins, 'spin_coins' => $spinCoins, 'spin_lines' => $spinLines, 'record_coin' => 10,
                'init_lines' => $initLines, 'configuration' => $config, 'prelaunch' => $prelaunch,
            ]],
            'poster_path' => $this->option('poster') ? $this->downloadPoster((string) $this->option('poster'), $code) : null,
            'is_active' => true,
        ], fn ($v) => $v !== null));
        $this->info(($template->wasRecentlyCreated ? 'Created' : 'Updated')." template #{$template->id} {$code} — {$title}");

        // 4. bundle
        $zip = $this->zip($dir, $code);
        $bundle = $bundles->store($template, new UploadedFile($zip, basename($zip), 'application/zip', null, true),
            entry: 'cdn/'.$launcher, notes: Str::limit("Play'n GO CDN mirror (playngo:fetch) of {$gid}, build {$build}", 250));
        File::delete($zip);
        $this->info("Bundle v{$bundle->version} active — {$bundle->file_count} files");

        // 5. the outcome library
        $stats = $library->stats($gid);
        if ($stats['rounds'] === 0) {
            $this->line("  recording {$this->option('rounds')} practice rounds for the outcome library…");
            $this->call('playngo:sample', ['gid' => $gid, '--rounds' => (int) $this->option('rounds'), '--spin' => "1 {$spinCoins} {$spinLines} 10 1"]);
            cache()->forget("playngo:library:{$gid}");
            $stats = $library->stats($gid);
        }
        $this->info(sprintf('Outcome library: %d rounds, return %.2f%%, top win %d× the stake',
            $stats['rounds'], $stats['rtp'], intdiv($stats['max_coins'], $betCoins)));
        if ($stats['rounds'] === 0) {
            // no games without maths: every spin would fail — find the spin (playngo:sample … --spin=) and import again
            $this->error("No rounds recorded — the game's spin was refused. The template is kept; no games were made.");

            return self::FAILURE;
        }
        if ($stats['rounds'] < 20000) {
            $this->warn("  a small library repeats rounds and misses rare big wins — grow it with: php artisan playngo:sample {$gid} --rounds=50000");
        }

        // 6. games
        $categoryIds = [Category::firstOrCreate(['slug' => 'playngo'],
            ['title' => "Play'n GO", 'position' => 5, 'config' => ['client_protocol' => ClientProtocol::PlayNGo->value]])->id];
        // themes are tags on the template, not categories (categories = providers)
        foreach ((array) $this->option('category') as $name) {
            $template->tags()->syncWithoutDetaching([Tag::firstOrCreate(['slug' => Str::slug((string) $name)], ['name' => (string) $name, 'position' => 10])->id]);
        }
        foreach ($shops as $shop) {
            $row = Game::updateOrCreate(['shop_id' => $shop->id, 'template_id' => $template->id], [
                'title' => $title, 'bank_type' => BankType::Slots, 'rtp_percent' => (int) $this->option('rtp'),
                'max_win_multiplier' => (int) $this->option('max-win'), 'reserve_percent' => 4,
                'bet_options' => $ladder, 'denomination' => 1, 'pricing_currency' => $currency, 'is_visible' => true,
            ]);
            $row->categories()->syncWithoutDetaching($categoryIds);
            $this->line("  <fg=green>✓</> game #{$row->id} in shop {$shop->name}");
        }

        $this->newLine();
        $this->info('Done. Try it: '.url("/games/demo/{$code}"));

        return self::SUCCESS;
    }

    /**
     * Point the mirrored ContainerLauncher's "CoreWeb" base (where it reads the
     * game and configuration) at the base our shell page sets, instead of the
     * page's own origin. Returns the launcher's path under `cdn/`.
     */
    private function patchLauncher(string $dir): string
    {
        $files = glob("{$dir}/cdn/hera/*/container-launcher/ContainerLauncher_bundle.js") ?: [];
        if ($files === []) {
            throw new RuntimeException("The mirror has no ContainerLauncher bundle (cdn/hera/…) — the page recording didn't load it");
        }
        $file = $files[0];
        $js = (string) file_get_contents($file);
        // both places the launcher takes its base from the page's origin
        $patches = [
            'this.model.CoreWebUrl=`${window.location.origin}/`' => 'this.model.CoreWebUrl=window.pngCoreWebUrl||`${window.location.origin}/`',
            'window.runtimeConfig={coreWebUrl:`${window.location.origin}/`' => 'window.runtimeConfig={coreWebUrl:window.pngCoreWebUrl||`${window.location.origin}/`',
            // the v2 configuration URL replaces the base's path — append to it instead
            't.pathname=m.default.endpoints.configuration.v2' => 't.pathname=t.pathname.replace(/\/?$/,"/")+m.default.endpoints.configuration.v2',
        ];
        foreach ($patches as $from => $to) {
            if (! str_contains($js, $to)) {
                if (substr_count($js, $from) !== 1) {
                    throw new RuntimeException("This ContainerLauncher build sets its CoreWeb base differently — can't point it at us");
                }
                $js = str_replace($from, $to, $js);
            }
        }
        file_put_contents($file, $js);

        return substr($file, strlen("{$dir}/cdn/"));
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

    /** Zip `$dir` under a `<Code>/` wrapper, which BundleManager strips. */
    private function zip(string $dir, string $code): string
    {
        $path = storage_path("app/game-zips/{$code}.zip");
        File::ensureDirectoryExists(dirname($path));
        if (is_file($path)) {
            unlink($path);
        }
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create {$path}");
        }
        foreach (File::allFiles($dir, true) as $file) {
            $archive->addFile($file->getPathname(), $code.'/'.str_replace('\\', '/', $file->getRelativePathname()));
        }
        $archive->close();

        return $path;
    }

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
