<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw per-round request/response payload.  ← w_game_log
 *
 * High volume (legacy grew to 7M rows because it was never pruned).
 * A scheduled job deletes rows older than config('casino.game_log_retention_days').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->longText('payload')->nullable();       // legacy str
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['game_id', 'created_at']);
            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_logs');
    }
};
