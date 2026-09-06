<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_templates', function (Blueprint $table) {
            // Extra config for the modern Pragmatic Play "gs2c" classic-payline
            // family (PaylineEngine) — the money/coin symbol + its weighted
            // value pool, the mystery-scatter symbol range, and the scatter
            // count -> free-spins/multiplier tables. Doesn't fit tumble_config
            // (different family, different math) or the plain line-slot
            // columns (paytable/reel_strips/paylines are still reused as-is).
            $table->json('payline_config')->nullable()->after('tumble_config');
        });
    }

    public function down(): void
    {
        Schema::table('game_templates', function (Blueprint $table) {
            $table->dropColumn('payline_config');
        });
    }
};
