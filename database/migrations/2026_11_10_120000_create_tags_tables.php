<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Game tags — themes and merchandising labels (Christmas, Books, Fruits, New…)
 * that describe a game rather than who made it. Categories are kept for
 * providers only; tags attach to the master template, so a game carries the
 * same tags in every shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('image_path')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        Schema::create('game_template_tag', function (Blueprint $table) {
            $table->foreignId('game_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['game_template_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_template_tag');
        Schema::dropIfExists('tags');
    }
};
