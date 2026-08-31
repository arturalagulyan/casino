<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** History of jackpot hits (legacy only kept w_jpg.last_winner + a statistics row). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jackpot_wins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jackpot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('round_id')->nullable()->constrained('game_rounds')->nullOnDelete();

            $table->decimal('amount', 20, 4);
            $table->decimal('balance_before', 20, 6)->default(0); // jackpot pool before payout
            $table->timestamp('won_at')->useCurrent();
            $table->timestamps();

            $table->index(['shop_id', 'won_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jackpot_wins');
    }
};
