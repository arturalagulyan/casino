<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** External operator endpoints for seamless-wallet integration.  ← w_operators */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operator_ref')->nullable();     // legacy opid
            $table->string('user_check_url')->nullable();   // legacy ucurl
            $table->string('callback_url')->nullable();     // legacy cburl
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operators');
    }
};
