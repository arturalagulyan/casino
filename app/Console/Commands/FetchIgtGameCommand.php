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
use App\Models\IgtSetup;
use App\Models\Tag;
use App\Services\GamePlay\BundleManager;
use App\Services\GamePlay\Igt\IgtLibrary;
use App\Services\GamePlay\Igt\IgtLiveClient;
use App\Services\GamePlay\Igt\IgtMirror;
use App\Services\GamePlay\NetEnt\BetboySite;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import an IGT slot from the Betboy lobby, the way apollo:fetch imports
 * Apollo ones:
 *
 *  1. launch it there as a DEMO session (fun credits) and capture IGT's
 *     paytable, init and money format; one demo round measures a spin's cost
 *  2. mirror the HTML5 client off its static host ({@see IgtMirror}, from a
 *     browser recording) — `storage/app/game-fetch/<Code>/commonStatic/…`, `<game>/…`
 *  3. create/refresh the GameTemplate (client protocol Igt) and upload the
 *     mirror as its active bundle
 *  4. record the outcome library — whole demo rounds (`igt:sample`) — up to --rounds
 *  5. create/refresh a Game in each shop, in the IGT category, with the
 *     lobby's tile as its poster
 *
 *   php artisan igt:fetch wolfrun_igt_html
 *   php artisan igt:fetch --all --rounds=3000
 */
class FetchIgtGameCommand extends Command
{
    use ImportsBetboyGames;

    protected $signature = 'igt:fetch
        {game? : Betboy game id or name (wolfrun_igt_html, "Wolf Run")}
        {--all : Every IGT game in the Betboy lobby}
        {--code= : Template code (default: the name in StudlyCase, e.g. WolfRun)}
        {--title= : Display title (default: the lobby\'s name)}
        {--shop=* : Shop id or name to add the game to (repeatable; default: every shop)}
        {--currency=EUR : Pricing currency of the stakes}
        {--rtp=90 : Per-shop RTP %}
        {--max-win=2000 : Per-shop max win (× bet)}
        {--category=* : Tag (by name, created if missing) for the game — the provider category is always "IGT"}
        {--poster= : Poster image URL (default: the Betboy lobby tile)}
        {--rounds=3000 : Outcome library size to record up to}
        {--reuse-mirror : Use an earlier mirror instead of recording the client again}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Import an IGT slot from the Betboy lobby: mirror its client, capture its setup, record its outcome library';

    public function handle(BetboySite $betboy, IgtMirror $mirror, BundleManager $bundles, IgtLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        if ($this->shops() === null) {
            return self::FAILURE;
        }
        if ($this->option('all')) {
            $failed = [];
            foreach ($betboy->games() as $g) {
                if (($g['providerName'] ?? '') !== 'IGT') {
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

    private function import(BetboySite $betboy, IgtMirror $mirror, BundleManager $bundles, IgtLibrary $library, string $key): int
    {
        $shops = $this->shops() ?? [];

        // 1. the game, and IGT's own setup from a demo session
        try {
            $lobby = $betboy->game($key);
            $page = $betboy->demo((string) $lobby['stringId'])['page'];
            $client = IgtLiveClient::fromPage($page);
            $client->open();
            $patterns = $client->patterns();
            $paytable = $client->paytable;
            $init = $client->init;
            $currencyFormat = $client->currency;
            $step = $client->minStep();   // rounds are recorded at the smallest line bet
            $bet = 0;
            for ($i = 0; $i < 20 && $bet < 1; $i++) {   // what a spin costs there, cents: measured
                $bet = $client->round($step)['bet'];
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (($lobby['providerName'] ?? '') !== 'IGT') {
            $this->warn("  Betboy lists '{$lobby['name']}' under {$lobby['providerName']}, not IGT — trying anyway");
        }
        if ($bet < 1) {
            $this->error('Could not measure what a spin costs.');

            return self::FAILURE;
        }
        $steps = array_values(array_map('strval', (array) ($paytable['PatternSliderInfo']['BetInfo']['Step'] ?? ['1'])));
        $stakes = array_map(fn (string $k) => round((float) $k / $step * $bet / 100, 2), $steps);
        $reels = (array) collect((array) ($init['PopulationOutcome'] ?? []))->firstWhere('@name', 'BaseGame.Reels');
        $reelCount = count((array) ($reels['Entry'] ?? []));
        $rowCount = count((array) ($reels['Entry'][0]['Cell'] ?? []));
        $stats = (array) ($paytable['PaytableStatistics'] ?? []);
        $this->info("{$lobby['name']} — IGT {$lobby['stringId']} ({$stats['@description']}): {$patterns} lines, denomination {$client->denom}, line bet {$step} costs {$bet}¢, stakes "
            .implode(',', $stakes).", IGT RTP {$stats['@minRTP']}–{$stats['@maxRTP']}%");

        // 2. the client
        $multi = $this->option('all');
        $code = (string) (($multi ? null : $this->option('code')) ?: FetchNetEntGameCommand::codeFor((string) $lobby['name']));
        $existing = GameTemplate::query()->where('code', $code)->first();   // codes compare case-insensitively
        if ($existing && ($existing->bonus_config['igt']['game_key'] ?? null) !== $lobby['stringId']) {
            $this->error("Template code {$code} is taken by #{$existing->id} {$existing->code} ({$existing->title}) — pass another --code.");

            return self::FAILURE;
        }
        $title = (string) (($multi ? null : $this->option('title')) ?: $lobby['name']);
        $dir = storage_path("app/game-fetch/{$code}");
        $manifest = "{$dir}/.igt-game.json";
        if ($this->option('reuse-mirror') && is_file($manifest)) {
            $m = (array) json_decode((string) file_get_contents($manifest), true);
            $this->info("Reusing the mirror at {$dir}");
        } else {
            $this->info("Recording and mirroring {$page}");
            $staging = storage_path('app/game-fetch/_incoming-'.getmypid());
            try {
                $m = $mirror->mirror($page, $staging, fn (string $msg) => $this->output->write("\r  <fg=gray>·</> {$msg}   "));
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
            'client_protocol' => ClientProtocol::Igt,
            'pricing_currency' => $currency,
            'default_denomination' => 1,
            'default_bet_options' => $stakes,
            'reel_count' => $reelCount ?: 5, 'row_count' => $rowCount ?: 3, 'min_match' => 3,
            'symbols' => [], 'symbol_count' => 0, 'paytable' => [], 'reel_strips' => [], 'paylines' => null,
            'has_free_spins' => true, 'has_gamble' => false,
            'bonus_config' => ['igt' => [
                'game_key' => $lobby['stringId'], 'name' => $lobby['name'], 'folder' => $m['folder'],
                'patterns' => $patterns, 'denom' => $client->denom, 'record_step' => $step, 'bet_cents' => $bet, 'bet_steps' => $steps,
                'languages' => $m['languages'],
            ]],   // the paytable / init / money format: igt_setups (too wide for this table)
            'poster_path' => $this->poster($betboy, $lobby, $code),
            'is_active' => true,
        ], fn ($v) => $v !== null));
        IgtSetup::updateOrCreate(['game_key' => $lobby['stringId']], ['paytable' => $paytable, 'init' => $init, 'currency' => $currencyFormat]);
        $this->info(($template->wasRecentlyCreated ? 'Created' : 'Updated')." template #{$template->id} {$code} — {$title}");

        // 4. bundle
        $zip = $this->zip($dir, $code);
        $bundle = $bundles->store($template, new UploadedFile($zip, basename($zip), 'application/zip', null, true),
            entry: $m['entry'], notes: Str::limit("IGT client mirror (igt:fetch) of {$lobby['stringId']}", 250));
        File::delete($zip);
        $this->info("Bundle v{$bundle->version} active — {$bundle->file_count} files, entry {$bundle->entry}");

        // 5. the outcome library
        $key = (string) $lobby['stringId'];
        $stats = $library->stats($key);
        $short = (int) $this->option('rounds') - $stats['rounds'];
        if ($short > 0) {
            $this->line("  recording {$short} demo rounds for the outcome library…");
            $this->call('igt:sample', ['game' => $key, '--rounds' => $short, '--betboy-token' => $this->option('betboy-token')]);
            $library->forget($key);
            $stats = $library->stats($key);
        }
        $this->info(sprintf('Outcome library: %d rounds (%d with features), return %.2f%%, top win %d× the stake',
            $stats['rounds'], $stats['features'], $stats['rtp'], intdiv($stats['max_cents'], $bet)));
        if ($stats['rounds'] < 20000) {
            $this->warn("  a small library repeats rounds and misses rare big wins — grow it with: php artisan igt:sample {$key} --rounds=50000");
        }

        // 6. games
        $category = Category::where('external_ref', 'betboy:igt')->first()
            ?? Category::firstOrCreate(['slug' => 'igt'], ['title' => 'IGT', 'position' => 6]);
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
