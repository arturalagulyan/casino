<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Play'n GO outcome library: whole rounds recorded from Play'n GO's own
        // practice server (`playngo:sample`). A spin on our side serves one of
        // them — its exact packets, cash fields rescaled to the player's coin
        // value — so the game's maths and features are Play'n GO's own.
        Schema::create('playngo_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 64);                   // Play'n GO gid, e.g. tomeofmadness
            $table->unsignedInteger('bet_coins');            // coins staked (the client's fixed 10)
            $table->unsignedInteger('win_coins');            // round total in coins
            $table->boolean('feature')->default(false);      // a free round / bonus board came up
            $table->json('steps');                           // [{sent, reply}, …] in order
            $table->timestamp('created_at')->nullable();
            $table->index(['game_key', 'win_coins']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playngo_rounds');
    }
};
