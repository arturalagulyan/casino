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
use App\Services\GamePlay\BundleManager;
use App\Services\GamePlay\Microgaming\MicrogamingFamily;
use App\Services\GamePlay\Microgaming\MicrogamingLibrary;
use App\Services\GamePlay\Microgaming\MicrogamingLiveClient;
use App\Services\GamePlay\Microgaming\MicrogamingMirror;
use App\Services\GamePlay\NetEnt\BetboySite;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import a Microgaming slot from the Betboy lobby, the way apollo:fetch
 * imports Apollo ones:
 *
 *  1. launch it there as a DEMO session (fun credits) and capture the game's
 *     setup — its Refresh reply (chips, coin cost), the demo account, the
 *     Titan shell's config
 *  2. mirror the client off the static host ({@see MicrogamingMirror}) —
 *     `storage/app/game-fetch/<Code>/index.html`, `commonStatic/…`, `<game>/…`
 *  3. create/refresh the GameTemplate (client protocol Microgaming) and
 *     upload the mirror as its active bundle
 *  4. record the outcome library — whole demo rounds (`microgaming:sample`) —
 *     up to --rounds
 *  5. create/refresh a Game in each shop, in the Microgaming category, with
 *     the lobby's tile as its poster
 *
 * So far the "TheForce" client family only ({@see MicrogamingFamily}); other
 * games are refused.
 *
 *   php artisan microgaming:fetch thunderstruckii_mg_html
 *   php artisan microgaming:fetch "Thunderstruck II" --shop=4 --rounds=20000
 *   php artisan microgaming:fetch --all
 */
class FetchMicrogamingGameCommand extends Command
{
    use ImportsBetboyGames;

    protected $signature = 'microgaming:fetch
        {game? : Betboy game id or name (thunderstruckii_mg_html, "Thunderstruck II")}
        {--all : Every Microgaming slot in the Betboy lobby}
        {--code= : Template code (default: the name in StudlyCase, e.g. ThunderstruckIi)}
        {--title= : Display title (default: the lobby\'s name)}
        {--shop=* : Shop id or name to add the game to (repeatable; default: every shop)}
        {--currency=EUR : Pricing currency of the stakes}
        {--rtp=90 : Per-shop RTP %}
        {--max-win=2000 : Per-shop max win (× bet)}
        {--category=* : Tag (by name, created if missing) for the game — the provider category is always "Microgaming"}
        {--poster= : Poster image URL (default: the Betboy lobby tile)}
        {--rounds=3000 : Outcome library size to record up to}
        {--reuse-mirror : Use an earlier mirror instead of downloading the client again}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Import a Microgaming slot from the Betboy lobby: mirror its client, capture its setup, record its outcome library';

    public function handle(BetboySite $betboy, MicrogamingMirror $mirror, BundleManager $bundles, MicrogamingLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        if ($this->shops() === null) {
            return self::FAILURE;
        }
        if ($this->option('all')) {
            $failed = [];
            foreach ($betboy->games() as $g) {
                if (($g['providerName'] ?? '') !== 'Microgaming') {
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

    private function import(BetboySite $betboy, MicrogamingMirror $mirror, BundleManager $bundles, MicrogamingLibrary $library, string $key): int
    {
        $shops = $this->shops() ?? [];

        // 1. the game: played once in a browser (the mirror's capture) and its setup read on that demo session
        try {
            $lobby = $betboy->game($key);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (($lobby['providerName'] ?? '') !== 'Microgaming') {
            $this->warn("  Betboy lists '{$lobby['name']}' under {$lobby['providerName']}, not Microgaming — trying anyway");
        }
        $multi = $this->option('all');
        $code = (string) (($multi ? null : $this->option('code')) ?: FetchNetEntGameCommand::codeFor((string) $lobby['name']));
        // codes compare case-insensitively: never take over another game's template
        $existing = GameTemplate::query()->where('code', $code)->first();
        if ($existing && ($existing->bonus_config['microgaming']['game_key'] ?? null) !== $lobby['stringId']) {
            $this->error("Template code {$code} is taken by #{$existing->id} {$existing->code} ({$existing->title}) — pass another --code.");

            return self::FAILURE;
        }
        $title = trim((string) (($multi ? null : $this->option('title')) ?: $lobby['name']));
        $dir = storage_path("app/game-fetch/{$code}");
        $manifest = "{$dir}/.microgaming-game.json";
        try {
            $launch = $betboy->launchUrl((string) $lobby['stringId']);
            if ($this->option('reuse-mirror') && is_file($manifest)) {
                $m = (array) json_decode((string) file_get_contents($manifest), true);
                $this->info("Reusing the mirror at {$dir}");
            } else {
                $this->info('Mirroring '.MicrogamingMirror::HOST.'… (the game played once in a browser)');
                $staging = storage_path('app/game-fetch/_incoming-'.getmypid());
                try {
                    $m = $mirror->mirror($launch, $staging, fn (string $msg) => $this->output->write("\r  <fg=gray>·</> {$msg}   "));
                    $this->newLine();
                } catch (RuntimeException $e) {
                    File::deleteDirectory($staging);
                    throw $e;
                }
                foreach (array_slice($m['errors'], 0, 5) as $err) {
                    $this->warn("  page error on Betboy: {$err}");
                }
                File::deleteDirectory($dir);
                File::moveDirectory($staging, $dir);
                File::put($manifest, json_encode($m, JSON_UNESCAPED_SLASHES));
            }
            // the recorder speaks the AdvSlot packets (<Request verbex='…' …/>): a client sending
            // anything else (Quantum JSON in CDATA, …) would be served replies it can't read
            $packet = (string) ($m['client_packet'] ?? '');
            $quantum = str_contains($packet, '"QuantumRequest"');
            if ($packet === '' || (! $quantum && (str_contains($packet, 'CDATA') || ! preg_match("/<Request verbex=['\"]Refresh['\"]\s*\/>/", $packet)))) {
                throw new RuntimeException('Unsupported Microgaming packet format'.($packet === '' ? ' (the client sent no game packet in the browser)' : ': '.mb_strimwidth($packet, 0, 200)));
            }
            $client = MicrogamingLiveClient::fromHtml((string) $m['html'], $launch);
            // the version the client itself stamps on <Pkt> (Dream Date: 6.1 — a probe stops at 5,
            // which answers the Refresh but not a Spin)
            if (! $quantum && preg_match('/<Pkt\s+version=\\\\?[\'"]([\d.]+)/', $packet, $v)) {
                $client->packetVersion = $v[1];
            }
            $init = $client->open();   // refuses anything but a demo session
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $chips = MicrogamingFamily::chips($init);
        if ($chips === []) {
            $this->error('The Refresh reply names no chips: '.mb_strimwidth($init, 0, 300));

            return self::FAILURE;
        }
        $stakes = array_map(fn ($c) => MicrogamingFamily::stake($init, $c, 1) / 100, $chips);
        $rows = (int) (MicrogamingLiveClient::attr($init, 'VisArea', 'numRows') ?? 3);
        $reels = (int) (MicrogamingLiveClient::attr($init, 'VisArea', 'numCols') ?? 5);
        $this->info("{$lobby['name']} — Microgaming ".($client->info['gameVersion'] ?? $client->info['engine'] ?? '?')." (module {$client->info['moduleId']}, packets v{$client->packetVersion}): {$reels}×{$rows}, chips "
            .implode(',', $chips).'¢ × '.MicrogamingFamily::stake($init, 1, 1).' coins, stakes '.implode(',', $stakes)." (recorded at {$stakes[0]})");
        $this->table([], [
            ['entry', $m['entry']],
            ['languages', implode(',', $m['languages'])],
            ['files', sprintf('%d (%.1f MB)', $m['files'], $m['bytes'] / 1048576)],
            ['mirrored to', $dir],
        ]);

        // 3. template — the maths is the outcome library; the columns just describe the grid
        $currency = Currency::tryFrom(strtoupper((string) $this->option('currency'))) ?? Currency::EUR;
        $account = $client->account;   // whole: the classic shell builds its login result from regulated / responsibleGaming / personal
        // extra Spin attributes a game needs (Dream Date: reelSetChoiceId — set by hand, kept on a refetch)
        $kept = GameTemplate::query()->where('code', $code)->value('bonus_config')['microgaming'] ?? [];
        $spinExtra = $kept['spin_extra'] ?? null;
        $template = GameTemplate::updateOrCreate(['code' => $code], array_filter([
            'title' => $title,
            'engine' => GameEngine::Internal,
            'device' => 'both',
            'bank_type' => BankType::Slots,
            'client_protocol' => ClientProtocol::Microgaming,
            'pricing_currency' => $currency,
            'default_denomination' => 1,
            'default_bet_options' => $stakes,
            'reel_count' => $reels ?: 5, 'row_count' => $rows ?: 3, 'min_match' => 3,
            'symbols' => [], 'symbol_count' => 0, 'paytable' => [], 'reel_strips' => [], 'paylines' => null,
            'has_free_spins' => true, 'has_gamble' => false,
            'bonus_config' => ['microgaming' => [
                'game_key' => $lobby['stringId'], 'name' => $lobby['name'], 'folder' => $m['folder'],
                'version' => $client->info['gameVersion'] ?? null, 'engine' => $client->info['engine'] ?? null,
                'module_id' => $client->info['moduleId'], 'client_id' => $client->info['clientId'], 'packet_version' => $client->packetVersion,
                'record_chip' => $chips[0], 'chips' => $chips, 'languages' => $m['languages'],
                'init' => $init, 'account' => $account, 'titan_config' => $m['titan_config'], 'session' => $m['session'] ?? [],
            ] + array_filter(['spin_extra' => $spinExtra, 'feature_choices' => $kept['feature_choices'] ?? null])],
            'poster_path' => $this->poster($betboy, $lobby, $code),
            'is_active' => true,
        ], fn ($v) => $v !== null));
        $this->info(($template->wasRecentlyCreated ? 'Created' : 'Updated')." template #{$template->id} {$code} — {$title}");

        // 4. bundle
        $zip = $this->zip($dir, $code);
        $bundle = $bundles->store($template, new UploadedFile($zip, basename($zip), 'application/zip', null, true),
            entry: $m['entry'], notes: Str::limit("Microgaming client mirror (microgaming:fetch) of {$m['folder']}", 250));
        File::delete($zip);
        $this->info("Bundle v{$bundle->version} active — {$bundle->file_count} files, entry {$bundle->entry}");

        // 5. the outcome library
        $key = (string) $lobby['stringId'];
        $stats = $library->stats($key);
        $short = (int) $this->option('rounds') - $stats['rounds'];
        if ($short > 0) {   // empty, or an earlier import stopped part-way
            $this->line("  recording {$short} demo rounds for the outcome library…");
            $this->call('microgaming:sample', ['game' => $key, '--rounds' => $short, '--betboy-token' => $this->option('betboy-token')]);
            $library->forget($key);
            $stats = $library->stats($key);
        }
        $this->info(sprintf('Outcome library: %d rounds (%d with features), return %.2f%%, top win %d× the stake',
            $stats['rounds'], $stats['features'], $stats['rtp'], intdiv($stats['max_cents'], max(1, (int) round($stakes[0] * 100)))));
        if ($stats['rounds'] < 20000) {
            $this->warn("  a small library repeats rounds and misses rare big wins — grow it with: php artisan microgaming:sample {$key} --rounds=50000");
        }

        // 6. games
        $category = Category::where('external_ref', 'betboy:microgaming')->first()
            ?? Category::firstOrCreate(['slug' => 'microgaming'], ['title' => 'Microgaming', 'position' => 3]);
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
