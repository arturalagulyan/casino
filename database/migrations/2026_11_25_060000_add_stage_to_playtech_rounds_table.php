<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Games with a level the player stays on across rounds (Batman Begins: temple, manor, cave …):
        // a round is only served on its own stage; `next_stage` is the stage the recording moved on to after it.
        Schema::table('playtech_rounds', function (Blueprint $table) {
            $table->string('stage', 32)->nullable()->after('start_credit');
            $table->string('next_stage', 32)->nullable()->after('stage');
            $table->index(['game_key', 'stage', 'win_cents']);
        });
    }

    public function down(): void
    {
        Schema::table('playtech_rounds', function (Blueprint $table) {
            $table->dropIndex(['game_key', 'stage', 'win_cents']);
            $table->dropColumn(['stage', 'next_stage']);
        });
    }
};
