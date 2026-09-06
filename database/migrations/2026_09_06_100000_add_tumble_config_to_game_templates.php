<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_templates', function (Blueprint $table) {
            // Extra config for the modern Pragmatic Play "gs2c" scatter-pays /
            // tumble-cascade game family (TumbleEngine) — the multiplier-bomb
            // symbol + its value pool, and the scatter counts that trigger /
            // retrigger free spins. Doesn't fit the existing line-slot columns
            // (paytable/reel_strips/symbols are still reused as-is).
            $table->json('tumble_config')->nullable()->after('rtp_control');
        });
    }

    public function down(): void
    {
        Schema::table('game_templates', function (Blueprint $table) {
            $table->dropColumn('tumble_config');
        });
    }
};
