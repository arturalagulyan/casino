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
use App\Services\GamePlay\Amatic\AmaticPageRecorder;
use App\Services\GamePlay\BundleManager;
use App\Services\GamePlay\Merkur\MerkurLibrary;
use App\Services\GamePlay\Merkur\MerkurLiveClient;
use App\Services\GamePlay\Merkur\MerkurMirror;
use App\Services\GamePlay\NetEnt\BetboySite;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import a Merkur (Edict) slot from the Betboy lobby, the way apollo:fetch
 * imports Apollo ones:
 *
 *  1. launch it there as a DEMO session (fun credits) and capture Edict's
 *     setup — launcher data, domain settings, the INIT (coins, lines, paytable)
 *  2. mirror the HTML5 client off its static host ({@see MerkurMirror}) —
 *     `storage/app/game-fetch/<Code>/iframe2.html`, `commonStatic2/…`, `<game>/html/…`
 *  3. create/refresh the GameTemplate (client protocol Merkur) and upload the
 *     mirror as its active bundle
 *  4. record the outcome library — whole demo rounds (`merkur:sample`) — up
 *     to --rounds
 *  5. create/refresh a Game in each shop, in the Merkur category, with the
 *     lobby's tile as its poster
 *
 * Every spin plays all lines of the game's largest line set (stake = coin ×
 * lines); the bet options are the coins × that line count.
 *
 *   php artisan merkur:fetch multifruits_mk_html
 *   php artisan merkur:fetch "Multi Fruits" --shop=4 --rounds=20000
 *   php artisan merkur:fetch --all
 *   php artisan merkur:fetch --all --reuse-init --reuse-mirror --rounds=0   # rebuild, no game launches
 */
class FetchMerkurGameCommand extends Command
{
    use ImportsBetboyGames;

    protected $signature = 'merkur:fetch
        {game? : Betboy game id or name (multifruits_mk_html, "Multi Fruits")}
        {--all : Every Merkur game in the Betboy lobby}
        {--code= : Template code (default: the name in StudlyCase, e.g. MultiFruits)}
        {--title= : Display title (default: the lobby\'s name)}
        {--shop=* : Shop id or name to add the game to (repeatable; default: every shop)}
        {--currency=EUR : Pricing currency of the stakes}
        {--rtp=90 : Per-shop RTP %}
        {--max-win=2000 : Per-shop max win (× bet)}
        {--category=* : Tag (by name, created if missing) for the game — the provider category is always "Merkur"}
        {--poster= : Poster image URL (default: the Betboy lobby tile)}
        {--rounds=3000 : Outcome library size to record up to}
        {--reuse-mirror : Use an earlier mirror instead of downloading the client again}
        {--reuse-init : Take the setup captured by an earlier import instead of launching a demo (no Betboy launch)}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Import a Merkur (Edict) slot from the Betboy lobby: mirror its client, capture its setup, record its outcome library';

    public function handle(BetboySite $betboy, MerkurMirror $mirror, BundleManager $bundles, MerkurLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        if ($this->shops() === null) {
            return self::FAILURE;
        }
        if ($this->option('all')) {
            $failed = [];
            foreach ($betboy->games() as $g) {
                if (($g['providerName'] ?? '') !== 'Merkur') {
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

    private function import(BetboySite $betboy, MerkurMirror $mirror, BundleManager $bundles, MerkurLibrary $library, string $key): int
    {
        $shops = $this->shops() ?? [];

        // 1. the game, and Edict's own setup from a demo session
        try {
            $lobby = $betboy->game($key);
            $earlier = $this->option('reuse-init') ? GameTemplate::query()->where('client_protocol', ClientProtocol::Merkur)->get()
                ->first(fn (GameTemplate $t) => ($t->bonus_config['merkur']['betboy_id'] ?? null) === $lobby['stringId']) : null;
            if ($earlier !== null) {
                $setup = array_intersect_key((array) $earlier->bonus_config['merkur'], ['launcher' => 1, 'domain' => 1, 'init' => 1]);
                $this->line("  setup from template #{$earlier->id} {$earlier->code}");
            } else {
                $client = MerkurLiveClient::fromPage($betboy->demo((string) $lobby['stringId'])['page']);
                $setup = $client->open();   // refuses anything but a fun-credit session
                $client->close();
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (($lobby['providerName'] ?? '') !== 'Merkur') {
            $this->warn("  Betboy lists '{$lobby['name']}' under {$lobby['providerName']}, not Merkur — trying anyway");
        }
        $folder = (string) $lobby['stringId'];
        $init = (array) $setup['init'];
        $split = MerkurLiveClient::splitCoins($init, MerkurMirror::splitCoins($folder));
        $play = MerkurLiveClient::recording($init, $split);
        $coins = array_values(array_map('intval', (array) ($init['coins'] ?? [])));
        sort($coins);
        if ($play['positions'] === [] || $coins === []) {
            $this->error('The demo INIT names no lines or coins: '.mb_strimwidth(json_encode($init) ?: '', 0, 300));

            return self::FAILURE;
        }
        $n = count($play['positions']);
        // total stakes: the coins, or the coins × the lines (coin per line)
        $stakes = array_map(fn (int $c) => round(($split ? $c : $c * $n) / 100, 2), $coins);
        $reelsList = array_values((array) ($init['mainGameResult']['reels'] ?? []));
        $reels = count($reelsList);
        $rows = (int) ($reelsList[0]['visibleSymbolCount'] ?? 3);
        $this->info("{$lobby['name']} — Merkur {$folder} ({$setup['launcher']['casinoGameTemplateName']}): {$reels}×{$rows}, {$n} lines, "
            .($split ? 'coin = total bet' : 'coin per line').', stakes '.implode(',', $stakes).' (recorded at '.$stakes[0].'), Merkur RTP '.($init['targetRtp'] ?? '?').'%');

        // 2. the client
        $multi = $this->option('all');
        $code = (string) (($multi ? null : $this->option('code')) ?: FetchNetEntGameCommand::codeFor(trim((string) $lobby['name'])));
        // codes compare case-insensitively: never take over another game's template
        $existing = GameTemplate::query()->where('code', $code)->first();
        if ($existing && ($existing->bonus_config['merkur']['game_key'] ?? null) !== $folder) {
            $this->error("Template code {$code} is taken by #{$existing->id} {$existing->code} ({$existing->title}) — pass another --code.");

            return self::FAILURE;
        }
        $title = (string) (($multi ? null : $this->option('title')) ?: trim((string) $lobby['name']));
        $dir = storage_path("app/game-fetch/{$code}");
        $manifest = "{$dir}/.merkur-game.json";
        if ($this->option('reuse-mirror') && is_file($manifest)) {
            $m = (array) json_decode((string) file_get_contents($manifest), true);
            $this->info("Reusing the mirror at {$dir}");
        } else {
            $this->info('Mirroring '.MerkurMirror::HOST."{$folder}/");
            $staging = storage_path('app/game-fetch/_incoming-'.getmypid());
            try {
                $recorded = MerkurMirror::isLegacy($folder) ? $this->record($betboy, $folder) : [];
                $m = $mirror->mirror($folder, $staging, (array) ($setup['domain']['languages'] ?? ['en']), fn (string $msg) => $this->output->write("\r  <fg=gray>·</> {$msg}   "), $recorded);
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
        if (($m['missing'] ?? []) !== []) {
            $this->warn('  missing: '.implode(', ', array_slice((array) $m['missing'], 0, 10)));
        }
        $this->table([], [
            ['entry', $m['entry']],
            ['languages', implode(',', $m['languages'])],
            ['files', sprintf('%d (%.1f MB)', $m['files'], $m['bytes'] / 1048576)],
            ['mirrored to', $dir],
        ]);

        // 3. template — the maths is the outcome library; the columns just describe the grid
        $currency = Currency::tryFrom(strtoupper((string) $this->option('currency'))) ?? Currency::EUR;
        $launcher = (array) $setup['launcher'];
        $template = GameTemplate::updateOrCreate(['code' => $code], array_filter([
            'title' => $title,
            'engine' => GameEngine::Internal,
            'device' => 'both',
            'bank_type' => BankType::Slots,
            'client_protocol' => ClientProtocol::Merkur,
            'pricing_currency' => $currency,
            'default_denomination' => 1,
            'default_bet_options' => $stakes,
            'reel_count' => $reels ?: 5, 'row_count' => $rows ?: 3, 'min_match' => 3,
            'symbols' => [], 'symbol_count' => 0, 'paytable' => [], 'reel_strips' => [], 'paylines' => null,
            'has_free_spins' => true, 'has_gamble' => true,
            'bonus_config' => ['merkur' => [
                'game_key' => $folder, 'betboy_id' => $lobby['stringId'], 'name' => trim((string) $lobby['name']),
                'record_wager' => $play['wager'], 'split_coins' => $split, 'coins' => $coins, 'stakes' => $stakes,
                // the older clients split the coin whenever there is one line set to choose from — and we
                // offer only the largest: offered twice, a game with several keeps its coin per line
                'repeat_line_set' => ($m['legacy'] ?? MerkurMirror::isLegacy($folder)) && count((array) ($init['wagerPositionSets'] ?? [])) > 1,
                'positions' => $play['positions'], 'languages' => $m['languages'],
                'init' => $init, 'launcher' => $launcher, 'domain' => (array) $setup['domain'],
            ]],
            'poster_path' => $this->poster($betboy, $lobby, $code),
            'is_active' => true,
        ], fn ($v) => $v !== null));
        $this->info(($template->wasRecentlyCreated ? 'Created' : 'Updated')." template #{$template->id} {$code} — {$title}");

        // 4. bundle
        $zip = $this->zip($dir, $code);
        $bundle = $bundles->store($template, new UploadedFile($zip, basename($zip), 'application/zip', null, true),
            entry: $m['entry'], notes: Str::limit("Merkur client mirror (merkur:fetch) of {$folder}", 250));
        File::delete($zip);
        $this->info("Bundle v{$bundle->version} active — {$bundle->file_count} files, entry {$bundle->entry}");

        // 5. the outcome library
        $stats = $library->stats($folder);
        $short = (int) $this->option('rounds') - $stats['rounds'];
        if ($short > 0) {   // empty, or an earlier import stopped part-way
            $this->line("  recording {$short} demo rounds for the outcome library…");
            $this->call('merkur:sample', ['game' => $lobby['stringId'], '--rounds' => $short,
                '--betboy-token' => $this->option('betboy-token')]);
            $library->forget($folder);
            $stats = $library->stats($folder);
        }
        $this->info(sprintf('Outcome library: %d rounds (%d with features), return %.2f%%, top win %d× the stake',
            $stats['rounds'], $stats['features'], $stats['rtp'], intdiv($stats['max_cents'], max(1, $play['stake']))));
        if ($stats['rounds'] < 20000) {
            $this->warn("  a small library repeats rounds and misses rare big wins — grow it with: php artisan merkur:sample {$lobby['stringId']} --rounds=50000");
        }

        // 6. games
        $category = Category::where('external_ref', 'betboy:merkur')->first()
            ?? Category::firstOrCreate(['slug' => 'merkur'], ['title' => 'Merkur', 'position' => 14]);
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

    /**
     * The older clients name most files at run time: what a live demo page
     * loads (desktop and mobile), recorded in a browser — one at a time, the
     * Selenium container serves a single session.
     *
     * @return list<string>
     */
    private function record(BetboySite $betboy, string $folder): array
    {
        $this->output->write('  <fg=gray>·</> recording a live page in a browser…');
        File::ensureDirectoryExists(storage_path('app/game-fetch'));
        $lock = fopen(storage_path('app/game-fetch/.merkur-recorder.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $urls = app(AmaticPageRecorder::class)->record($betboy->demo($folder)['page']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->line(' '.count($urls).' files');

        return $urls;
    }
}
