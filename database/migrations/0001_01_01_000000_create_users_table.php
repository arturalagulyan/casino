<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Tenancy & hierarchy. FKs are added in add_user_foreign_keys once
            // roles/shops exist (users <-> shops is circular).
            $table->unsignedBigInteger('shop_id')->nullable()->index();
            $table->unsignedBigInteger('role_id')->nullable()->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->unsignedBigInteger('inviter_id')->nullable()->index();

            // Identity
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('password');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->date('birthday')->nullable();
            $table->string('avatar')->nullable();
            $table->char('language', 5)->default('en');
            $table->string('currency', 4)->nullable();   // App\Enums\Currency
            $table->unsignedInteger('rating')->default(0);

            // State
            $table->string('status')->default('unconfirmed');   // App\Enums\UserStatus
            $table->boolean('is_blocked')->default(false);
            $table->boolean('is_demo_agent')->default(false);
            $table->boolean('free_demo')->default(false);
            $table->timestamp('agreed_at')->nullable();

            // Seamless-wallet / external players
            $table->string('external_provider')->nullable();
            $table->string('external_player_id')->nullable();
            $table->text('external_token')->nullable();

            // Security
            $table->string('two_factor_secret')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->string('current_session_id')->nullable();   // single-session enforcement
            $table->string('confirmation_token', 64)->nullable();
            $table->string('sms_token')->nullable();
            $table->timestamp('sms_token_at')->nullable();

            // Activity clocks (drive bonus cooldowns)
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_online_at')->nullable();
            $table->timestamp('last_bet_at')->nullable();
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('last_daily_entry_at')->nullable();
            $table->timestamp('last_wheel_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shop_id', 'username']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();

            // Legacy w_sessions carried geo/device columns for the "active
            // sessions" admin screen.
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('os')->nullable();
            $table->string('device')->nullable();
            $table->string('browser')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
