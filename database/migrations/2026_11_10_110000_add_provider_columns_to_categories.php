<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `type` (provider | category), `image_path` (icon) and `external_ref` on
 * categories. Some databases already have them (added outside migrations),
 * so each column / index is only created when missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $has = fn (string $column) => Schema::hasColumn('categories', $column);
        $indexes = collect(Schema::getIndexes('categories'))->pluck('name');

        Schema::table('categories', function (Blueprint $table) use ($has, $indexes) {
            if (! $has('type')) {
                $table->string('type')->default('category')->after('template_id');
            }
            if (! $has('image_path')) {
                $table->string('image_path')->nullable()->after('slug');
            }
            if (! $has('external_ref')) {
                $table->string('external_ref')->nullable()->after('image_path');
            }
            if (! $indexes->contains('categories_type_position_index')) {
                $table->index(['type', 'position']);
            }
            if (! $indexes->contains('categories_external_ref_index')) {
                $table->index('external_ref');
            }
        });
    }

    public function down(): void
    {
        // Left in place: databases that had these columns before this migration rely on them.
    }
};
