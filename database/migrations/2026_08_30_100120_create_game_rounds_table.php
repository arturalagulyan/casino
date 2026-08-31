<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per spin — the financial record.  ← w_stat_game (append-only). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_rounds', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->string('game_code');                  // legacy join key / fallback
            $table->string('currency', 4)->default('EUR')->index();   // App\Enums\Currency

            $table->decimal('bet', 20, 4)->default(0);
            $table->decimal('win', 20, 4)->default(0);
            $table->decimal('balance_after', 20, 4)->default(0);

            // How the stake was split (legacy in_game / in_jpg / in_profit)
            $table->decimal('stake_to_bank', 20, 4)->default(0);
            $table->decimal('stake_to_jackpot', 20, 4)->default(0);
            $table->decimal('stake_to_profit', 20, 4)->default(0);

            $table->decimal('denomination', 20, 4)->default(0);
            $table->json('bank_snapshot')->nullable();    // legacy *_bank columns
            $table->unsignedTinyInteger('status')->default(0);

            $table->timestamp('played_at')->useCurrent();

            $table->index(['shop_id', 'played_at']);
            $table->index(['user_id', 'played_at']);
            $table->index(['game_id', 'played_at']);
            $table->index(['game_code', 'played_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_rounds');
    }
};
