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
use App\Services\GamePlay\Booongo\BooongoLibrary;
use App\Services\GamePlay\Booongo\BooongoLiveClient;
use App\Services\GamePlay\Booongo\BooongoMirror;
use App\Services\GamePlay\BundleManager;
use App\Services\GamePlay\NetEnt\BetboySite;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import a Booongo slot from the Betboy lobby, the way apollo:fetch imports
 * Apollo ones:
 *
 *  1. launch it there as a DEMO session (fun credits) and capture Booongo's
 *     settings (line bets, lines, paytable, reel samples) and idle context
 *  2. mirror the HTML5 game and its game runner off the static host
 *     ({@see BooongoMirror}) — `storage/app/game-fetch/<Code>/index.html`,
 *     `commonStatic/gamerunner/<v>/…`, `<game>/…`
 *  3. create/refresh the GameTemplate (client protocol Booongo) and upload the
 *     mirror as its active bundle
 *  4. record the outcome library — whole demo rounds (`booongo:sample`) — up to --rounds
 *  5. create/refresh a Game in each shop, in the Booongo category, with the
 *     lobby's tile as its poster
 *
 *   php artisan booongo:fetch book_of_sun_bng_html
 *   php artisan booongo:fetch --all --rounds=3000
 */
class FetchBooongoGameCommand extends Command
{
    use ImportsBetboyGames;

    protected $signature = 'booongo:fetch
        {game? : Betboy game id or name (book_of_sun_bng_html, "Book of Sun")}
        {--all : Every Booongo game in the Betboy lobby}
        {--code= : Template code (default: the name in StudlyCase, e.g. BookOfSun)}
        {--title= : Display title (default: the lobby\'s name)}
        {--shop=* : Shop id or name to add the game to (repeatable; default: every shop)}
        {--currency=EUR : Pricing currency of the stakes}
        {--rtp=90 : Per-shop RTP %}
        {--max-win=2000 : Per-shop max win (× bet)}
        {--category=* : Tag (by name, created if missing) for the game — the provider category is always "Booongo"}
        {--poster= : Poster image URL (default: the Betboy lobby tile)}
        {--rounds=3000 : Outcome library size to record up to}
        {--reuse-mirror : Use an earlier mirror instead of recording the client again}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Import a Booongo slot from the Betboy lobby: mirror its client, capture its setup, record its outcome library';

    public function handle(BetboySite $betboy, BooongoMirror $mirror, BundleManager $bundles, BooongoLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        if ($this->shops() === null) {
            return self::FAILURE;
        }
        if ($this->option('all')) {
            $failed = [];
            foreach ($betboy->games() as $g) {
                if (($g['providerName'] ?? '') !== 'Booongo') {
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

    private function import(BetboySite $betboy, BooongoMirror $mirror, BundleManager $bundles, BooongoLibrary $library, string $key): int
    {
        $shops = $this->shops() ?? [];

        // 1. the game, and Booongo's own setup from a demo session
        try {
            $lobby = $betboy->game($key);
            $launch = $betboy->demo((string) $lobby['stringId'])['page'];
            $client = BooongoLiveClient::fromPage($launch);
            $start = $client->open();   // refuses anything but fun credits
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (($lobby['providerName'] ?? '') !== 'Booongo') {
            $this->warn("  Betboy lists '{$lobby['name']}' under {$lobby['providerName']}, not Booongo — trying anyway");
        }
        $settings = (array) $start['settings'];
        $bets = array_values(array_map(fn ($b) => 0 + $b, (array) ($settings['bets'] ?? [])));
        sort($bets);
        $factor = (int) max((array) ($settings['bet_factor'] ?? [1]) ?: [1]);
        $lines = $client->lines();
        $recordBet = $client->minBet();
        $betCents = (int) round($recordBet * $factor);
        if ($bets === [] || $betCents < 1) {
            $this->error('The demo start names no line bets: '.mb_strimwidth(json_encode($settings) ?: '', 0, 300));

            return self::FAILURE;
        }
        $stakes = array_map(fn ($b) => round($b * $factor / 100, 2), $bets);
        $this->info("{$lobby['name']} — Booongo {$lobby['stringId']}: {$settings['cols']}×{$settings['rows']}, {$lines} lines, line bets ".implode(',', $bets)
            ." × {$factor} → stakes ".implode(',', $stakes)." (recorded at {$stakes[0]})");

        // 2. the client
        $multi = $this->option('all');
        $code = (string) (($multi ? null : $this->option('code')) ?: FetchNetEntGameCommand::codeFor((string) $lobby['name']));
        $existing = GameTemplate::query()->where('code', $code)->first();   // codes compare case-insensitively
        if ($existing && ($existing->bonus_config['booongo']['game_key'] ?? null) !== $lobby['stringId']) {
            $this->error("Template code {$code} is taken by #{$existing->id} {$existing->code} ({$existing->title}) — pass another --code.");

            return self::FAILURE;
        }
        $title = trim((string) (($multi ? null : $this->option('title')) ?: $lobby['name']));
        $dir = storage_path("app/game-fetch/{$code}");
        $manifest = "{$dir}/.booongo-game.json";
        if ($this->option('reuse-mirror') && is_file($manifest)) {
            $m = (array) json_decode((string) file_get_contents($manifest), true);
            $this->info("Reusing the mirror at {$dir}");
        } else {
            $this->info("Recording and mirroring {$lobby['stringId']}");
            $staging = storage_path('app/game-fetch/_incoming-'.getmypid());
            try {
                $m = $mirror->mirror(fn () => $betboy->demo((string) $lobby['stringId'])['page'], $staging, fn (string $msg) => $this->output->write("\r  <fg=gray>·</> {$msg}   "));
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
            ['runner', $m['runner']],
            ['languages', implode(',', $m['languages'])],
            ['files', sprintf('%d (%.1f MB)', $m['files'], $m['bytes'] / 1048576)],
            ['mirrored to', $dir],
        ]);
        if ($m['missing'] !== []) {
            $this->warn(count($m['missing']).' recorded file(s) the host did not serve: '.implode(', ', array_slice($m['missing'], 0, 10)));
        }

        // 3. template — the maths is the outcome library; the columns just describe the grid
        $currency = Currency::tryFrom(strtoupper((string) $this->option('currency'))) ?? Currency::EUR;
        $template = GameTemplate::updateOrCreate(['code' => $code], array_filter([
            'title' => $title,
            'engine' => GameEngine::Internal,
            'device' => 'both',
            'bank_type' => BankType::Slots,
            'client_protocol' => ClientProtocol::Booongo,
            'pricing_currency' => $currency,
            'default_denomination' => 1,
            'default_bet_options' => $stakes,
            'reel_count' => (int) ($settings['cols'] ?? 5), 'row_count' => (int) ($settings['rows'] ?? 3), 'min_match' => 3,
            'symbols' => [], 'symbol_count' => 0, 'paytable' => [], 'reel_strips' => [], 'paylines' => null,
            'has_free_spins' => true, 'has_gamble' => false,
            'bonus_config' => ['booongo' => [
                'game_key' => $lobby['stringId'], 'name' => $lobby['name'], 'folder' => $m['folder'],
                'lines' => $lines, 'bet_factor' => $factor, 'record_bet' => $recordBet, 'bet_cents' => $betCents, 'bets' => $bets,
                'languages' => $m['languages'], 'settings' => $settings, 'context' => (array) $start['context'],
            ]],
            'poster_path' => $this->poster($betboy, $lobby, $code),
            'is_active' => true,
        ], fn ($v) => $v !== null));
        $this->info(($template->wasRecentlyCreated ? 'Created' : 'Updated')." template #{$template->id} {$code} — {$title}");

        // 4. bundle
        $zip = $this->zip($dir, $code);
        $bundle = $bundles->store($template, new UploadedFile($zip, basename($zip), 'application/zip', null, true),
            entry: $m['entry'], notes: Str::limit("Booongo client mirror (booongo:fetch) of {$lobby['stringId']}", 250));
        File::delete($zip);
        $this->info("Bundle v{$bundle->version} active — {$bundle->file_count} files, entry {$bundle->entry}");

        // 5. the outcome library
        $key = (string) $lobby['stringId'];
        $stats = $library->stats($key);
        $short = (int) $this->option('rounds') - $stats['rounds'];
        if ($short > 0) {   // empty, or an earlier import stopped part-way
            $this->line("  recording {$short} demo rounds for the outcome library…");
            $this->call('booongo:sample', ['game' => $key, '--rounds' => $short, '--betboy-token' => $this->option('betboy-token')]);
            $library->forget($key);
            $stats = $library->stats($key);
        }
        $this->info(sprintf('Outcome library: %d rounds (%d with features), return %.2f%%, top win %d× the stake',
            $stats['rounds'], $stats['features'], $stats['rtp'], intdiv($stats['max_cents'], $betCents)));
        if ($stats['rounds'] < 20000) {
            $this->warn("  a small library repeats rounds and misses rare big wins — grow it with: php artisan booongo:sample {$key} --rounds=50000");
        }

        // 6. games
        $category = Category::where('external_ref', 'betboy:booongo')->first()
            ?? Category::firstOrCreate(['slug' => 'booongo'], ['title' => 'Booongo', 'position' => 8]);
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
