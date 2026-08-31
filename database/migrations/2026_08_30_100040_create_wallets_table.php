<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A player's money.  ← the balance / bonus / count_* columns of w_users.
 *
 * Kept deliberately flat (explicit columns, not a bonus_wallets child table)
 * so the phase-2 port of User::addBalance() and the wagering engine stays
 * mechanical. Normalising is a fast-follow once behaviour is test-locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('currency', 4)->default('EUR');   // follows users.currency

            $table->decimal('balance', 20, 4)->default(0);          // real money

            // Bonus balances (legacy: tournaments, happyhours, refunds, progress,
            // daily_entries, invite, welcomebonus, smsbonus, wheelfortune)
            $table->decimal('bonus_tournaments', 20, 4)->default(0);
            $table->decimal('bonus_happy_hours', 20, 4)->default(0);
            $table->decimal('bonus_refunds', 20, 4)->default(0);
            $table->decimal('bonus_progress', 20, 4)->default(0);
            $table->decimal('bonus_daily', 20, 4)->default(0);
            $table->decimal('bonus_invite', 20, 4)->default(0);
            $table->decimal('bonus_welcome', 20, 4)->default(0);
            $table->decimal('bonus_sms', 20, 4)->default(0);
            $table->decimal('bonus_wheel', 20, 4)->default(0);

            // Wagering still required before the matching bucket unlocks
            // (legacy count_balance + count_*)
            $table->decimal('wager_total', 20, 4)->default(0);
            $table->decimal('wager_tournaments', 20, 4)->default(0);
            $table->decimal('wager_happy_hours', 20, 4)->default(0);
            $table->decimal('wager_refunds', 20, 4)->default(0);
            $table->decimal('wager_progress', 20, 4)->default(0);
            $table->decimal('wager_daily', 20, 4)->default(0);
            $table->decimal('wager_invite', 20, 4)->default(0);
            $table->decimal('wager_welcome', 20, 4)->default(0);
            $table->decimal('wager_sms', 20, 4)->default(0);
            $table->decimal('wager_wheel', 20, 4)->default(0);

            $table->decimal('locked', 20, 4)->default(0);           // legacy address
            $table->decimal('total_deposited', 20, 4)->default(0);  // legacy total_in
            $table->decimal('total_withdrawn', 20, 4)->default(0);  // legacy total_out

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
