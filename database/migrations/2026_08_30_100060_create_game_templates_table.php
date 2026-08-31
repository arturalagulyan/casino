<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The master game catalogue — one row per installed game package.
 * ← w_games rows with shop_id = 0, merged with w_game_path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // legacy name, e.g. PragmaticSweetBonanza
            $table->string('title');
            $table->string('provider')->nullable();
            $table->string('engine')->default('internal'); // App\Enums\GameEngine
            $table->string('package_path')->nullable();  // legacy w_game_path.path (server code)
            $table->string('client_path')->nullable();    // front-end bundle
            $table->string('device')->default('both');    // App\Enums\Device
            $table->string('bank_type')->default('slots'); // App\Enums\BankType

            $table->json('default_bet_options')->nullable();
            $table->decimal('default_denomination', 20, 4)->default(1);
            $table->json('default_lines_config')->nullable();
            $table->json('default_jackpot_chances')->nullable();
            $table->json('default_advanced')->nullable();
            $table->string('scale_mode')->default('');    // App\Enums\ScaleMode
            $table->string('view_state')->default('');    // App\Enums\ViewState

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_templates');
    }
};
