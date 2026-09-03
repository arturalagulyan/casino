<?php

namespace App\Console\Commands;

use App\Models\GameTemplate;
use App\Services\GamePlay\BundleManager;
use App\Services\Legacy\DryRunRollback;
use App\Services\Legacy\LegacyGameReader;
use App\Services\Legacy\LegacyImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * One-shot migration of a legacy VanguardLTE casino DB (+ its game files) into
 * the rebuild. Reads the read-only `legacy` DB connection and a local mirror of
 * the legacy server's `app/Games` + `public/gamess` folders.
 *
 *   php artisan import:legacy                      # DB rows only
 *   php artisan import:legacy --only=shops,games
 *   php artisan import:legacy --dry-run
 *   php artisan import:legacy --bundles            # + zip & register front-ends
 *   php artisan import:legacy --fresh              # wipe demo/seed data first
 */
class ImportLegacyCommand extends Command
{
    protected $signature = 'import:legacy
        {--only= : Comma list of steps (shops,categories,banks,jackpots,users,apikeys,operators,games)}
        {--dry-run : Roll everything back, just report}
        {--bundles : Also zip & register the mirrored front-end bundles}
        {--fresh : Truncate the seeded demo data before importing}';

    protected $description = 'Import production data from a legacy VanguardLTE casino database';

    public function handle(): int
    {
        if (! $this->legacyReachable()) {
            $this->error('Cannot reach the `legacy` DB connection. Set LEGACY_DB_* in .env (an SSH tunnel or a local dump restore).');

            return self::FAILURE;
        }

        if ($this->option('fresh') && $this->confirm('Truncate all shops / users / games / categories / jackpots first?', false)) {
            $this->wipe();
        }

        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

        try {
            (new LegacyImport($this->output))->run($only, (bool) $this->option('dry-run'));
        } catch (DryRunRollback) {
            $this->warn('Dry run — rolled back.');
        }

        if ($this->option('bundles') && ! $this->option('dry-run')) {
            $this->importBundles();
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. shops=%d  users=%d  categories=%d  jackpots=%d  operators=%d  templates=%d  games=%d',
            DB::table('shops')->count(),
            DB::table('users')->count(),
            DB::table('categories')->count(),
            DB::table('jackpots')->count(),
            DB::table('operators')->count(),
            DB::table('game_templates')->count(),
            DB::table('games')->count(),
        ));

        return self::SUCCESS;
    }

    private function legacyReachable(): bool
    {
        try {
            DB::connection('legacy')->getPdo();
            DB::connection('legacy')->table('shops')->limit(1)->get();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function wipe(): void
    {
        $this->warn('Wiping seeded data…');
        DB::transaction(function () {
            foreach ([
                'category_game', 'category_shop', 'game_rounds', 'game_logs', 'game_sessions',
                'transactions', 'jackpot_wins', 'game_bundles', 'games', 'game_templates',
                'jackpots', 'game_banks', 'api_keys', 'operators', 'categories', 'wallets',
            ] as $t) {
                DB::table($t)->delete();
            }
            // keep the admin, drop everyone else and every non-admin shop
            DB::table('users')->where('role_id', '!=', DB::table('roles')->where('slug', 'admin')->value('id'))->delete();
            DB::table('shops')->delete();
        });
    }

    private function importBundles(): void
    {
        $this->output->section('Registering front-end bundles');
        $reader = app(LegacyGameReader::class);
        $manager = app(BundleManager::class);

        $done = $skipped = $noBundle = $failed = 0;
        $failures = [];

        GameTemplate::query()->orderBy('code')->each(function (GameTemplate $tpl) use ($reader, $manager, &$done, &$skipped, &$noBundle, &$failed, &$failures) {
            if ($tpl->activeBundle) {
                $skipped++;

                return;
            }
            $entry = $reader->bundleEntry($tpl->code);
            if ($entry === null) {
                $noBundle++;

                return;
            }

            try {
                $bundle = $manager->storeFromDirectory($tpl, $reader->bundleDir($tpl->code), entry: $entry);
                $done++;
                $this->line("  <fg=gray>·</> {$tpl->code} — v{$bundle->version}, {$bundle->file_count} files");
            } catch (\Throwable $e) {
                $failed++;
                $failures[$tpl->code] = $e->getMessage();
                $this->line("  <fg=red>✗</> {$tpl->code} — {$e->getMessage()}");
            }
        });

        $this->newLine();
        $this->info("Bundles: registered={$done}  already-had={$skipped}  no-html={$noBundle}  failed={$failed}");
        if ($failures !== []) {
            File::ensureDirectoryExists(storage_path('app/legacy'));
            File::put(storage_path('app/legacy/bundle-failures.json'), (string) json_encode($failures, JSON_PRETTY_PRINT));
            $this->warn('Failure details → storage/app/legacy/bundle-failures.json');
        }
    }
}
