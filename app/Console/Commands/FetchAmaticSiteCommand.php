<?php

namespace App\Console\Commands;

use App\Models\GameBundle;
use App\Services\GamePlay\Amatic\AmaticLobbyScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Imports every Amatic game an operator's lobby lists, one `amatic:fetch`
 * per game (mirror → maths verified against Amatic's live server → template
 * + bundle + games), tagging the games with the lobby's own categories:
 *
 *   php artisan amatic:fetch-site https://www.grandx.com/casino/ --shop=4
 *   php artisan amatic:fetch-site https://www.grandx.com/casino/ --only=fortunejoker10,admiral
 *   php artisan amatic:fetch-site https://www.grandx.com/casino/ --list     # just show what's there
 *
 * Games that can't be imported faithfully (other client generations, maths
 * that no rule set reproduces) are skipped with the reason; everything is
 * written to a JSON report.
 */
class FetchAmaticSiteCommand extends Command
{
    protected $signature = 'amatic:fetch-site
        {lobby=https://www.grandx.com/casino/ : The casino lobby page}
        {--shop=* : Shop id or name to add the games to (repeatable; default: every shop)}
        {--only=* : Only these Amatic game keys (e.g. fortunejoker10), comma-separated or repeated}
        {--skip-existing : Skip games already imported (an active bundle for the same page)}
        {--spins=40 : Live spins sampled per game to verify the maths}
        {--reuse-mirror : Reuse earlier mirrors instead of downloading again}
        {--keep-bundle : Keep existing active bundles (re-verify/update maths only)}
        {--list : Only list the lobby\'s games}
        {--report= : Report path (default storage/app/amatic-site-report.json)}';

    protected $description = 'Import every Amatic game listed on an operator lobby (e.g. GrandX), with the lobby\'s categories';

    /** Lobby sections that list everything — not worth a category of their own. */
    private const array SKIP_CATEGORIES = ['All Games'];

    public function handle(AmaticLobbyScraper $scraper): int
    {
        $lobby = (string) $this->argument('lobby');
        $this->info("Reading {$lobby}");
        $games = $scraper->games($lobby, fn (string $m) => $this->output->isVerbose() ? $this->line("  {$m}") : null);
        $this->info(count($games).' games listed');

        $only = array_filter(array_map('trim', explode(',', implode(',', (array) $this->option('only')))));
        if ($only !== []) {
            $games = array_values(array_filter($games, fn ($g) => in_array($g['key'], $only, true)));
        }

        if ($this->option('list')) {
            $this->table(['key', 'title', 'categories'], array_map(fn ($g) => [$g['key'] ?? '-', $g['title'], implode(', ', $g['categories'])], $games));

            return self::SUCCESS;
        }

        $report = [];
        foreach ($games as $n => $g) {
            $this->newLine();
            $this->info(sprintf('[%d/%d] %s (%s)', $n + 1, count($games), $g['title'], $g['key'] ?? '?'));
            $row = ['title' => $g['title'], 'key' => $g['key'], 'categories' => $g['categories']];

            if ($g['url'] === null) {
                $report[] = $row + ['status' => 'skipped', 'reason' => 'the lobby gave no Amatic launcher URL'];
                $this->warn('  no launcher URL — skipped');

                continue;
            }
            if ($this->option('skip-existing') && $this->imported((string) $g['key'])) {
                $report[] = $row + ['status' => 'exists'];
                $this->line('  already imported — skipped');

                continue;
            }

            $args = [
                'url' => $g['url'],
                '--plain-code' => true,
                '--title' => $g['title'],
                '--spins' => (int) $this->option('spins'),
                '--category' => array_values(array_diff($g['categories'], self::SKIP_CATEGORIES)),
            ];
            if ($g['poster'] ?? null) {
                $args['--poster'] = $g['poster'];
            }
            if ($shops = (array) $this->option('shop')) {
                $args['--shop'] = $shops;
            }
            foreach (['reuse-mirror', 'keep-bundle'] as $flag) {
                if ($this->option($flag)) {
                    $args["--{$flag}"] = true;
                }
            }
            $exit = $this->call('amatic:fetch', $args);
            $report[] = $row + ['status' => $exit === self::SUCCESS ? 'imported' : 'failed'];
        }

        $path = (string) ($this->option('report') ?: storage_path('app/amatic-site-report.json'));
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $counts = array_count_values(array_column($report, 'status'));
        $this->newLine();
        $this->table(['status', 'games'], array_map(fn ($s, $c) => [$s, $c], array_keys($counts), $counts));
        $this->info("Report: {$path}");

        return self::SUCCESS;
    }

    private function imported(string $key): bool
    {
        return GameBundle::query()->where('is_active', true)->where('entry', 'like', "%/amarent/{$key}.html")->exists();
    }
}
