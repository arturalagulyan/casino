<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Booongo outcome library: whole rounds (a spin and the free spins / bonus
        // respins it awards) recorded on a Betboy demo session (`booongo:sample`) at the
        // game's smallest line bet. A spin on our side serves one of them — its
        // exact replies, money fields rescaled to the player's stake.
        Schema::create('booongo_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 64);                   // Betboy game id, e.g. book_of_sun_bng_html
            $table->unsignedInteger('bet_cents');            // stake recorded at, cents (line bet × bet_factor)
            $table->unsignedInteger('win_cents');            // round total, cents
            $table->boolean('feature')->default(false);      // free spins / a bonus came up
            $table->bigInteger('start_credit');              // recorded credit (cents) before the stake
            $table->json('steps');                           // [{context, balance}, …] — one `play` reply per action
            $table->timestamp('created_at')->nullable();
            $table->index(['game_key', 'win_cents']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booongo_rounds');
    }
};
