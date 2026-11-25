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
use App\Services\GamePlay\Playtech\PlaytechGames;
use App\Services\GamePlay\Playtech\PlaytechLibrary;
use App\Services\GamePlay\Playtech\PlaytechLiveClient;
use App\Services\GamePlay\Playtech\PlaytechMirror;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import a Playtech (NGM HTML5) slot from the Betboy lobby, the way
 * wazdan:fetch imports Wazdan ones:
 *
 *  1. launch it there as a DEMO session (fun credits) and capture the
 *     platform's setup for it (game limits, the game's own login messages)
 *  2. mirror the client off its static host ({@see PlaytechMirror}) — the
 *     game module to `storage/app/game-fetch/<Code>/pt/<rgame>/`, the platform
 *     chrome every game shares to `storage/app/game-shared/playtech/`
 *  3. create/refresh the GameTemplate (client protocol PlaytechNgm) and upload
 *     the game's files as its active bundle (entry: the platform page)
 *  4. record the outcome library — whole demo rounds (`playtech:sample`) — up
 *     to --rounds
 *  5. create/refresh a Game in each shop, in the Playtech category
 *
 * How a game spins (its bet at coin 1, lines, own messages) isn't in its
 * setup: {@see PlaytechGames} has it, read off each client's traffic.
 *
 *   php artisan playtech:fetch whk_pt
 *   php artisan playtech:fetch --all
 */
class FetchPlaytechGameCommand extends Command
{
    use ImportsBetboyGames;

    protected $signature = 'playtech:fetch
        {game? : Betboy game id or name (whk_pt, "White King")}
        {--all : Every Playtech game in the Betboy lobby}
        {--code= : Template code (default: the name in StudlyCase, e.g. WhiteKing)}
        {--title= : Display title (default: the lobby\'s name)}
        {--shop=* : Shop id or name to add the game to (repeatable; default: every shop)}
        {--currency=EUR : Pricing currency of the stakes}
        {--rtp=90 : Per-shop RTP %}
        {--max-win=2000 : Per-shop max win (× bet)}
        {--category=* : Tag (by name, created if missing) for the game — the provider category is always "Playtech"}
        {--poster= : Poster image URL (default: the Betboy lobby tile)}
        {--rounds=3000 : Outcome library size to record up to}
        {--reuse-init : Take the setup captured by an earlier import instead of launching a demo (no Betboy launch)}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Import a Playtech slot from the Betboy lobby: mirror its client, capture its setup, record its outcome library';

    public function handle(BetboySite $betboy, PlaytechMirror $mirror, BundleManager $bundles, PlaytechLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        if ($this->shops() === null) {
            return self::FAILURE;
        }
        $this->info('Platform chrome (shared)…');
        $c = $mirror->mirrorCommon();
        $this->line("  commonStatic: {$c['fetched']} downloaded, {$c['reused']} already in the store");

        if ($this->option('all')) {
            $failed = [];
            foreach ($betboy->games() as $g) {
                if (($g['providerName'] ?? '') !== 'Playtech') {
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

    private function import(BetboySite $betboy, PlaytechMirror $mirror, BundleManager $bundles, PlaytechLibrary $library, string $key): int
    {
        $shops = $this->shops() ?? [];

        // 1. the game and the platform's setup for it, from a demo session
        try {
            $lobby = $betboy->game($key);
            $spec = PlaytechGames::spec((string) $lobby['stringId']);
            $earlier = GameTemplate::query()->where('client_protocol', ClientProtocol::PlaytechNgm)->get()
                ->first(fn (GameTemplate $t) => ($t->bonus_config['playtech']['game_key'] ?? null) === $lobby['stringId']);
            if ($this->option('reuse-init') && $earlier !== null) {
                $pt = (array) $earlier->bonus_config['playtech'];
                [$init, $rgame, $short] = [(array) $pt['init'], (string) $pt['rgame'], (string) $pt['game']];
                $this->line("  setup from template #{$earlier->id} {$earlier->code}");
            } else {
                $client = PlaytechLiveClient::fromUrl($betboy->demo((string) $lobby['stringId'])['page']);
                $init = $client->open();   // refuses anything but a demo session
                $client->close();
                [$rgame, $short] = [$client->rgame, $client->game];
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $coins = array_values(array_filter(array_map('intval', (array) ($init['limits']['data']['coinSizes'] ?? []))));
        sort($coins);
        if ($coins === []) {
            $this->error('The demo setup names no coin sizes: '.mb_strimwidth(json_encode($init) ?: '', 0, 300));

            return self::FAILURE;
        }
        $this->info(sprintf('%s — Playtech %s (%s): bet %d × coin, coins %s', $lobby['name'], $lobby['stringId'], $rgame, $spec['bet'], implode(',', $coins)));

        // 2. the client
        $multi = $this->option('all');
        $code = (string) (($multi ? null : $this->option('code')) ?: FetchNetEntGameCommand::codeFor((string) $lobby['name']));
        // codes compare case-insensitively: never take over another game's template
        $existing = GameTemplate::query()->where('code', $code)->first();
        if ($existing && ($existing->bonus_config['playtech']['game_key'] ?? null) !== $lobby['stringId']) {
            $code .= 'Playtech';
            $existing = GameTemplate::query()->where('code', $code)->first();
            if ($existing && ($existing->bonus_config['playtech']['game_key'] ?? null) !== $lobby['stringId']) {
                $this->error("Template code {$code} is taken by #{$existing->id} {$existing->code} ({$existing->title}) — pass another --code.");

                return self::FAILURE;
            }
            $this->warn("  code taken by another game — using {$code}");
        }
        $title = (string) (($multi ? null : $this->option('title')) ?: $lobby['name']);
        $dir = storage_path("app/game-fetch/{$code}");
        $staging = storage_path('app/game-fetch/_incoming-'.getmypid());
        $this->info('Mirroring '.PlaytechMirror::HOST."{$rgame}/…");
        try {
            $m = $mirror->mirror($rgame, $short, $staging, [], fn (string $rel) => $this->output->write("\r  <fg=gray>·</> ".mb_strimwidth($rel, 0, 100).'   '));
            $this->newLine();
        } catch (RuntimeException $e) {
            File::deleteDirectory($staging);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        // the entry: the platform page (the rest of commonStatic comes from the shared store)
        File::ensureDirectoryExists("{$staging}/pt/commonStatic");
        File::copy(PlaytechMirror::sharedDir().'/commonStatic/game.html', "{$staging}/pt/commonStatic/game.html");
        File::deleteDirectory($dir);
        File::moveDirectory($staging, $dir);
        $this->table([], [
            ['game module', "{$rgame}: {$m['files']} files ({$m['fetched']} downloaded)"],
            ['not found', $m['missing'] === [] ? 'none' : implode(', ', array_slice($m['missing'], 0, 8)).(count($m['missing']) > 8 ? ', …' : '')],
            ['mirrored to', $dir],
        ]);

        // 3. template — the maths is the outcome library
        $currency = Currency::tryFrom(strtoupper((string) $this->option('currency'))) ?? Currency::EUR;
        $options = array_map(fn (int $c) => round($c * $spec['bet'] / 100, 2), $coins);
        $before = array_map(fn (array $b) => $b + ['reply' => PlaytechGames::BEFORE_REPLY[(int) $b['ID']] ?? []], (array) ($spec['before'] ?? []));
        $template = GameTemplate::updateOrCreate(['code' => $code], array_filter([
            'title' => $title,
            'engine' => GameEngine::Internal,
            'device' => 'both',
            'bank_type' => BankType::Slots,
            'client_protocol' => ClientProtocol::PlaytechNgm,
            'pricing_currency' => $currency,
            'default_denomination' => 1,
            'default_bet_options' => $options,
            'reel_count' => 5, 'row_count' => 3, 'min_match' => 3,
            'symbols' => [], 'symbol_count' => 0, 'paytable' => [], 'reel_strips' => [], 'paylines' => null,
            'has_free_spins' => true, 'has_gamble' => false,
            'bonus_config' => ['playtech' => [
                'game_key' => $lobby['stringId'], 'name' => $lobby['name'], 'game' => $short, 'rgame' => $rgame,
                'mult' => $spec['bet'], 'record_bet' => $spec['bet'], 'lines' => $spec['lines'], 'custom' => $spec['custom'] ?? null,
                'before' => $before, 'coins' => $coins, 'init' => ['limits' => $init['limits'] ?? [], 'gameLogin' => $init['gameLogin'] ?? []],
            ]],
            'poster_path' => $this->poster($betboy, $lobby, $code),
            'is_active' => true,
        ], fn ($v) => $v !== null));
        $this->info(($template->wasRecentlyCreated ? 'Created' : 'Updated')." template #{$template->id} {$code} — {$title}");

        // 4. bundle
        $zip = $this->zip($dir, $code);
        $bundle = $bundles->store($template, new UploadedFile($zip, basename($zip), 'application/zip', null, true),
            entry: 'pt/commonStatic/game.html', notes: Str::limit("Playtech client mirror (playtech:fetch) of {$rgame}", 250));
        File::delete($zip);
        $this->info("Bundle v{$bundle->version} active — {$bundle->file_count} files, entry {$bundle->entry}");

        // 5. the outcome library
        $gameKey = (string) $lobby['stringId'];
        $stats = $library->stats($gameKey);
        $short = (int) $this->option('rounds') - $stats['rounds'];
        if ($short > 0) {   // empty, or an earlier import stopped part-way
            $this->line("  recording {$short} demo rounds for the outcome library…");
            $this->call('playtech:sample', ['game' => $gameKey, '--rounds' => $short, '--betboy-token' => $this->option('betboy-token')]);
            $library->forget($gameKey);
            $stats = $library->stats($gameKey);
        }
        $this->info(sprintf('Outcome library: %d rounds (%d with free spins / respins), return %.2f%%, top win %d× the bet',
            $stats['rounds'], $stats['features'], $stats['rtp'], intdiv($stats['max_cents'], max(1, $spec['bet']))));

        // 6. games
        $category = Category::where('external_ref', 'betboy:playtech')->first()
            ?? Category::firstOrCreate(['slug' => 'playtech'], ['title' => 'Playtech', 'position' => 3]);
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
}
