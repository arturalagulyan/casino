<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin Game Builder drafts: a RoyalSpin game described as data (grid,
        // symbols + pays, features, look & feel, uploaded art). Publishing one
        // builds its front-end bundle and writes the game_templates row it
        // points at; the design stays editable and can be re-published.
        Schema::create('game_designs', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('mechanic', 16)->default('cascade');   // lines | cascade
            $table->string('skin', 32)->default('classic');       // engine skin (layout + frame)
            $table->string('art_pack', 64)->nullable();           // stock art the symbols draw from
            $table->unsignedTinyInteger('reel_count')->default(5);
            $table->unsignedTinyInteger('row_count')->default(3);
            $table->json('symbols')->nullable();
            $table->json('paylines')->nullable();                // custom lines; null = generated
            $table->json('settings')->nullable();
            $table->json('theme')->nullable();
            $table->json('assets')->nullable();                  // uploaded art / music (public disk paths)
            $table->foreignId('template_id')->nullable()->constrained('game_templates')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->string('build_hash', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_designs');
    }
};
