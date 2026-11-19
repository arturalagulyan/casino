<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // IGT outcome library: whole rounds (a spin and the free spins
        // it awards) recorded on a Betboy demo session (`igt:sample`) at
        // 1 credit (0.01) a line, every line. A spin on our side serves one of
        // them — its exact replies, credit fields × the player's credits a line.
        Schema::create('igt_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 64);                   // Betboy id, e.g. wolfrun_igt_html
            $table->unsignedInteger('bet_cents');            // credits the round cost (= cents at 0.01)
            $table->unsignedInteger('win_cents');            // credits it paid
            $table->boolean('feature')->default(false);      // free spins came up
            $table->bigInteger('start_credit');              // recorded balance (cents) before the stake
            $table->json('steps');                           // [reply, …] — one /play reply each
            $table->timestamp('created_at')->nullable();
            $table->index(['game_key', 'win_cents']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('igt_rounds');
    }
};
