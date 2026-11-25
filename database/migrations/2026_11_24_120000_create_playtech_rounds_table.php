<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Playtech outcome library: whole rounds (the paid spin, the free spins /
        // respins it awards) recorded on a Betboy demo session (`playtech:sample`)
        // at coin size 1. A spin on our side serves one of them —
        // its exact messages, money fields rescaled to the player's coin.
        Schema::create('playtech_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 64);                   // Betboy game id, e.g. whk_pt
            $table->unsignedInteger('bet_cents');            // stake recorded at, cents
            $table->unsignedInteger('win_cents');            // round total, cents
            $table->boolean('feature')->default(false);      // free spins / respins / a bonus came up
            $table->bigInteger('start_credit');              // recorded credit (cents) before the stake
            $table->json('steps');                           // [{t: spin type, m: [messages]}, …]
            $table->timestamp('created_at')->nullable();
            $table->index(['game_key', 'win_cents']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playtech_rounds');
    }
};
