<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Seamless-wallet credentials per shop.  ← w_apis */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('key')->unique();          // legacy keygen
            $table->string('secret')->nullable();
            $table->json('allowed_ips')->nullable();  // legacy single ip
            $table->string('callback_url')->nullable(); // legacy endpoint
            $table->boolean('is_active')->default(true); // legacy status
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
