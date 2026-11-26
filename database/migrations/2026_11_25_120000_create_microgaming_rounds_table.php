<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Microgaming outcome library: whole rounds (the paid Spin and the
        // BonusPick / FreeSpin / … packets that follow it) recorded on a Betboy
        // demo session (`microgaming:sample`) at one chip of the smallest size.
        // A spin on our side serves one of them — its exact XML replies, money
        // attributes rescaled to the player's chip size and coins.
        Schema::create('microgaming_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 64);                   // Betboy game id, e.g. thunderstruckii_mg_html
            $table->unsignedInteger('bet_cents');            // stake recorded at, cents
            $table->unsignedInteger('win_cents');            // round total, cents
            $table->boolean('feature')->default(false);      // more than the paid spin
            $table->bigInteger('start_credit');              // recorded balance (cents) before the stake
            $table->json('steps');                           // [{q: verbex, p: request attributes, r: reply payload (XML)}, …]
            $table->timestamp('created_at')->nullable();
            $table->index(['game_key', 'win_cents']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microgaming_rounds');
    }
};
