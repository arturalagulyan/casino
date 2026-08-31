<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Jackpot pools (per shop; shop_id null = global template).  ← w_jpg */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jackpots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('balance', 20, 6)->default(0);
            $table->decimal('contribution_percent', 5, 2)->default(0);   // legacy percent

            // Legacy stored an index into a PHP range array; store the real range.
            $table->decimal('seed_min', 20, 4)->default(0);
            $table->decimal('seed_max', 20, 4)->default(0);
            $table->decimal('payout_min', 20, 4)->default(0);
            $table->decimal('payout_max', 20, 4)->default(0);

            $table->foreignId('last_winner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_won_at')->nullable();
            $table->decimal('last_won_amount', 20, 4)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jackpots');
    }
};
