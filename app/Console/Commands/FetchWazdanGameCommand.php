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
use App\Services\GamePlay\Wazdan\WazdanLibrary;
use App\Services\GamePlay\Wazdan\WazdanLiveClient;
use App\Services\GamePlay\Wazdan\WazdanMirror;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import a Wazdan slot from the Betboy lobby, the way apollo:fetch imports
 * Apollo ones:
 *
 *  1. launch it there as a DEMO session (fun credits) and capture Wazdan's
 *     setup (GAME_PARAMS, GAME — stakes —, STATE, …) and its rules pages
 *  2. mirror the HTML5 (GWT) client off its static host ({@see WazdanMirror}) —
 *     the game's own files to `storage/app/game-fetch/<Code>/`, what every
 *     Wazdan game shares to `storage/app/game-shared/wazdan/`
 *  3. create/refresh the GameTemplate (client protocol Wazdan) and upload the
 *     game's files as its active bundle
 *  4. record the outcome library — whole demo rounds (`wazdan:sample`) — up
 *     to --rounds
 *  5. create/refresh a Game in each shop, in the Wazdan category, with the
 *     lobby's tile as its poster
 *
 *   php artisan wazdan:fetch sh_wzdn
 *   php artisan wazdan:fetch "Super Hot" --shop=4 --rounds=20000
 *   php artisan wazdan:fetch --all
 *   php artisan wazdan:fetch --all --reuse-init --rounds=0   # rebuild the clients, no game launches
 */
class FetchWazdanGameCommand extends Command
{
    use ImportsBetboyGames;

    protected $signature = 'wazdan:fetch
        {game? : Betboy game id or name (sh_wzdn, "Super Hot")}
        {--all : Every Wazdan game in the Betboy lobby}
        {--code= : Template code (default: the name in StudlyCase, e.g. SuperHot)}
        {--title= : Display title (default: the lobby\'s name)}
        {--shop=* : Shop id or name to add the game to (repeatable; default: every shop)}
        {--currency=EUR : Pricing currency of the stakes}
        {--rtp=90 : Per-shop RTP %}
        {--max-win=2000 : Per-shop max win (× bet)}
        {--category=* : Tag (by name, created if missing) for the game — the provider category is always "Wazdan"}
        {--poster= : Poster image URL (default: the Betboy lobby tile)}
        {--rounds=3000 : Outcome library size to record up to}
        {--reuse-init : Take the setup captured by an earlier import instead of launching a demo (no Betboy launch)}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Import a Wazdan slot from the Betboy lobby: mirror its client, capture its setup, record its outcome library';

    public function handle(BetboySite $betboy, WazdanMirror $mirror, BundleManager $bundles, WazdanLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        if ($this->shops() === null) {
            return self::FAILURE;
        }
        if ($this->option('all')) {
            $failed = [];
            foreach ($betboy->games() as $g) {
                if (($g['providerName'] ?? '') !== 'Wazdan') {
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

    private function import(BetboySite $betboy, WazdanMirror $mirror, BundleManager $bundles, WazdanLibrary $library, string $key): int
    {
        $shops = $this->shops() ?? [];

        // 1. the game, Wazdan's own setup and rules pages from a demo session
        try {
            $lobby = $betboy->game($key);
            $earlier = GameTemplate::query()->where('client_protocol', ClientProtocol::Wazdan)->get()
                ->first(fn (GameTemplate $t) => ($t->bonus_config['wazdan']['game_key'] ?? null) === $lobby['stringId']);
            if ($this->option('reuse-init') && $earlier !== null) {
                $wz = (array) $earlier->bonus_config['wazdan'];
                [$init, $sid, $page] = [(array) $wz['init'], (string) $wz['sid'], (string) $wz['page']];
                $help = $this->earlierHelp($earlier);
                $this->line("  setup from template #{$earlier->id} {$earlier->code}");
            } else {
                $launch = $betboy->demo((string) $lobby['stringId']);
                $page = Http::withUserAgent('Mozilla/5.0')->timeout(30)->get($launch['page'])->throw()->body();
                $client = WazdanLiveClient::fromPage($page);
                $init = $client->open();   // refuses anything but a demo session
                $client->close();
                $sid = $client->sid;
                $help = $this->help($launch['page'], $init);
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (($lobby['providerName'] ?? '') !== 'Wazdan') {
            $this->warn("  Betboy lists '{$lobby['name']}' under {$lobby['providerName']}, not Wazdan — trying anyway");
        }
        $helpVersion = $help['version'] ?? null;
        unset($help['version']);
        $stakes = array_values(array_filter(array_map('intval', explode(',', (string) ($init['GAME']['stake_list'] ?? '')))));
        sort($stakes);
        if ($stakes === [] || ! isset($init['STATE']['symb'])) {
            $this->error('The demo setup names no stakes or reels: '.mb_strimwidth(json_encode($init) ?: '', 0, 300));

            return self::FAILURE;
        }
        $cmd = WazdanLiveClient::params($sid)['cmd'] ?? '';
        [$reels, $rows] = self::grid(count(explode(',', (string) $init['STATE']['symb'])));
        $this->info(sprintf('%s — Wazdan %s: %d symbols, stakes %s (recorded at %d), RTP %s%%%s', $lobby['name'], $lobby['stringId'],
            count(explode(',', (string) $init['STATE']['symb'])), implode(',', array_slice($stakes, 0, 6)).(count($stakes) > 6 ? ',…' : ''),
            $stakes[0], $init['GAME']['rtp'] ?? '?', isset($init['STATE']['bonus_cost']) ? ' — feature buy (not offered)' : ''));

        // 2. the client
        $multi = $this->option('all');
        $code = (string) (($multi ? null : $this->option('code')) ?: FetchNetEntGameCommand::codeFor((string) $lobby['name']));
        // codes compare case-insensitively: never take over another game's template
        $existing = GameTemplate::query()->where('code', $code)->first();
        if ($existing && ($existing->bonus_config['wazdan']['game_key'] ?? null) !== $lobby['stringId']) {
            $code .= 'Wazdan';
            $existing = GameTemplate::query()->where('code', $code)->first();
            if ($existing && ($existing->bonus_config['wazdan']['game_key'] ?? null) !== $lobby['stringId']) {
                $this->error("Template code {$code} is taken by #{$existing->id} {$existing->code} ({$existing->title}) — pass another --code.");

                return self::FAILURE;
            }
            $this->warn("  code taken by another game — using {$code}");
        }
        $title = (string) (($multi ? null : $this->option('title')) ?: $lobby['name']);
        $dir = storage_path("app/game-fetch/{$code}");
        $this->info('Mirroring '.WazdanMirror::HOST.'…');
        $staging = storage_path('app/game-fetch/_incoming-'.getmypid());
        try {
            $m = $mirror->mirror($page, $help, $staging, WazdanMirror::sharedDir(), fn (string $msg) => $this->output->write("\r  <fg=gray>·</> {$msg}   "));
            $this->newLine();
        } catch (RuntimeException $e) {
            File::deleteDirectory($staging);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($m['missing'] !== []) {
            File::deleteDirectory($staging);
            $this->error('Client files missing on the host: '.implode(', ', array_slice($m['missing'], 0, 10)));

            return self::FAILURE;
        }
        File::deleteDirectory($dir);
        File::moveDirectory($staging, $dir);
        $this->table([], [
            ['module', "{$m['folder']}/{$m['module']} — ".count($m['permutations']).' permutations'],
            ['bundle', sprintf('%d files (%.1f MB)', $m['files'], $m['bytes'] / 1048576)],
            ['shared assets', sprintf('%d in the manifest: %d downloaded, %d already in the store, %d not on the host', $m['assets'], $m['fetched'], $m['reused'], $m['absent'])],
            ['rules pages', implode(', ', array_keys($help)) ?: 'none'],
            ['mirrored to', $dir],
        ]);

        // 3. template — the maths is the outcome library; the columns just describe the grid
        $currency = Currency::tryFrom(strtoupper((string) $this->option('currency'))) ?? Currency::EUR;
        $options = array_map(fn (int $c) => round($c / 100, 2), $stakes);
        $template = GameTemplate::updateOrCreate(['code' => $code], array_filter([
            'title' => $title,
            'engine' => GameEngine::Internal,
            'device' => 'both',
            'bank_type' => BankType::Slots,
            'client_protocol' => ClientProtocol::Wazdan,
            'pricing_currency' => $currency,
            'default_denomination' => 1,
            'default_bet_options' => $options,
            'reel_count' => $reels, 'row_count' => $rows, 'min_match' => 3,
            'symbols' => [], 'symbol_count' => 0, 'paytable' => [], 'reel_strips' => [], 'paylines' => null,
            'has_free_spins' => true, 'has_gamble' => true,
            'bonus_config' => ['wazdan' => [
                'game_key' => $lobby['stringId'], 'name' => $lobby['name'], 'folder' => $m['folder'], 'cmd' => $cmd,
                'record_stake' => $stakes[0], 'stakes' => $stakes, 'start_arg' => self::startArg($init, $stakes[0]),
                'help_version' => $helpVersion, 'sid' => $sid, 'page' => $page, 'init' => $init,
            ]],
            'poster_path' => $this->poster($betboy, $lobby, $code),
            'is_active' => true,
        ], fn ($v) => $v !== null));
        $this->info(($template->wasRecentlyCreated ? 'Created' : 'Updated')." template #{$template->id} {$code} — {$title}");

        // 4. bundle
        $zip = $this->zip($dir, $code);
        $bundle = $bundles->store($template, new UploadedFile($zip, basename($zip), 'application/zip', null, true),
            entry: 'index.html', notes: Str::limit("Wazdan client mirror (wazdan:fetch) of {$m['folder']}", 250));
        File::delete($zip);
        $this->info("Bundle v{$bundle->version} active — {$bundle->file_count} files, entry {$bundle->entry}");

        // 5. the outcome library
        $gameKey = (string) $lobby['stringId'];
        $stats = $library->stats($gameKey);
        $short = (int) $this->option('rounds') - $stats['rounds'];
        if ($short > 0) {   // empty, or an earlier import stopped part-way
            $this->line("  recording {$short} demo rounds for the outcome library…");
            $this->call('wazdan:sample', ['game' => $gameKey, '--rounds' => $short, '--betboy-token' => $this->option('betboy-token')]);
            $library->forget($gameKey);
            $stats = $library->stats($gameKey);
        }
        $this->info(sprintf('Outcome library: %d rounds (%d with free games / respins), return %.2f%%, top win %d× the stake',
            $stats['rounds'], $stats['features'], $stats['rtp'], intdiv($stats['max_cents'], max(1, $stakes[0]))));

        // 6. games
        $category = Category::where('external_ref', 'betboy:wazdan')->first()
            ?? Category::firstOrCreate(['slug' => 'wazdan'], ['title' => 'Wazdan', 'position' => 3]);
        foreach ((array) $this->option('category') as $name) {
            $template->tags()->syncWithoutDetaching([Tag::firstOrCreate(['slug' => Str::slug((string) $name)], ['name' => (string) $name, 'position' => 10])->id]);
        }
        foreach ($shops as $shop) {
            $row = Game::updateOrCreate(['shop_id' => $shop->id, 'template_id' => $template->id], [
                'title' => $title, 'bank_type' => BankType::Slots, 'rtp_percent' => (int) $this->option('rtp'),
                'max_win_multiplier' => (int) $this->option('max-win'), 'reserve_percent' => 4,
                'bet_options' => $options, 'denomination' => 1, 'pricing_currency' => $currency, 'is_visible' => true,
            ]);
            $row->categories()->syncWithoutDetaching([$category->id]);
            $this->line("  <fg=green>✓</> game #{$row->id} in shop {$shop->name}");
        }

        $this->newLine();
        $this->info('Done. Try it: '.url("/games/demo/{$code}"));

        return self::SUCCESS;
    }

    /**
     * What the client sends with START: the stake, and — in "coin bonus" games
     * — the same number as `coins`.
     *
     * @return array<string, int>
     */
    public static function startArg(array $init, int $stake): array
    {
        return ['stake' => $stake] + (! empty($init['GAME_PARAMS']['coin bonus']) ? ['coins' => $stake] : []);
    }

    /** @return array{0: int, 1: int} reels × rows for a board of $n symbols (descriptive only) */
    private static function grid(int $n): array
    {
        return match ($n) {
            9 => [3, 3], 12 => [4, 3], 16 => [4, 4], 20 => [5, 4], 25 => [5, 5], 27 => [3, 9], 30 => [6, 5], 36 => [6, 6],
            default => [5, max(1, intdiv($n, 5))],
        };
    }

    /**
     * The rules pages the session host's get.php serves (desktop, mobile), and
     * the rules' version.
     *
     * @return array<string, string>
     */
    private function help(string $pageUrl, array $init): array
    {
        $base = rtrim($pageUrl, '/').'/wz/get.php';
        $stakes = array_map('intval', explode(',', (string) ($init['GAME']['stake_list'] ?? '10')));
        $out = [];
        $version = Http::withUserAgent('Mozilla/5.0')->timeout(30)->get($base, ['lang' => 'en', 'type' => 'desktop', 'cpt' => 1, 'return_version' => 1])->json('helpVersion');
        foreach (['desktop', 'mobile'] as $type) {
            $r = Http::withUserAgent('Mozilla/5.0')->timeout(30)->get($base, ['lang' => 'en', 'type' => $type, 'currency' => ' ', 'cpt' => 1,
                'ulm' => 0, 'el' => 0, 'jpot' => 0, 'gamble' => 1, 'shop' => 0, 'max_stake' => max($stakes), 'gpt' => 0, 'min_stake' => min($stakes),
                'hinfo' => $init['GAME']['hinfo'] ?? '']);
            if ($r->successful() && str_contains($r->body(), '<')) {
                $out[$type] = $r->body();
            }
        }
        if ($out === []) {
            $this->warn('  no rules page from the host');
        }

        return $out + (is_string($version) ? ['version' => $version] : []);
    }

    /** @return array<string, string> the rules pages an earlier import kept */
    private function earlierHelp(GameTemplate $t): array
    {
        $out = [];
        foreach (['desktop', 'mobile'] as $type) {
            $file = storage_path("app/game-fetch/{$t->code}/help/{$type}.html");
            if (is_file($file)) {
                $out[$type] = (string) file_get_contents($file);
            }
        }

        return $out + (isset($t->bonus_config['wazdan']['help_version']) ? ['version' => (string) $t->bonus_config['wazdan']['help_version']] : []);
    }
}
