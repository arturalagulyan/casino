<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wazdan outcome library: whole rounds (the paid START, the free games /
        // respins it awards) recorded on a Betboy demo session (`wazdan:sample`)
        // at the game's smallest stake. A spin on our side serves one of them —
        // its exact replies, money fields rescaled to the player's stake.
        Schema::create('wazdan_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 64);                   // Betboy game id, e.g. sh_wzdn
            $table->unsignedInteger('bet_cents');            // stake recorded at, cents
            $table->unsignedInteger('win_cents');            // round total, cents
            $table->boolean('feature')->default(false);      // free games / respins came up
            $table->bigInteger('start_credit');              // recorded credit (cents) before the stake
            $table->json('steps');                           // [START reply `res`, …]
            $table->timestamp('created_at')->nullable();
            $table->index(['game_key', 'win_cents']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wazdan_rounds');
    }
};
