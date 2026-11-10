<?php

namespace App\Console\Commands;

use App\Services\Catalog\ProviderCategorySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Categories = Betboy's providers (with icons); every other category becomes
 * a tag. See {@see ProviderCategorySync}.
 *
 *   php artisan categories:sync-providers --dry-run
 *   php artisan categories:sync-providers
 */
class SyncProviderCategoriesCommand extends Command
{
    protected $signature = 'categories:sync-providers
        {--source='.ProviderCategorySync::SOURCE.' : Folder with providers.json and provider-icons/}
        {--dry-run : Show what would change, change nothing}';

    protected $description = 'Sync provider categories with the Betboy provider snapshot and turn all other categories into tags';

    public function handle(ProviderCategorySync $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun) {
            $path = 'backups/categories-'.now()->format('Ymd-His').'.json';
            Storage::disk('local')->put($path, json_encode([
                'categories' => DB::table('categories')->get(),
                'category_game' => DB::table('category_game')->get(),
                'category_shop' => DB::table('category_shop')->get(),
            ]));
            $this->line("Backup: storage/app/private/{$path}");
        }

        $source = (string) $this->option('source');
        $report = $sync->sync(str_starts_with($source, '/') ? $source : base_path($source), $dryRun);

        $this->info(($dryRun ? '[dry run] ' : '').sprintf(
            '%d provider categories (%d with icons), %d categories turned into tags, %d kept.',
            count($report['providers']), $report['icons'], count($report['tags']), count($report['kept']),
        ));
        if ($report['tags'] !== []) {
            $this->line('  tags: '.implode(', ', $report['tags']));
        }
        if ($report['kept'] !== []) {
            $this->warn('  kept as categories (they carry game config or sub-categories): '.implode(', ', $report['kept']));
        }

        return self::SUCCESS;
    }
}
