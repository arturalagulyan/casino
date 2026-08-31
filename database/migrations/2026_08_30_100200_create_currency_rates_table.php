<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FX rates so multi-currency shops can show a single reporting total.
 * `rate` = how many units of `currency` equal one EUR (the base).
 * New — the legacy platform never converted between currencies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 4)->unique();       // App\Enums\Currency
            $table->decimal('rate', 24, 10)->default(1);   // units per 1 EUR
            $table->timestamp('quoted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};
