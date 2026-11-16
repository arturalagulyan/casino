<?php

namespace App\Console\Commands;

use App\Enums\BankType;
use App\Enums\ClientProtocol;
use App\Enums\Currency;
use App\Enums\GameEngine;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameTemplate;
use App\Models\NetEntRound;
use App\Models\Shop;
use App\Models\Tag;
use App\Services\GamePlay\BundleManager;
use App\Services\GamePlay\NetEnt\BetboySite;
use App\Services\GamePlay\NetEnt\IncompleteRound;
use App\Services\GamePlay\NetEnt\NetEntLibrary;
use App\Services\GamePlay\NetEnt\NetEntLiveClient;
use App\Services\GamePlay\NetEnt\NetEntQuirks;
use App\Services\GamePlay\NetEnt\NetEntMirror;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Import a NetEnt game from the Betboy lobby, the way FetchAmaticGameCommand
 * imports Amatic ones:
 *
 *  1. launch it there as a DEMO session (fun credits) and capture NetEnt's own
 *     init + paytable — lines, coin values, bet levels, reels
 *  2. mirror the HTML5 client off its static host ({@see NetEntMirror}) —
 *     `storage/app/game-fetch/<Code>/nth/<game>/game/index.html`, …
 *  3. create/refresh the GameTemplate (client protocol NetEnt) and upload the
 *     mirror as its active bundle
 *  4. record the outcome library — whole demo rounds (`netent:sample`) — up
 *     to --rounds
 *  5. create/refresh a Game in each shop, in the NetEnt category, with the
 *     lobby's tile as its poster
 *
 *   php artisan netent:fetch happy_riches
 *   php artisan netent:fetch "Happy Riches" --code=HappyRiches --shop=4 --rounds=5000
 */
class FetchNetEntGameCommand extends Command
{
    protected $signature = 'netent:fetch
        {game : Betboy game id or name (happy_riches, "Happy Riches")}
        {--code= : Template code (default: the name in StudlyCase, e.g. HappyRiches)}
        {--title= : Display title (default: the lobby\'s name)}
        {--shop=* : Shop id or name to add the game to (repeatable; default: every shop)}
        {--currency=EUR : Pricing currency of the bet ladder}
        {--rtp=90 : Per-shop RTP %}
        {--max-win=2000 : Per-shop max win (× bet)}
        {--category=* : Tag (by name, created if missing) for the game — the provider category is always "NetEnt"}
        {--poster= : Poster image URL (default: the Betboy lobby tile)}
        {--rounds=3000 : Outcome library size to record up to}
        {--reuse-mirror : Use an earlier mirror instead of downloading the client again}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Import a NetEnt game from the Betboy lobby: mirror its client, capture its setup, record its outcome library';

    public function handle(BetboySite $betboy, NetEntMirror $mirror, BundleManager $bundles, NetEntLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        $shops = $this->shops();
        if ($shops === null) {
            return self::FAILURE;
        }

        // 1. the game, and NetEnt's own setup from a demo session
        try {
            $lobby = $betboy->game((string) $this->argument('game'));
            $demo = $betboy->demo((string) $lobby['stringId']);
            $client = new NetEntLiveClient($demo['page']);
            // a table game (roulette, …) runs on our own engine: no paytable, no
            // stake to measure, no outcome library — see NetEntQuirks::table()
            $table = NetEntQuirks::table($client->gameId());
            $initRaw = $client->raw('init');
            $paytable = $table ? '' : $client->raw('paytable');
            $init = NetEntRound::fields($initRaw);
            if (($init['playercurrencyiso'] ?? '') !== 'NAN' && ($init['playforfun'] ?? '') !== 'true') {
                throw new RuntimeException("Betboy opened a session in {$init['playercurrencyiso']}, not a demo — refusing to stake real money.");
            }
            // what a spin costs (coins at level 1): measured on a demo round, as
            // games price lines, ways and bet options differently
            $betLines = NetEntLiveClient::betLines($init);
            $recordDenom = NetEntQuirks::recordDenomination($init);
            $coins = 0;
            for ($i = 0; $table === null && $i < 30 && $coins === 0; $i++) {   // a plain one-step round that names the credit
                try {
                    $r = $client->round($betLines, 1, $recordDenom);
                } catch (IncompleteRound) {   // stuck in a pick bonus: a fresh session
                    $client = new NetEntLiveClient($betboy->demo((string) $lobby['stringId'])['page']);
                    $client->init();

                    continue;
                }
                $coins = NetEntQuirks::isPlain($client->gameId(), $r['steps']) && $r['stake'] !== null ? intdiv($r['stake'], $recordDenom) : 0;
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (($lobby['providerName'] ?? '') !== 'NetEnt') {
            $this->warn("  Betboy lists '{$lobby['name']}' under {$lobby['providerName']}, not NetEnt — trying anyway");
        }
        $gameId = $client->gameId();
        $lines = NetEntRound::lineCount($init);
        $denoms = array_map('intval', explode(',', (string) ($init['denomination.all'] ?? '1')));
        $levels = array_map('intval', explode(',', (string) ($init['betlevel.all'] ?? '1')));
        $reels = count(array_filter(array_keys($init), fn ($k) => preg_match('/^rs\.i0\.r\.i\d+\.hold$/', $k)));
        // chip values: `denominations` (roulette), `denomination.all` (baccarat), per hand count (video poker)
        $chips = array_map('intval', explode(',', (string) ($init['denominations'] ?? $init['denomination.all'] ?? $init['denominations.h1'] ?? '')));
        if ($table) {
            $this->info("{$lobby['name']} — NetEnt {$gameId}: {$table[0]} ({$table[1]}), chips ".implode(',', $chips).' ¢ — served by our own engine');
        } else {
            $this->info("{$lobby['name']} — NetEnt {$gameId}: betlines {$betLines}, {$coins} coins a spin, {$reels} reels, coin values ".implode(',', $denoms).' ¢, levels '.implode(',', $levels));
        }
        if ($table === null && $coins < 1) {
            $this->error("A demo spin cost {$coins} coins — not a slot this importer knows.");

            return self::FAILURE;
        }

        // 2. the client
        $code = (string) ($this->option('code') ?: self::CODES[$client->gameId()] ?? self::codeFor((string) $lobby['name']));
        // codes compare case-insensitively: never take over another game's template
        // (Blackjack Touch's "Blackjack" is the Amatic "BlackJack")
        $existing = GameTemplate::query()->where('code', $code)->first();
        if ($existing && ($existing->bonus_config['netent']['game_id'] ?? null) !== $client->gameId()) {
            $this->error("Template code {$code} is taken by #{$existing->id} {$existing->code} ({$existing->title}) — pass another --code.");

            return self::FAILURE;
        }
        $title = (string) ($this->option('title') ?: $lobby['name']);
        $dir = storage_path("app/game-fetch/{$code}");
        $manifest = "{$dir}/.netent-game.json";
        if ($this->option('reuse-mirror') && is_file($manifest)) {
            $m = (array) json_decode((string) file_get_contents($manifest), true);
            $this->info("Reusing the mirror at {$dir}");
        } else {
            $this->info("Mirroring {$demo['page']}");
            $staging = storage_path('app/game-fetch/_incoming-'.getmypid());
            try {
                $m = $mirror->mirror($demo['page'], $staging, fn (string $msg) => $this->line("  <fg=gray>·</> {$msg}"));
            } catch (RuntimeException $e) {
                File::deleteDirectory($staging);
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            File::deleteDirectory($dir);
            File::moveDirectory($staging, $dir);
            File::put($manifest, json_encode($m, JSON_UNESCAPED_SLASHES));
        }
        $this->table([], [
            ['entry', $m['entry']],
            ['languages', implode(',', $m['languages'])],
            ['files', sprintf('%d (%.1f MB)', $m['files'], $m['bytes'] / 1048576)],
            ['mirrored to', $dir],
        ]);
        foreach ($m['warnings'] as $w) {
            $this->warn($w);
        }
        if ($m['missing'] !== []) {
            $this->warn(count($m['missing']).' required file(s) the host did not have: '.implode(', ', $m['missing']));
        }

        // 3. template — the maths is the outcome library; the columns just describe the grid
        $currency = Currency::tryFrom(strtoupper((string) $this->option('currency'))) ?? Currency::EUR;
        $ladder = $table ? array_map(fn (int $c) => $c / 100, array_filter($chips)) : $this->ladder($coins, $levels, $denoms);
        $bankType = $table ? BankType::Table : BankType::Slots;
        $template = GameTemplate::updateOrCreate(['code' => $code], array_filter([
            'title' => $title,
            'engine' => GameEngine::Internal,
            'device' => 'both',
            'bank_type' => $bankType,
            'client_protocol' => ClientProtocol::NetEnt,
            'pricing_currency' => $currency,
            'default_denomination' => 1,
            'default_bet_options' => $ladder,
            'reel_count' => $reels ?: 5, 'row_count' => 3, 'min_match' => 3,
            'symbols' => [], 'symbol_count' => 0, 'paytable' => [], 'reel_strips' => [], 'paylines' => null,
            'has_free_spins' => $table === null, 'has_gamble' => false,
            'bonus_config' => ['netent' => [
                'game_id' => $gameId, 'betboy_id' => $lobby['stringId'], 'name' => $lobby['name'], 'table' => $table,
                'lines' => $lines, 'bet_coins' => $coins, 'betlines' => $betLines, 'record_denomination' => $recordDenom, 'denominations' => $denoms, 'levels' => $levels,
                'languages' => $m['languages'], 'init' => $initRaw, 'paytable' => $paytable,
            ]],
            'poster_path' => $this->poster($betboy, $lobby, $code),
            'is_active' => true,
        ], fn ($v) => $v !== null));
        $this->info(($template->wasRecentlyCreated ? 'Created' : 'Updated')." template #{$template->id} {$code} — {$title}");

        // 4. bundle
        $zip = $this->zip($dir, $code);
        $bundle = $bundles->store($template, new UploadedFile($zip, basename($zip), 'application/zip', null, true),
            entry: $m['entry'], notes: Str::limit("NetEnt client mirror (netent:fetch) of {$gameId}", 250));
        File::delete($zip);
        $this->info("Bundle v{$bundle->version} active — {$bundle->file_count} files, entry {$bundle->entry}");

        // 5. the outcome library (slots)
        $stats = $table ? null : $library->stats($gameId);
        $short = $stats === null ? 0 : (int) $this->option('rounds') - $stats['rounds'];
        if ($short > 0) {   // empty, or an earlier import stopped part-way
            $this->line("  recording {$short} demo rounds for the outcome library…");
            $this->call('netent:sample', ['game' => $lobby['stringId'], '--rounds' => $short,
                '--betboy-token' => $this->option('betboy-token')]);
            $library->forget($gameId);
            $stats = $library->stats($gameId);
        }
        if ($stats !== null) {
            $this->info(sprintf('Outcome library: %d rounds (%d with features), return %.2f%%, top win %d× the stake',
                $stats['rounds'], $stats['features'], $stats['rtp'], intdiv($stats['max_coins'], $coins)));
        }
        if ($stats !== null && $stats['rounds'] < 20000) {
            $this->warn("  a small library repeats rounds and misses rare big wins — grow it with: php artisan netent:sample {$lobby['stringId']} --rounds=50000");
        }

        // 6. games
        $category = Category::where('external_ref', 'betboy:netent')->first()
            ?? Category::firstOrCreate(['slug' => 'netent'], ['title' => 'NetEnt', 'position' => 5]);
        foreach ((array) $this->option('category') as $name) {
            $template->tags()->syncWithoutDetaching([Tag::firstOrCreate(['slug' => Str::slug((string) $name)], ['name' => (string) $name, 'position' => 10])->id]);
        }
        foreach ($shops as $shop) {
            $row = Game::updateOrCreate(['shop_id' => $shop->id, 'template_id' => $template->id], [
                'title' => $title, 'bank_type' => $bankType, 'rtp_percent' => (int) $this->option('rtp'),
                'max_win_multiplier' => (int) $this->option('max-win'), 'reserve_percent' => 4,
                'bet_options' => $ladder, 'denomination' => 1, 'pricing_currency' => $currency, 'is_visible' => true,
            ]);
            $row->categories()->syncWithoutDetaching([$category->id]);
            $this->line("  <fg=green>✓</> game #{$row->id} in shop {$shop->name}");
        }

        $this->newLine();
        $this->info('Done. Try it: '.url("/games/demo/{$code}"));

        return self::SUCCESS;
    }

    /** Codes a lobby name would give that another game's template already has. */
    private const array CODES = [
        'blackjack_touch' => 'BlackjackTouch',   // "Blackjack" — the Amatic "BlackJack" (codes compare case-insensitively)
    ];

    /**
     * A template code from a lobby name — letters and digits only, as it is a
     * URL segment (`/games/{code}/…`): "Gonzo's Gold" → GonzosGold,
     * "Motörhead" → Motorhead, "Street Fighter II: The World Warrior Slot" →
     * StreetFighterIITheWorldWarriorSlot.
     */
    public static function codeFor(string $name): string
    {
        $words = preg_replace('/[^A-Za-z0-9]+/', ' ', str_replace(["'", '’'], '', Str::ascii($name)));

        return str_replace(' ', '', ucwords(trim((string) $words)));
    }

    /**
     * Every stake the client can set: coins a spin × level × coin value.
     *
     * @param  list<int>  $levels
     * @param  list<int>  $denoms  coin values, cents
     * @return list<float>
     */
    private function ladder(int $coins, array $levels, array $denoms): array
    {
        $out = [];
        foreach ($denoms as $d) {
            foreach ($levels as $l) {
                $out[] = round($coins * $l * $d / 100, 2);
            }
        }
        $out = array_values(array_unique($out, SORT_REGULAR));
        sort($out);

        return $out;
    }

    /** @param array<string, mixed> $lobby */
    private function poster(BetboySite $betboy, array $lobby, string $code): ?string
    {
        $url = (string) ($this->option('poster') ?: (($lobby['pic'] ?? '') !== '' ? $betboy->absolute((string) $lobby['pic']) : ''));
        if ($url === '') {
            return null;
        }
        try {
            $response = Http::withUserAgent('Mozilla/5.0')->timeout(30)->get($url);
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            $this->warn("  poster {$url} not available");

            return null;
        }
        // by the bytes, not the URL — Betboy serves WebP as `….jpg`
        $ext = match (getimagesizefromstring($response->body())['mime'] ?? null) {
            'image/webp' => 'webp', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            default => strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'png',
        };
        $rel = "game-posters/{$code}.{$ext}";
        Storage::disk('public')->put($rel, $response->body());
        $this->line("  poster {$url} → {$rel}");

        return $rel;
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
            if ($file->getFilename() === '.netent-game.json') {
                continue;
            }
            $archive->addFile($file->getPathname(), $code.'/'.str_replace('\\', '/', $file->getRelativePathname()));
        }
        $archive->close();

        return $path;
    }
}
