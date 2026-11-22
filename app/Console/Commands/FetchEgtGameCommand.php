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
use App\Services\GamePlay\Egt\EgtLibrary;
use App\Services\GamePlay\Egt\EgtLiveClient;
use App\Services\GamePlay\Egt\EgtMirror;
use App\Services\GamePlay\NetEnt\BetboySite;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import an EGT (Amusnet) slot from the Betboy lobby, the way apollo:fetch
 * imports Apollo ones:
 *
 *  1. launch it there as a DEMO session (fun credits) and capture EGT's
 *     login / settings / subscribe — bets, paytable, game number (gin)
 *  2. mirror the HTML5 client off its static host ({@see EgtMirror}) —
 *     `storage/app/game-fetch/<Code>/index.html`, `lib/…`, `Mobile/games/<Engine>/assets/<gin>/…`
 *  3. create/refresh the GameTemplate (client protocol Egt) and upload the
 *     mirror as its active bundle
 *  4. record the outcome library — whole demo rounds (`egt:sample`) — up to --rounds
 *  5. create/refresh a Game in each shop, in the EGT category, with the
 *     lobby's tile as its poster
 *
 *   php artisan egt:fetch burning_hot_html
 *   php artisan egt:fetch "Burning Hot" --shop=4 --rounds=20000
 *   php artisan egt:fetch --all
 *   php artisan egt:fetch --all --reuse-init --reuse-mirror --rounds=0   # rebuild, no game launches
 */
class FetchEgtGameCommand extends Command
{
    use ImportsBetboyGames;

    protected $signature = 'egt:fetch
        {game? : Betboy game id or name (burning_hot_html, "Burning Hot")}
        {--all : Every EGT game in the Betboy lobby}
        {--code= : Template code (default: the name in StudlyCase, e.g. BurningHot)}
        {--title= : Display title (default: the lobby\'s name)}
        {--shop=* : Shop id or name to add the game to (repeatable; default: every shop)}
        {--currency=EUR : Pricing currency of the bets}
        {--rtp=90 : Per-shop RTP %}
        {--max-win=2000 : Per-shop max win (× bet)}
        {--category=* : Tag (by name, created if missing) for the game — the provider category is always "EGT"}
        {--poster= : Poster image URL (default: the Betboy lobby tile)}
        {--rounds=3000 : Outcome library size to record up to}
        {--reuse-mirror : Use an earlier mirror instead of downloading the client again}
        {--reuse-init : Take the setup captured by an earlier import instead of launching a demo (no Betboy launch)}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Import an EGT slot from the Betboy lobby: mirror its client, capture its setup, record its outcome library';

    public function handle(BetboySite $betboy, EgtMirror $mirror, BundleManager $bundles, EgtLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        if ($this->shops() === null) {
            return self::FAILURE;
        }
        if ($this->option('all')) {
            $failed = [];
            foreach ($betboy->games() as $g) {
                if (($g['providerName'] ?? '') !== 'EGT') {
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

    private function import(BetboySite $betboy, EgtMirror $mirror, BundleManager $bundles, EgtLibrary $library, string $key): int
    {
        $shops = $this->shops() ?? [];

        // 1. the game, and EGT's own setup from a demo session
        try {
            $lobby = $betboy->game($key);
            $earlier = $this->option('reuse-init') ? GameTemplate::query()->where('client_protocol', ClientProtocol::Egt)->get()
                ->first(fn (GameTemplate $t) => ($t->bonus_config['egt']['betboy_id'] ?? null) === $lobby['stringId']) : null;
            if ($earlier !== null) {
                $setup = (array) $earlier->bonus_config['egt'];
                $this->line("  setup from template #{$earlier->id} {$earlier->code}");
            } else {
                $client = EgtLiveClient::fromPage($betboy->demo((string) $lobby['stringId'])['page']);
                $client->open();   // refuses anything but a demo session
                $client->close();
                $setup = ['gin' => $client->gin(), 'login' => $client->login, 'settings' => $client->settings, 'subscribe' => $client->subscribe];
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (($lobby['providerName'] ?? '') !== 'EGT') {
            $this->warn("  Betboy lists '{$lobby['name']}' under {$lobby['providerName']}, not EGT — trying anyway");
        }
        $gin = (int) $setup['gin'];
        $bets = array_values(array_map('intval', (array) ($setup['settings']['complex']['bets'] ?? [])));
        sort($bets);
        if ($gin <= 0 || $bets === []) {
            $this->error('The demo settings name no game or bets: '.mb_strimwidth(json_encode($setup['settings'] ?? null) ?: '', 0, 300));

            return self::FAILURE;
        }
        $gameType = (string) (array_key_first((array) ($setup['login']['complex'] ?? [])) ?? '');
        $reels = count((array) ($setup['settings']['complex']['mainFakeReels'] ?? [])) ?: 5;
        $this->info("{$lobby['name']} — EGT gin {$gin} ({$gameType}): {$reels} reels, bets ".implode(',', $bets)
            ." (recorded at {$bets[0]}), EGT RTP ".($setup['settings']['complex']['rtp'] ?? '?').'%');

        // 2. the client
        $multi = $this->option('all');
        $code = (string) (($multi ? null : $this->option('code')) ?: FetchNetEntGameCommand::codeFor((string) $lobby['name']));
        // codes compare case-insensitively: never take over another game's template
        $existing = GameTemplate::query()->where('code', $code)->first();
        if ($existing && (int) ($existing->bonus_config['egt']['gin'] ?? 0) !== $gin) {
            $this->error("Template code {$code} is taken by #{$existing->id} {$existing->code} ({$existing->title}) — pass another --code.");

            return self::FAILURE;
        }
        $title = (string) (($multi ? null : $this->option('title')) ?: $lobby['name']);
        $dir = storage_path("app/game-fetch/{$code}");
        $manifest = "{$dir}/.egt-game.json";
        if ($this->option('reuse-mirror') && is_file($manifest)) {
            $m = (array) json_decode((string) file_get_contents($manifest), true);
            EgtMirror::patch($dir);
            $this->info("Reusing the mirror at {$dir}");
        } else {
            $this->info('Mirroring '.EgtMirror::HOST." (gin {$gin})");
            $staging = storage_path('app/game-fetch/_incoming-'.getmypid());
            try {
                $m = $mirror->mirror(fn () => $betboy->demo((string) $lobby['stringId'])['page'], $staging,
                    fn (string $msg) => $this->output->write("\r  <fg=gray>·</> {$msg}   "));
                $this->newLine();
            } catch (RuntimeException $e) {
                File::deleteDirectory($staging);
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            if ((int) $m['gin'] !== $gin) {
                File::deleteDirectory($staging);
                $this->error("The mirrored page is game {$m['gin']}, not {$gin}");

                return self::FAILURE;
            }
            File::deleteDirectory($dir);
            File::moveDirectory($staging, $dir);
            File::put($manifest, json_encode($m, JSON_UNESCAPED_SLASHES));
        }
        $this->table([], [
            ['entry', $m['entry']],
            ['engine', $m['engine']],
            ['languages', implode(',', $m['languages'])],
            ['files', sprintf('%d (%.1f MB)', $m['files'], $m['bytes'] / 1048576)],
            ['not served', count($m['missing']) ? implode(', ', array_slice($m['missing'], 0, 5)).(count($m['missing']) > 5 ? ' …' : '') : '—'],
            ['mirrored to', $dir],
        ]);

        // 3. template — the maths is the outcome library; the columns just describe the grid
        $currency = Currency::tryFrom(strtoupper((string) $this->option('currency'))) ?? Currency::EUR;
        $money = array_map(fn (int $b) => round($b / 100, 2), $bets);
        $strip = fn (array $r) => array_diff_key($r, array_flip(['sessionKey', 'messageId', 'eventTimestamp', 'playerIdToken']));
        $template = GameTemplate::updateOrCreate(['code' => $code], array_filter([
            'title' => $title,
            'engine' => GameEngine::Internal,
            'device' => 'both',
            'bank_type' => BankType::Slots,
            'client_protocol' => ClientProtocol::Egt,
            'pricing_currency' => $currency,
            'default_denomination' => 1,
            'default_bet_options' => $money,
            'reel_count' => $reels, 'row_count' => 3, 'min_match' => 3,
            'symbols' => [], 'symbol_count' => 0, 'paytable' => [], 'reel_strips' => [], 'paylines' => null,
            'has_free_spins' => true, 'has_gamble' => true,
            'bonus_config' => ['egt' => [
                'gin' => $gin, 'game_type' => $gameType, 'engine' => $m['engine'], 'betboy_id' => $lobby['stringId'], 'name' => $lobby['name'],
                'record_bet' => $bets[0], 'bets' => $bets, 'languages' => $m['languages'],
                'login' => $strip((array) $setup['login']), 'settings' => $strip((array) $setup['settings']), 'subscribe' => $strip((array) $setup['subscribe']),
            ]],
            'poster_path' => $this->poster($betboy, $lobby, $code),
            'is_active' => true,
        ], fn ($v) => $v !== null));
        $this->info(($template->wasRecentlyCreated ? 'Created' : 'Updated')." template #{$template->id} {$code} — {$title}");

        // 4. bundle
        $zip = $this->zip($dir, $code);
        $bundle = $bundles->store($template, new UploadedFile($zip, basename($zip), 'application/zip', null, true),
            entry: $m['entry'], notes: Str::limit("EGT client mirror (egt:fetch) of gin {$gin} ({$m['engine']})", 250));
        File::delete($zip);
        $this->info("Bundle v{$bundle->version} active — {$bundle->file_count} files, entry {$bundle->entry}");

        // 5. the outcome library
        $stats = $library->stats($gin);
        $short = (int) $this->option('rounds') - $stats['rounds'];
        if ($short > 0) {   // empty, or an earlier import stopped part-way
            $this->line("  recording {$short} demo rounds for the outcome library…");
            $this->call('egt:sample', ['game' => $lobby['stringId'], '--rounds' => $short,
                '--betboy-token' => $this->option('betboy-token')]);
            $library->forget($gin);
            $stats = $library->stats($gin);
        }
        $this->info(sprintf('Outcome library: %d rounds (%d with a feature), return %.2f%%, top win %d× the bet',
            $stats['rounds'], $stats['features'], $stats['rtp'], intdiv($stats['max_cents'], max(1, $bets[0]))));
        if ($stats['rounds'] < 20000) {
            $this->warn("  a small library repeats rounds and misses rare big wins — grow it with: php artisan egt:sample {$lobby['stringId']} --rounds=50000");
        }

        // 6. games
        $category = Category::where('external_ref', 'betboy:egt')->first()
            ?? Category::firstOrCreate(['slug' => 'egt'], ['title' => 'EGT', 'position' => 21]);
        foreach ((array) $this->option('category') as $name) {
            $template->tags()->syncWithoutDetaching([Tag::firstOrCreate(['slug' => Str::slug((string) $name)], ['name' => (string) $name, 'position' => 10])->id]);
        }
        foreach ($shops as $shop) {
            $row = Game::updateOrCreate(['shop_id' => $shop->id, 'template_id' => $template->id], [
                'title' => $title, 'bank_type' => BankType::Slots, 'rtp_percent' => (int) $this->option('rtp'),
                'max_win_multiplier' => (int) $this->option('max-win'), 'reserve_percent' => 4,
                'bet_options' => $money, 'denomination' => 1, 'pricing_currency' => $currency, 'is_visible' => true,
            ]);
            $row->categories()->syncWithoutDetaching([$category->id]);
            $this->line("  <fg=green>✓</> game #{$row->id} in shop {$shop->name}");
        }

        $this->newLine();
        $this->info('Done. Try it: '.url("/games/demo/{$code}"));

        return self::SUCCESS;
    }
}
