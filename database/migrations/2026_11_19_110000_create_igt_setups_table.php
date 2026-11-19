<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An IGT game's recorded server setup (`igt:fetch`): its paytable (reel
        // strips and all — tens to hundreds of KB), init and money format. Kept
        // off game_templates: rows that wide make sorted template lists (the
        // admin's) run MySQL out of sort memory.
        Schema::create('igt_setups', function (Blueprint $table) {
            $table->string('game_key', 64)->primary();       // Betboy id, e.g. wolfrun_igt_html
            $table->json('paytable');
            $table->json('init');
            $table->json('currency');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('igt_setups');
    }
};
