<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop-wide liquidity pools that fund wins.
 * ← w_game_bank + w_fish_bank (merged: fish is just another pool).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 4)->default('EUR');   // App\Enums\Currency

            $table->decimal('slots', 20, 4)->default(0);
            $table->decimal('little', 20, 4)->default(0);
            $table->decimal('table_bank', 20, 4)->default(0);
            $table->decimal('bonus', 20, 4)->default(0);
            $table->decimal('fish', 20, 4)->default(0);

            $table->decimal('temp_rtp', 20, 4)->nullable(); // manual RTP override

            $table->timestamps();
            $table->unique(['shop_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_banks');
    }
};
