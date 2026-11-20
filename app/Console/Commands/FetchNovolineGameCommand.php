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
use App\Models\NovolineSetup;
use App\Models\Tag;
use App\Services\GamePlay\BundleManager;
use App\Services\GamePlay\NetEnt\BetboySite;
use App\Services\GamePlay\Novoline\NovolineLibrary;
use App\Services\GamePlay\Novoline\NovolineLiveClient;
use App\Services\GamePlay\Novoline\NovolineMirror;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Import a Novoline (Greentube) slot from the Betboy lobby, the way igt:fetch
 * imports IGT ones:
 *
 *  1. launch it there as a DEMO session (fun credits) and capture the login
 *     reply (init state, config); one demo round measures a spin's cost
 *  2. mirror the HTML5 client off its static host ({@see NovolineMirror}) —
 *     `storage/app/game-fetch/<Code>/index.html`, `<game>/…`, `<game>Mobile/…`
 *  3. create/refresh the GameTemplate (client protocol Novoline) and upload
 *     the mirror as its active bundle
 *  4. record the outcome library — whole demo rounds (`novoline:sample`)
 *  5. create/refresh a Game in each shop, in the Novoline category, with the
 *     lobby's tile as its poster
 *
 *   php artisan novoline:fetch bookofradeluxe_gt_html
 *   php artisan novoline:fetch --all --rounds=3000
 */
class FetchNovolineGameCommand extends Command
{
    use ImportsBetboyGames;

    protected $signature = 'novoline:fetch
        {game? : Betboy game id or name (bookofradeluxe_gt_html, "Book of Ra Deluxe")}
        {--all : Every Novoline game in the Betboy lobby}
        {--skip=* : With --all: Betboy ids to leave out}
        {--code= : Template code (default: the name in StudlyCase, e.g. BookOfRaDeluxe)}
        {--title= : Display title (default: the lobby\'s name)}
        {--shop=* : Shop id or name to add the game to (repeatable; default: every shop)}
        {--currency=EUR : Pricing currency of the stakes}
        {--rtp=90 : Per-shop RTP %}
        {--max-win=2000 : Per-shop max win (× bet)}
        {--category=* : Tag (by name, created if missing) for the game — the provider category is always "Novoline"}
        {--poster= : Poster image URL (default: the Betboy lobby tile)}
        {--rounds=3000 : Outcome library size to record up to}
        {--reuse-mirror : Use an earlier mirror instead of recording the client again}
        {--top-up : With --reuse-mirror: first fetch what the manifests name that the mirror lacks}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Import a Novoline slot from the Betboy lobby: mirror its client, capture its setup, record its outcome library';

    public function handle(BetboySite $betboy, NovolineMirror $mirror, BundleManager $bundles, NovolineLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        if ($this->shops() === null) {
            return self::FAILURE;
        }
        if ($this->option('all')) {
            $failed = [];
            $skip = (array) $this->option('skip');
            foreach ($betboy->games() as $g) {
                if (($g['providerName'] ?? '') !== 'Novoline' || in_array($g['stringId'], $skip, true)) {
                    continue;
                }
                $this->newLine();
                $this->info("━━ {$g['name']} ({$g['stringId']})");
                try {
                    $ok = $this->import($betboy, $mirror, $bundles, $library, (string) $g['stringId']) === self::SUCCESS;
                } catch (Throwable $e) {
                    $this->error(mb_strimwidth($e->getMessage(), 0, 300, '…'));
                    $ok = false;
                }
                if (! $ok) {
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

    private function import(BetboySite $betboy, NovolineMirror $mirror, BundleManager $bundles, NovolineLibrary $library, string $key): int
    {
        $shops = $this->shops() ?? [];

        // 1. the game, and its login reply from a demo session
        try {
            $lobby = $betboy->game($key);
            $key = (string) $lobby['stringId'];
            $client = NovolineSampleCommand::connect($betboy, $key);
            $login = $client->login;
            $init = $client->state;
            $factor = NovolineSampleCommand::factor($login);
            [$lines, , $x] = NovolineSampleCommand::bet($init);
            $lineBets = NovolineSampleCommand::lineBets($init);
            $perLine = $lineBets[0] ?? 1;
            $round = $client->round();   // what a spin costs there: measured
            $client->close();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (($lobby['providerName'] ?? '') !== 'Novoline') {
            $this->warn("  Betboy lists '{$lobby['name']}' under {$lobby['providerName']}, not Novoline — trying anyway");
        }
        $betCents = (int) round($round['bet'] * 100 / $factor);
        if ($betCents < 1) {
            $this->error('Could not measure what a spin costs.');

            return self::FAILURE;
        }
        $stakes = array_map(fn (int $b) => round($b / $perLine * $betCents / 100, 2), $lineBets ?: [$perLine]);
        $g = NovolineLiveClient::field($init, ':g') ?? ['0'];
        $gambleMax = is_numeric($g[0] ?? null) ? (int) $g[0] : 0;
        $this->info("{$lobby['name']} — Greentube {$key}: {$lines} lines, line bet {$perLine} of ".implode(',', $lineBets ?: [$perLine])
            ." costs {$betCents}¢, stakes ".implode(',', $stakes).($gambleMax > 0 ? ", gamble up to {$gambleMax}×" : ', no gamble'));

        // 2. the client
        $multi = $this->option('all');
        $code = (string) (($multi ? null : $this->option('code')) ?: FetchNetEntGameCommand::codeFor((string) $lobby['name']));
        $existing = GameTemplate::query()->where('code', $code)->first();   // codes compare case-insensitively
        if ($existing && ($existing->bonus_config['novoline']['game_key'] ?? null) !== $key) {
            $this->error("Template code {$code} is taken by #{$existing->id} {$existing->code} ({$existing->title}) — pass another --code.");

            return self::FAILURE;
        }
        $title = (string) (($multi ? null : $this->option('title')) ?: trim((string) $lobby['name']));
        $dir = storage_path("app/game-fetch/{$code}");
        $manifest = "{$dir}/.novoline-game.json";
        if ($this->option('reuse-mirror') && is_file($manifest)) {
            $m = (array) json_decode((string) file_get_contents($manifest), true);
            $this->info("Reusing the mirror at {$dir}");
            if ($this->option('top-up')) {
                $added = $mirror->topUp($dir, (string) $m['folder']);
                $files = File::allFiles($dir, true);
                $m = array_merge($m, ['files' => count($files), 'bytes' => array_sum(array_map(fn ($f) => $f->getSize(), $files))]);
                File::put($manifest, json_encode($m, JSON_UNESCAPED_SLASHES));
                $this->info("  topped up: {$added} file(s) added");
            }
        } else {
            $page = $betboy->demo($key)['page'];
            $this->info("Recording and mirroring {$page}");
            $staging = storage_path('app/game-fetch/_incoming-'.getmypid());
            try {
                $html = Http::withUserAgent('Mozilla/5.0')->timeout(30)->get($page)->body();
                $m = $mirror->mirror($page, $html, $key, $staging, fn (string $msg) => $this->output->write("\r  <fg=gray>·</> {$msg}   "));
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
            ['files', sprintf('%d (%.1f MB)', $m['files'], $m['bytes'] / 1048576)],
            ['mirrored to', $dir],
        ]);
        if ($m['missing'] !== []) {
            $this->warn(count($m['missing']).' recorded file(s) the host did not serve: '.implode(', ', array_slice($m['missing'], 0, 10)));
        }

        // 3. template
        $currency = Currency::tryFrom(strtoupper((string) $this->option('currency'))) ?? Currency::EUR;
        $template = GameTemplate::updateOrCreate(['code' => $code], array_filter([
            'title' => $title,
            'engine' => GameEngine::Internal,
            'device' => 'both',
            'bank_type' => BankType::Slots,
            'client_protocol' => ClientProtocol::Novoline,
            'pricing_currency' => $currency,
            'default_denomination' => 1,
            'default_bet_options' => $stakes,
            'reel_count' => 5, 'row_count' => 3, 'min_match' => 3,
            'symbols' => [], 'symbol_count' => 0, 'paytable' => [], 'reel_strips' => [], 'paylines' => null,
            'has_free_spins' => true, 'has_gamble' => $gambleMax > 0,
            'bonus_config' => ['novoline' => [
                'game_key' => $key, 'name' => trim((string) $lobby['name']), 'folder' => $m['folder'],
                'lines' => $lines, 'per_line' => $perLine, 'x' => $x, 'bet_cents' => $betCents, 'bet_steps' => $lineBets ?: [$perLine],
                'factor' => $factor, 'gamble' => $gambleMax > 0, 'gamble_max' => min(10, $gambleMax),
            ]],   // the login reply: novoline_setups (too wide for this table)
            'poster_path' => $this->poster($betboy, $lobby, $code),
            'is_active' => true,
        ], fn ($v) => $v !== null));
        NovolineSetup::updateOrCreate(['game_key' => $key], ['login' => $login]);
        $this->info(($template->wasRecentlyCreated ? 'Created' : 'Updated')." template #{$template->id} {$code} — {$title}");

        // 4. bundle
        $zip = $this->zip($dir, $code);
        $bundle = $bundles->store($template, new UploadedFile($zip, basename($zip), 'application/zip', null, true),
            entry: $m['entry'], notes: Str::limit("Novoline client mirror (novoline:fetch) of {$key}", 250));
        File::delete($zip);
        $this->info("Bundle v{$bundle->version} active — {$bundle->file_count} files, entry {$bundle->entry}");

        // 5. the outcome library
        $stats = $library->stats($key);
        $short = (int) $this->option('rounds') - $stats['rounds'];
        if ($short > 0) {
            $this->line("  recording {$short} demo rounds for the outcome library…");
            $this->call('novoline:sample', ['game' => $key, '--rounds' => $short, '--betboy-token' => $this->option('betboy-token')]);
            $library->forget($key);
            $stats = $library->stats($key);
        }
        $this->info(sprintf('Outcome library: %d rounds (%d with features), return %.2f%%, top win %d× the stake',
            $stats['rounds'], $stats['features'], $stats['rtp'], intdiv($stats['max_cents'], $betCents)));
        if ($stats['rounds'] < 20000) {
            $this->warn("  a small library repeats rounds and misses rare big wins — grow it with: php artisan novoline:sample {$key} --rounds=50000");
        }

        // 6. games
        $category = Category::where('external_ref', 'betboy:novoline')->first()
            ?? Category::firstOrCreate(['slug' => 'novoline'], ['title' => 'Novoline', 'position' => 4]);
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
