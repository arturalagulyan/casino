<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Game categories, hierarchical, per shop (shop_id null = global).  ← w_categories */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->unsignedBigInteger('template_id')->nullable();   // legacy original_id
            $table->string('title');
            $table->string('slug')->nullable();                      // legacy href
            $table->integer('position')->default(0);
            $table->json('config')->nullable();                      // shared game config the category's games inherit (client_protocol, layout, engine knobs…)
            $table->timestamps();

            $table->index(['shop_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
