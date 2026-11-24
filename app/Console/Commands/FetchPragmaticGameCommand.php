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
use App\Services\GamePlay\NetEnt\BetboySite;
use App\Services\GamePlay\PragmaticPlay\PragmaticLibrary;
use App\Services\GamePlay\PragmaticPlay\PragmaticLiveClient;
use App\Services\GamePlay\PragmaticPlay\PragmaticLegacyBundle;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import a Pragmatic Play slot from the Betboy lobby, the way apollo:fetch
 * imports Apollo ones:
 *
 *  1. launch it there as a DEMO session (fun credits) and capture its doInit
 *     (paytable, reel sets, lines, initial screen)
 *  2. build the client from OUR legacy Pragmatic front-end of the same game
 *     symbol ({@see PragmaticLegacyBundle}) — Betboy's own client build carries
 *     a licence check bound to Betboy's sessions, ours doesn't; games without a
 *     legacy client are skipped
 *  3. create/refresh the GameTemplate (client protocol PragmaticPlay) and upload
 *     the client as its active bundle
 *  4. record the outcome library — whole demo rounds (`pragmatic:sample`) — up to --rounds
 *  5. create/refresh a Game in each shop, in the Pragmatic category, with the
 *     lobby's tile as its poster
 *
 * Codes: the name in StudlyCase, or `<Name>Pragmatic` when that code is taken
 * here or by a legacy Pragmatic game (the dev server still has those).
 *
 *   php artisan pragmatic:fetch vs20fruitsw_prg
 *   php artisan pragmatic:fetch --all --rounds=3000
 */
class FetchPragmaticGameCommand extends Command
{
    use ImportsBetboyGames;

    /** The coin ladder offered (× lines = the stakes), as Pragmatic's own. */
    public const array COINS = [0.01, 0.02, 0.03, 0.04, 0.05, 0.10, 0.20, 0.25, 0.50, 0.75, 1.00, 2.00, 3.00, 4.00, 5.00];

    protected $signature = 'pragmatic:fetch
        {game? : Betboy game id or name (vs20fruitsw_prg, "Sweet Bonanza")}
        {--all : Every Pragmatic slot in the Betboy lobby}
        {--code= : Template code (default: the name in StudlyCase, e.g. SweetBonanza)}
        {--title= : Display title (default: the lobby\'s name)}
        {--shop=* : Shop id or name to add the game to (repeatable; default: every shop)}
        {--currency=EUR : Pricing currency of the stakes}
        {--rtp=90 : Per-shop RTP %}
        {--max-win=2000 : Per-shop max win (× bet)}
        {--category=* : Tag (by name, created if missing) for the game — the provider category is always "Pragmatic"}
        {--poster= : Poster image URL (default: the Betboy lobby tile)}
        {--rounds=3000 : Outcome library size to record up to}
        {--reuse-init : Take the doInit an earlier import stored instead of launching a demo}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Import a Pragmatic Play slot from the Betboy lobby with our legacy client: capture its setup, record its outcome library';

    /** @var array<string, int>|null lowercased legacy game folders */
    private ?array $legacyCodes = null;

    public function handle(BetboySite $betboy, PragmaticLegacyBundle $legacy, BundleManager $bundles, PragmaticLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        if ($this->shops() === null) {
            return self::FAILURE;
        }
        if ($this->option('all')) {
            $failed = [];
            foreach ($betboy->games() as $g) {
                if (($g['providerName'] ?? '') !== 'Pragmatic') {   // not "Pragmatic Play Live …": live dealer tables
                    continue;
                }
                if ($legacy->folders(self::symbol((string) $g['stringId'])) === []) {
                    $this->line("<fg=gray>skip {$g['name']} ({$g['stringId']}): no legacy client</>");

                    continue;
                }
                $this->newLine();
                $this->info("━━ {$g['name']} ({$g['stringId']})");
                if ($this->import($betboy, $legacy, $bundles, $library, (string) $g['stringId']) !== self::SUCCESS) {
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

        return $this->import($betboy, $legacy, $bundles, $library, (string) $this->argument('game'));
    }

    private function import(BetboySite $betboy, PragmaticLegacyBundle $legacy, BundleManager $bundles, PragmaticLibrary $library, string $key): int
    {
        $shops = $this->shops() ?? [];

        // 1. the game, and Pragmatic's own setup from a demo session
        try {
            $lobby = $betboy->game($key);
            if ($legacy->folders(self::symbol((string) $lobby['stringId'])) === []) {
                throw new RuntimeException("No legacy Pragmatic client for {$lobby['stringId']} — not importable");
            }
            $stored = $this->option('reuse-init') ? GameTemplate::query()->where('client_protocol', ClientProtocol::PragmaticPlay)->get()
                ->first(fn (GameTemplate $t) => ($t->bonus_config['pragmatic']['game_key'] ?? null) === $lobby['stringId'])?->bonus_config['pragmatic']['init'] ?? null : null;
            if (is_string($stored) && $stored !== '') {
                $init = PragmaticLiveClient::parse($stored);
            } else {
                $html = Http::withUserAgent('Mozilla/5.0')->timeout(30)->retry(2, 2000)->get($betboy->demo((string) $lobby['stringId'])['launch'])->body();
                $client = PragmaticLiveClient::fromLaunchHtml($html);   // refuses anything but fun credits
                $client->open();
                $init = $client->call('doInit', ['cver' => '1']);   // again, idle: a round the session had in play is played out
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (($lobby['providerName'] ?? '') !== 'Pragmatic') {
            $this->warn("  Betboy lists '{$lobby['name']}' under {$lobby['providerName']}, not Pragmatic — trying anyway");
        }
        if (($init['na'] ?? 's') !== 's') {
            $this->error("The demo session is still in a round (na={$init['na']}) — try again");

            return self::FAILURE;
        }
        $lines = max(1, (int) ($init['l'] ?? 1));
        $rows = max(1, (int) ($init['sh'] ?? 3));
        $reels = max(1, intdiv(count(explode(',', (string) ($init['s'] ?? ''))), $rows));
        $coins = self::COINS;
        $recordCoin = $coins[0];
        $betCents = (int) round($recordCoin * $lines * 100);
        $stakes = array_map(fn ($c) => round($c * $lines, 2), $coins);
        $this->info("{$lobby['name']} — Pragmatic {$lobby['stringId']}: {$reels}×{$rows}, {$lines} lines, stakes "
            .implode(',', $stakes)." (recorded at {$stakes[0]})");

        // 2. the client
        $multi = $this->option('all');
        $existing = GameTemplate::query()->get(['id', 'code', 'bonus_config'])
            ->first(fn (GameTemplate $t) => ($t->bonus_config['pragmatic']['game_key'] ?? null) === $lobby['stringId']);
        $code = (string) (($multi ? null : $this->option('code')) ?: $existing?->code ?: $this->freeCode((string) $lobby['name']));
        $owner = GameTemplate::query()->where('code', $code)->first();   // codes compare case-insensitively
        if ($owner && ($owner->bonus_config['pragmatic']['game_key'] ?? null) !== $lobby['stringId']) {
            $this->error("Template code {$code} is taken by #{$owner->id} {$owner->code} ({$owner->title}) — pass another --code.");

            return self::FAILURE;
        }
        $title = trim((string) (($multi ? null : $this->option('title')) ?: $lobby['name']));
        $dir = storage_path("app/game-fetch/{$code}");
        $symbol = self::symbol((string) $lobby['stringId']);
        $this->info("Building the client from the legacy front-end of {$symbol}");
        $m = $legacy->build($symbol, $dir);
        File::put("{$dir}/.pragmatic-game.json", json_encode($m, JSON_UNESCAPED_SLASHES));
        $this->table([], [
            ['entry', $m['entry']],
            ['legacy', $m['folder']],
            ['builds', implode(',', $m['builds'])],
            ['files', sprintf('%d (%.1f MB)', $m['files'], $m['bytes'] / 1048576)],
            ['built in', $dir],
        ]);

        // 3. template — the maths is the outcome library; the columns just describe the grid
        $config = PragmaticLegacyBundle::config((string) File::get(config('legacy.games_frontend_path')."/{$m['folder']}/gs2c/html5Game.html"));
        $currency = Currency::tryFrom(strtoupper((string) $this->option('currency'))) ?? Currency::EUR;
        $template = GameTemplate::updateOrCreate(['code' => $code], array_filter([
            'title' => $title,
            'engine' => GameEngine::Internal,
            'device' => 'both',
            'bank_type' => BankType::Slots,
            'client_protocol' => ClientProtocol::PragmaticPlay,
            'pricing_currency' => $currency,
            'default_denomination' => 1,
            'default_bet_options' => $stakes,
            'reel_count' => $reels, 'row_count' => $rows, 'min_match' => 3,
            'symbols' => [], 'symbol_count' => 0, 'paytable' => [], 'reel_strips' => [], 'paylines' => null,
            'has_free_spins' => true, 'has_gamble' => false,
            'bonus_config' => ['pragmatic' => [
                'game_key' => $lobby['stringId'], 'name' => $lobby['name'], 'symbol' => $symbol, 'datapath' => $m['datapath'], 'legacy' => $m['folder'],
                'lines' => $lines, 'record_coin' => $recordCoin, 'bet_cents' => $betCents, 'coins' => $coins,
                'languages' => $m['languages'], 'init' => PragmaticLiveClient::encode($init), 'config' => $config,
            ]],
            'poster_path' => $this->poster($betboy, $lobby, $code),
            'is_active' => true,
        ], fn ($v) => $v !== null));
        $this->info(($template->wasRecentlyCreated ? 'Created' : 'Updated')." template #{$template->id} {$code} — {$title}");

        // 4. bundle
        $zip = $this->zip($dir, $code);
        $bundle = $bundles->store($template, new UploadedFile($zip, basename($zip), 'application/zip', null, true),
            entry: $m['entry'], notes: Str::limit("Pragmatic Play legacy client {$m['folder']} (pragmatic:fetch) for {$lobby['stringId']}", 250));
        File::delete($zip);
        $this->info("Bundle v{$bundle->version} active — {$bundle->file_count} files, entry {$bundle->entry}");

        // 5. the outcome library
        $key = (string) $lobby['stringId'];
        $stats = $library->stats($key);
        $short = (int) $this->option('rounds') - $stats['rounds'];
        if ($short > 0) {   // empty, or an earlier import stopped part-way
            $this->line("  recording {$short} demo rounds for the outcome library…");
            $this->call('pragmatic:sample', ['game' => $key, '--rounds' => $short, '--coin' => $recordCoin, '--betboy-token' => $this->option('betboy-token')]);
            $library->forget($key);
            $stats = $library->stats($key);
        }
        $this->info(sprintf('Outcome library: %d rounds (%d with features), return %.2f%%, top win %d× the stake',
            $stats['rounds'], $stats['features'], $stats['rtp'], intdiv($stats['max_cents'], max(1, $betCents))));
        if ($stats['rounds'] < 20000) {
            $this->warn("  a small library repeats rounds and misses rare big wins — grow it with: php artisan pragmatic:sample {$key} --rounds=50000");
        }

        // 6. games
        $category = Category::where('external_ref', 'betboy:pragmatic')->first()
            ?? Category::firstOrCreate(['slug' => 'pragmatic'], ['title' => 'Pragmatic', 'position' => 8]);
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
     * The name in StudlyCase — or `<Name>Pragmatic` when that is a template here
     * already or a legacy Pragmatic game's code (the dev server has those).
     */
    /** Pragmatic's game symbol: Betboy's id without its `_prg` suffix (vs20fruitsw_prg → vs20fruitsw). */
    public static function symbol(string $stringId): string
    {
        return (string) preg_replace('/_prg$/', '', $stringId);
    }

    private function freeCode(string $name): string
    {
        $code = FetchNetEntGameCommand::codeFor($name);
        if ($this->legacyCodes === null) {
            $dir = (string) config('legacy.games_backend_path');
            $this->legacyCodes = is_dir($dir) ? array_flip(array_map(fn ($d) => strtolower(basename($d)), File::directories($dir))) : [];
        }
        $taken = GameTemplate::query()->where('code', $code)->exists()
            || isset($this->legacyCodes[strtolower($code)]) || isset($this->legacyCodes[strtolower($code).'m']);

        return $taken ? "{$code}Pragmatic" : $code;
    }
}
