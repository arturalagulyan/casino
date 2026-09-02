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
            $table->string('poster_path')->nullable();  // lobby / admin thumbnail — legacy /frontend/<theme>/ico/<name>.jpg
            $table->string('engine')->default('internal'); // App\Enums\GameEngine
            $table->string('package_path')->nullable();  // legacy w_game_path.path (server code)
            $table->string('client_path')->nullable();    // front-end bundle
            $table->string('device')->default('both');    // App\Enums\Device
            $table->string('bank_type')->default('slots'); // App\Enums\BankType
            $table->string('client_protocol')->nullable(); // App\Enums\ClientProtocol — how the bundle talks to us; null = inherit from category, else 'standard'

            $table->json('default_bet_options')->nullable();
            $table->decimal('default_denomination', 20, 4)->default(1);
            $table->json('default_lines_config')->nullable();
            $table->json('default_jackpot_chances')->nullable();
            $table->json('default_advanced')->nullable();
            $table->string('scale_mode')->nullable();    // App\Enums\ScaleMode
            $table->string('view_state')->nullable();    // App\Enums\ViewState

            // ---- Engine spec (the shared "group" config every game in this
            // template runs on — ports the hardcoded VanguardLTE SlotSettings) ----
            $table->unsignedTinyInteger('reel_count')->default(5);
            $table->unsignedTinyInteger('row_count')->default(3);
            $table->unsignedTinyInteger('symbol_count')->default(9);
            $table->json('symbols')->nullable();                      // legacy SymbolGame — playable symbol list
            $table->unsignedTinyInteger('wild_symbol')->nullable();
            $table->unsignedTinyInteger('scatter_symbol')->nullable();
            $table->unsignedTinyInteger('bonus_symbol')->nullable();
            $table->unsignedInteger('wild_multiplier')->default(1);   // legacy slotWildMpl
            $table->unsignedTinyInteger('min_match')->default(3);     // smallest paying run left-to-right (EGT "Action Money" pays 2)
            $table->boolean('has_bonus')->default(false);             // legacy slotBonus
            $table->unsignedTinyInteger('bonus_type')->default(1);    // legacy slotBonusType
            $table->unsignedTinyInteger('scatter_type')->default(0);  // legacy slotScatterType
            $table->json('bonus_config')->nullable();                 // per-scatter feature flows + params (see App\Services\GamePlay\Bonus)
            $table->boolean('has_free_spins')->default(false);
            $table->unsignedInteger('free_spins_count')->default(10); // legacy slotFreeCount (fixed grant)
            $table->json('free_spins_table')->nullable();             // legacy slotFreeCount array — grant per scatter count
            $table->unsignedInteger('free_spins_multiplier')->default(1); // legacy slotFreeMpl
            $table->boolean('has_gamble')->default(true);             // legacy slotGamble
            $table->unsignedTinyInteger('gamble_type')->default(1);   // legacy GambleType
            $table->unsignedInteger('gamble_win_chance')->default(4); // legacy rezerv default
            $table->boolean('split_screen')->default(false);
            $table->string('volatility')->default('medium');          // App\Enums\Volatility
            $table->unsignedInteger('rtp_control_window')->default(200); // legacy RtpControlCount

            $table->json('paytable')->nullable();      // { "0":[0,0,0,5,20,100], … } × betline
            $table->json('reel_strips')->nullable();   // { "reelStrip1":[…], "reelStripBonus1":[…] }
            $table->json('paylines')->nullable();      // [[1,1,1,1,1], …] row per reel
            $table->json('win_chances')->nullable();   // legacy lines_percent_config (spin/bonus × lineN × rtpBand)
            $table->json('win_distribution')->nullable(); // win-size curve override (defaults from Volatility)
            $table->json('rtp_control')->nullable();   // feedback-loop knobs (cold chances, correction cap)
            $table->json('layout')->nullable();        // client reel positions / key map / sounds / view config

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_templates');
    }
};
