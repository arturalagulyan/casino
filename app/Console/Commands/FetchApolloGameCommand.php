<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ImportsBetboyGames;
use App\Enums\BankType;
use App\Enums\ClientProtocol;
use App\Enums\Currency;
use App\Enums\GameEngine;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameTemplate;
use App\Models\Tag;
use App\Services\GamePlay\Apollo\ApolloLibrary;
use App\Services\GamePlay\Apollo\ApolloLiveClient;
use App\Services\GamePlay\Apollo\ApolloMirror;
use App\Services\GamePlay\BundleManager;
use App\Services\GamePlay\NetEnt\BetboySite;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import an Apollo Games slot from the Betboy lobby, the way netent:fetch
 * imports NetEnt ones:
 *
 *  1. launch it there as a DEMO session (fun credits) and capture Apollo's
 *     init — stakes, paytable, game folder
 *  2. mirror the HTML5 client off its static host ({@see ApolloMirror}) —
 *     `storage/app/game-fetch/<Code>/iframe.html`, `common/…`, `<game>_ag/…`
 *  3. create/refresh the GameTemplate (client protocol Apollo) and upload the
 *     mirror as its active bundle
 *  4. record the outcome library — whole demo rounds (`apollo:sample`) — up
 *     to --rounds
 *  5. create/refresh a Game in each shop, in the Apollo category, with the
 *     lobby's tile as its poster
 *
 *   php artisan apollo:fetch lucky81_ag
 *   php artisan apollo:fetch "Lucky 81" --shop=4 --rounds=20000
 *   php artisan apollo:fetch --all
 *   php artisan apollo:fetch --all --reuse-init --rounds=0   # rebuild the clients, no game launches
 */
class FetchApolloGameCommand extends Command
{
    use ImportsBetboyGames;

    protected $signature = 'apollo:fetch
        {game? : Betboy game id or name (lucky81_ag, "Lucky 81")}
        {--all : Every Apollo game in the Betboy lobby}
        {--code= : Template code (default: the name in StudlyCase, e.g. Lucky81)}
        {--title= : Display title (default: the lobby\'s name)}
        {--shop=* : Shop id or name to add the game to (repeatable; default: every shop)}
        {--currency=EUR : Pricing currency of the stakes}
        {--rtp=90 : Per-shop RTP %}
        {--max-win=2000 : Per-shop max win (× bet)}
        {--category=* : Tag (by name, created if missing) for the game — the provider category is always "Apollo"}
        {--poster= : Poster image URL (default: the Betboy lobby tile)}
        {--rounds=3000 : Outcome library size to record up to}
        {--reuse-mirror : Use an earlier mirror instead of downloading the client again}
        {--reuse-init : Take the init captured by an earlier import instead of launching a demo (no Betboy launch)}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Import an Apollo Games slot from the Betboy lobby: mirror its client, capture its setup, record its outcome library';

    public function handle(BetboySite $betboy, ApolloMirror $mirror, BundleManager $bundles, ApolloLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        if ($this->shops() === null) {
            return self::FAILURE;
        }
        if ($this->option('all')) {
            $failed = [];
            foreach ($betboy->games() as $g) {
                if (($g['providerName'] ?? '') !== 'Apollo') {
                    continue;
                }
                $this->newLine();
                $this->info("━━ {$g['name']} ({$g['stringId']})");
                if ($this->import($betboy, $mirror, $bundles, $library, (string) $g['stringId']) !== self::SUCCESS) {
                    $failed[] = $g['stringId'];
                }
            }
            if ($failed !== []) {
                $this->error('Failed: '.implode(', ', $failed));
            }

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }
        if (! $this->argument('game')) {
            $this->error('Name a game, or pass --all.');

            return self::FAILURE;
        }

        return $this->import($betboy, $mirror, $bundles, $library, (string) $this->argument('game'));
    }

    private function import(BetboySite $betboy, ApolloMirror $mirror, BundleManager $bundles, ApolloLibrary $library, string $key): int
    {
        $shops = $this->shops() ?? [];

        // 1. the game, and Apollo's own setup from a demo session
        try {
            $lobby = $betboy->game($key);
            $earlier = $this->option('reuse-init') ? GameTemplate::query()->where('client_protocol', ClientProtocol::Apollo)->get()
                ->first(fn (GameTemplate $t) => ($t->bonus_config['apollo']['betboy_id'] ?? null) === $lobby['stringId']) : null;
            if ($earlier !== null) {
                $init = (array) $earlier->bonus_config['apollo']['init'];
                $this->line("  init from template #{$earlier->id} {$earlier->code}");
            } else {
                $client = ApolloLiveClient::fromPage($betboy->demo((string) $lobby['stringId'])['page']);
                $init = $client->open();   // refuses anything but a demo session
                $client->close();
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (($lobby['providerName'] ?? '') !== 'Apollo') {
            $this->warn("  Betboy lists '{$lobby['name']}' under {$lobby['providerName']}, not Apollo — trying anyway");
        }
        $folder = (string) ($init['name'] ?? '');
        $stakes = array_values(array_map('floatval', (array) ($init['stakeOptions'] ?? [])));
        sort($stakes);
        if ($folder === '' || $stakes === []) {
            $this->error('The demo init names no game folder or stakes: '.mb_strimwidth(json_encode($init) ?: '', 0, 300));

            return self::FAILURE;
        }
        // reelsSymbols is row by row: [[r1c1, r1c2, …], [r2c1, …], …]
        $rows = count((array) ($init['gameState']['reelsSymbols'] ?? []));
        $reels = count((array) ($init['gameState']['reelsSymbols'][0] ?? []));
        $this->info("{$lobby['name']} — Apollo {$folder} ({$init['gameName']}): {$reels}×{$rows}, {$init['gameState']['numberOfWinningLines']} lines, stakes ".implode(',', $stakes)
            ." (recorded at {$stakes[0]}), Apollo RTP {$init['rtp']}%");

        // 2. the client
        $multi = $this->option('all');
        $code = (string) (($multi ? null : $this->option('code')) ?: FetchNetEntGameCommand::codeFor((string) $lobby['name']));
        // codes compare case-insensitively: never take over another game's template
        $existing = GameTemplate::query()->where('code', $code)->first();
        if ($existing && ($existing->bonus_config['apollo']['game_key'] ?? null) !== $folder) {
            $this->error("Template code {$code} is taken by #{$existing->id} {$existing->code} ({$existing->title}) — pass another --code.");

            return self::FAILURE;
        }
        $title = (string) (($multi ? null : $this->option('title')) ?: $lobby['name']);
        $dir = storage_path("app/game-fetch/{$code}");
        $manifest = "{$dir}/.apollo-game.json";
        if ($this->option('reuse-mirror') && is_file($manifest)) {
            $m = (array) json_decode((string) file_get_contents($manifest), true);
            $this->info("Reusing the mirror at {$dir}");
        } else {
            $this->info('Mirroring '.ApolloMirror::HOST."{$folder}/");
            $staging = storage_path('app/game-fetch/_incoming-'.getmypid());
            try {
                $m = $mirror->mirror($folder, $staging, fn (string $msg) => $this->output->write("\r  <fg=gray>·</> {$msg}   "));
                $this->newLine();
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

        // 3. template — the maths is the outcome library; the columns just describe the grid
        $currency = Currency::tryFrom(strtoupper((string) $this->option('currency'))) ?? Currency::EUR;
        unset($init['player'], $init['clientIpAddress']);
        $template = GameTemplate::updateOrCreate(['code' => $code], array_filter([
            'title' => $title,
            'engine' => GameEngine::Internal,
            'device' => 'both',
            'bank_type' => BankType::Slots,
            'client_protocol' => ClientProtocol::Apollo,
            'pricing_currency' => $currency,
            'default_denomination' => 1,
            'default_bet_options' => $stakes,
            'reel_count' => $reels ?: 5, 'row_count' => $rows ?: 3, 'min_match' => 3,
            'symbols' => [], 'symbol_count' => 0, 'paytable' => [], 'reel_strips' => [], 'paylines' => null,
            'has_free_spins' => true, 'has_gamble' => true,
            'bonus_config' => ['apollo' => [
                'game_key' => $folder, 'game_name' => $init['gameName'] ?? null, 'betboy_id' => $lobby['stringId'], 'name' => $lobby['name'],
                'record_stake' => $stakes[0], 'stakes' => $stakes, 'languages' => $m['languages'], 'init' => $init,
            ]],
            'poster_path' => $this->poster($betboy, $lobby, $code),
            'is_active' => true,
        ], fn ($v) => $v !== null));
        $this->info(($template->wasRecentlyCreated ? 'Created' : 'Updated')." template #{$template->id} {$code} — {$title}");

        // 4. bundle
        $zip = $this->zip($dir, $code);
        $bundle = $bundles->store($template, new UploadedFile($zip, basename($zip), 'application/zip', null, true),
            entry: $m['entry'], notes: Str::limit("Apollo client mirror (apollo:fetch) of {$folder}", 250));
        File::delete($zip);
        $this->info("Bundle v{$bundle->version} active — {$bundle->file_count} files, entry {$bundle->entry}");

        // 5. the outcome library
        $stats = $library->stats($folder);
        $short = (int) $this->option('rounds') - $stats['rounds'];
        if ($short > 0) {   // empty, or an earlier import stopped part-way
            $this->line("  recording {$short} demo rounds for the outcome library…");
            $this->call('apollo:sample', ['game' => $lobby['stringId'], '--rounds' => $short,
                '--betboy-token' => $this->option('betboy-token')]);
            $library->forget($folder);
            $stats = $library->stats($folder);
        }
        $this->info(sprintf('Outcome library: %d rounds (%d with free spins), return %.2f%%, top win %d× the stake',
            $stats['rounds'], $stats['features'], $stats['rtp'], intdiv($stats['max_cents'], max(1, (int) round($stakes[0] * 100)))));
        if ($stats['rounds'] < 20000) {
            $this->warn("  a small library repeats rounds and misses rare big wins — grow it with: php artisan apollo:sample {$lobby['stringId']} --rounds=50000");
        }

        // 6. games
        $category = Category::where('external_ref', 'betboy:apollo')->first()
            ?? Category::firstOrCreate(['slug' => 'apollo'], ['title' => 'Apollo', 'position' => 3]);
        foreach ((array) $this->option('category') as $name) {
            $template->tags()->syncWithoutDetaching([Tag::firstOrCreate(['slug' => Str::slug((string) $name)], ['name' => (string) $name, 'position' => 10])->id]);
        }
        foreach ($shops as $shop) {
            $row = Game::updateOrCreate(['shop_id' => $shop->id, 'template_id' => $template->id], [
                'title' => $title, 'bank_type' => BankType::Slots, 'rtp_percent' => (int) $this->option('rtp'),
                'max_win_multiplier' => (int) $this->option('max-win'), 'reserve_percent' => 4,
                'bet_options' => $stakes, 'denomination' => 1, 'pricing_currency' => $currency, 'is_visible' => true,
            ]);
            $row->categories()->syncWithoutDetaching([$category->id]);
            $this->line("  <fg=green>✓</> game #{$row->id} in shop {$shop->name}");
        }

        $this->newLine();
        $this->info('Done. Try it: '.url("/games/demo/{$code}"));

        return self::SUCCESS;
    }
}
