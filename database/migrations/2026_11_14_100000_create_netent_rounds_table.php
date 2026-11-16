<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NetEnt outcome library: whole rounds recorded from a NetEnt demo
        // session (`netent:sample`) at bet level 1, coin value 1 cent. A spin on
        // our side serves one of them — its exact servlet replies, money fields
        // rescaled to the player's level / coin value — so the game's maths and
        // features are NetEnt's own.
        Schema::create('netent_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 64);                   // NetEnt game id, e.g. happy_riches
            $table->unsignedInteger('bet_coins');            // coins staked (lines × level 1)
            $table->unsignedInteger('win_coins');            // round total in coins
            $table->boolean('feature')->default(false);      // free spins / a bonus came up
            $table->bigInteger('start_credit');              // recorded balance (cents) before the stake
            $table->json('steps');                           // [{sent, reply}, …] in order
            $table->timestamp('created_at')->nullable();
            $table->index(['game_key', 'win_coins']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('netent_rounds');
    }
};
