<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The tenant: one casino site.  ← w_shops */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('frontend');                     // theme folder
            $table->string('currency', 4)->default('EUR');   // App\Enums\Currency
            $table->decimal('balance', 20, 4)->default(0);   // legacy w_shops.balance — shop credit float
            $table->string('status')->default('active');    // App\Enums\ShopStatus

            $table->unsignedTinyInteger('rtp_percent')->default(90);       // legacy percent
            $table->unsignedInteger('max_win_multiplier')->default(1000);  // legacy max_win
            $table->decimal('player_limit', 20, 4)->default(0);            // legacy shop_limit
            $table->string('order_by')->default('az');                     // App\Enums\GameOrder

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->json('allowed_countries')->nullable();
            $table->json('allowed_os')->nullable();
            $table->json('allowed_devices')->nullable();
            $table->json('required_rules')->nullable();      // legacy rules_* flags

            // Feature toggles (legacy *_active)
            $table->boolean('happy_hours_enabled')->default(true);
            $table->boolean('progress_enabled')->default(true);
            $table->boolean('invites_enabled')->default(true);
            $table->boolean('welcome_bonuses_enabled')->default(true);
            $table->boolean('sms_bonuses_enabled')->default(true);
            $table->boolean('wheel_fortune_enabled')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
