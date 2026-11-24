<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pragmatic Play outcome library: whole rounds (the paid doSpin and the
        // tumbles / free spins / bonus steps / collects that follow it) recorded on
        // a Betboy demo session (`pragmatic:sample`) at the game's smallest coin.
        // A spin on our side serves one of them — its exact replies, money fields
        // rescaled to the player's coin.
        Schema::create('pragmatic_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 64);                   // Betboy game id, e.g. vs20fruitsw_prg
            $table->unsignedInteger('bet_cents');            // stake recorded at, cents (coin × lines)
            $table->unsignedInteger('win_cents');            // round total, cents
            $table->boolean('feature')->default(false);      // more than a spin (+ its collect)
            $table->bigInteger('start_credit');              // recorded credit (cents) before the stake
            $table->json('steps');                           // [{a: action, p: params, r: reply (raw key=value)}, …]
            $table->timestamp('created_at')->nullable();
            $table->index(['game_key', 'win_cents']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pragmatic_rounds');
    }
};
