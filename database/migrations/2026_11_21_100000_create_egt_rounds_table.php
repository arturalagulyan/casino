<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // EGT outcome library: whole rounds (a spin and the free spins it
        // awards) recorded on a Betboy demo session (`egt:sample`) at the
        // game's smallest bet. A spin on our side serves one of them — its
        // exact replies, money fields rescaled to the player's bet.
        Schema::create('egt_rounds', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('gin');                  // EGT gameIdentificationNumber, e.g. 801
            $table->unsignedInteger('bet_cents');            // bet recorded at, cents
            $table->unsignedInteger('win_cents');            // round total, cents
            $table->boolean('feature')->default(false);      // free spins / a bonus came up
            $table->bigInteger('start_balance');             // recorded balance (cents) before the bet
            $table->json('steps');                           // [reply, …] — one `bet` reply each
            $table->timestamp('created_at')->nullable();
            $table->index(['gin', 'win_cents']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egt_rounds');
    }
};
