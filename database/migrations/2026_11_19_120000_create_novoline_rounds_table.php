<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Novoline (Greentube) outcome library: whole rounds (a spin, the free
        // spins it awards, the collects) recorded on a Betboy demo session
        // (`novoline:sample`) at the smallest line bet, every line. A spin on
        // our side serves one of them — its exact frames, credit fields × the
        // player's line bet ÷ the recorded one.
        Schema::create('novoline_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 64);                   // Betboy id, e.g. bookofradeluxe_gt_html
            $table->unsignedInteger('bet_cents');            // what the round cost, cents
            $table->unsignedInteger('win_cents');            // what it paid, cents
            $table->boolean('feature')->default(false);      // free spins / a bonus came up
            $table->bigInteger('start_credit');              // recorded balance (credits) before the stake
            $table->json('steps');                           // [{c: client frame, r: [server frames]}, …]
            $table->timestamp('created_at')->nullable();
            $table->index(['game_key', 'win_cents']);
        });

        // A game's recorded login reply (its config and init state: reels,
        // paytable, bet options) — kept off game_templates (row width).
        Schema::create('novoline_setups', function (Blueprint $table) {
            $table->string('game_key', 64)->primary();
            $table->json('login');                           // [server frame, …]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('novoline_rounds');
        Schema::dropIfExists('novoline_setups');
    }
};
