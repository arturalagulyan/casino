<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Apollo Games outcome library: whole rounds (a spin and the free spins
        // it awards) recorded on a Betboy demo session (`apollo:sample`) at the
        // game's smallest stake. A spin on our side serves one of them — its
        // exact replies, money fields rescaled to the player's stake.
        Schema::create('apollo_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 64);                   // Apollo game folder, e.g. lucky81_ag
            $table->unsignedInteger('bet_cents');            // stake recorded at, cents
            $table->unsignedInteger('win_cents');            // round total, cents
            $table->boolean('feature')->default(false);      // free spins came up
            $table->bigInteger('start_credit');              // recorded credit (cents) before the stake
            $table->json('steps');                           // [reply, …] — one casino.spin reply each
            $table->timestamp('created_at')->nullable();
            $table->index(['game_key', 'win_cents']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apollo_rounds');
    }
};
