<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-currency play (see App\Services\GamePlay\CurrencyScaler + docs).
 *
 * - game_templates / games get `pricing_currency` — the currency the bet ladder
 *   is authored in; other player currencies scale the denomination by the FX
 *   rate.
 * - jackpots get `currency` — the pool's home currency (null = the shop's).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_templates', function (Blueprint $table) {
            $table->string('pricing_currency', 4)->default('EUR')->after('default_denomination');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->string('pricing_currency', 4)->nullable()->after('denomination'); // null inherits the template
        });

        Schema::table('jackpots', function (Blueprint $table) {
            $table->string('currency', 4)->nullable()->after('name'); // null = shop currency (EUR for a global pool)
        });
    }

    public function down(): void
    {
        Schema::table('game_templates', function (Blueprint $table) {
            $table->dropColumn('pricing_currency');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('pricing_currency');
        });

        Schema::table('jackpots', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
