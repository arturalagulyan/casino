<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * One-time data step on deploy: categories become Betboy's provider list
 * (with icons) and every other category becomes a tag — see
 * App\Services\Catalog\ProviderCategorySync. The command backs the old
 * categories up to storage/app/private/backups/ first.
 *
 * Skipped under tests (which build their own categories).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        Artisan::call('categories:sync-providers');
    }

    public function down(): void
    {
        // Not reversible automatically — restore from the backup JSON if needed.
    }
};
