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

            // RTP / behaviour tuning
            $table->unsignedTinyInteger('reserve_percent')->default(0);  // legacy rezerv
            $table->unsignedInteger('cask')->default(0);
            $table->json('lines_config_spin')->nullable();
            $table->json('lines_config_spin_bonus')->nullable();
            $table->json('lines_config_bonus')->nullable();
            $table->json('lines_config_bonus_bonus')->nullable();
            $table->json('jackpot_chances')->nullable();  // legacy chanceFirepot*/fireCount*
            $table->json('advanced')->nullable();
            $table->json('bet_options')->nullable();      // legacy bet / bet_ALL
            $table->decimal('denomination', 20, 4)->default(1);
            $table->string('scale_mode')->default('');
            $table->string('view_state')->default('');

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
