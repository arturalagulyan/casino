<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Merkur (Edict) outcome library: whole rounds (a PLAY, the free games
        // it awards, the FINISH_GAME collecting its win) recorded on a Betboy
        // demo session (`merkur:sample`) at the game's smallest coin. A spin on
        // our side serves one of them — its exact replies, money rescaled.
        Schema::create('merkur_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 64);                   // Betboy id, e.g. multifruits_mk_html
            $table->unsignedInteger('bet_cents');            // total stake recorded at, cents
            $table->unsignedInteger('win_cents');            // round total, cents
            $table->boolean('feature')->default(false);      // free games (or another feature) came up
            $table->bigInteger('start_credit');              // recorded depot (cents) before the stake
            $table->json('steps');                           // [{action, core, game}, …]
            $table->timestamp('created_at')->nullable();
            $table->index(['game_key', 'win_cents']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merkur_rounds');
    }
};
