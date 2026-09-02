<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded front-end bundles for a game (the legacy public/games/<Code> folder).
 * One row per uploaded version; the active one is what players load. New —
 * legacy shipped these as loose files in the repo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('disk')->default('game_bundles');
            $table->string('path');                    // dir under the disk, e.g. "actionmoney/3"
            $table->string('entry')->default('index.html');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('file_count')->default(0);
            $table->string('checksum', 64)->nullable(); // sha256 of the uploaded zip
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['game_template_id', 'version']);
            $table->index(['game_template_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_bundles');
    }
};
