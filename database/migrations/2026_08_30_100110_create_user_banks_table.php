<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RECONSTRUCTED — legacy `w_user_bank` was not present in the casino DB.
 *
 * Optional per-player liquidity pool for individual-RTP control. Mirrors
 * game_banks, keyed per user. When `is_active` is true this player's spins
 * settle against this bank instead of the shop bank.
 *
 * If a real w_user_bank exists elsewhere, send its SHOW CREATE TABLE and this
 * will be reconciled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 4)->default('EUR');   // App\Enums\Currency

            $table->decimal('slots', 20, 4)->default(0);
            $table->decimal('little', 20, 4)->default(0);
            $table->decimal('table_bank', 20, 4)->default(0);
            $table->decimal('bonus', 20, 4)->default(0);
            $table->decimal('fish', 20, 4)->default(0);

            $table->decimal('temp_rtp', 20, 4)->nullable();
            $table->boolean('is_active')->default(false);

            $table->timestamps();
            $table->unique(['user_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_banks');
    }
};
