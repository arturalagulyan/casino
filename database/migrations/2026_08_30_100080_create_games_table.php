<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A template published into one shop, with that shop's tuning.
 * ← w_games rows with shop_id > 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('game_templates')->cascadeOnDelete(); // legacy original_id
            $table->foreignId('jackpot_id')->nullable()->constrained()->nullOnDelete();          // legacy jpg_id

            $table->string('title')->nullable();
            $table->string('label')->nullable();       // App\Enums\GameLabel
            $table->string('bank_type')->default('slots'); // App\Enums\BankType

            // RTP / behaviour tuning — per-shop overrides of the template spec
            $table->unsignedTinyInteger('rtp_percent')->nullable();       // overrides shops.rtp_percent
            $table->unsignedInteger('max_win_multiplier')->nullable();    // overrides shops.max_win_multiplier
            $table->unsignedInteger('wild_multiplier')->nullable();       // overrides template
            $table->unsignedInteger('free_spins_count')->nullable();      // overrides template
            $table->json('free_spins_table')->nullable();                 // overrides template
            $table->json('win_distribution')->nullable();                 // overrides template win-size curve
            $table->unsignedTinyInteger('reserve_percent')->default(0);   // legacy rezerv — gamble win chance
            $table->unsignedInteger('cask')->default(0);
            $table->json('lines_config_spin')->nullable();
            $table->json('lines_config_spin_bonus')->nullable();
            $table->json('lines_config_bonus')->nullable();
            $table->json('lines_config_bonus_bonus')->nullable();
            $table->json('win_chances')->nullable();       // overrides template win_chances wholesale
            $table->json('jackpot_chances')->nullable();   // legacy chanceFirepot*/fireCount* (firepots)
            $table->json('advanced')->nullable();
            $table->json('engine_state')->nullable();      // per-game RTP feedback loop (legacy game.advanced)
            $table->json('bet_options')->nullable();       // legacy bet / bet_ALL
            $table->decimal('denomination', 20, 4)->default(1);
            $table->string('scale_mode')->nullable();
            $table->string('view_state')->nullable();

            $table->boolean('is_visible')->default(true); // legacy view
            $table->integer('sort_order')->default(0);

            // Denormalised running totals (updated per spin)
            $table->decimal('total_bet', 20, 4)->default(0);   // legacy stat_in
            $table->decimal('total_win', 20, 4)->default(0);   // legacy stat_out
            $table->unsignedBigInteger('rounds_count')->default(0); // legacy bids

            $table->timestamps();

            $table->unique(['shop_id', 'template_id']);
            $table->index(['shop_id', 'is_visible', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
