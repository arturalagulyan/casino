<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ← w_game_categories / w_shop_categories */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_game', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['category_id', 'game_id']);
        });

        Schema::create('category_shop', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->unique(['category_id', 'shop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_shop');
        Schema::dropIfExists('category_game');
    }
};
