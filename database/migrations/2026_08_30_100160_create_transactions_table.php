<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The money ledger — every balance movement for any account.
 * ← w_statistics, with w_statistics_add folded into the `accounting` json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();          // whose balance moved
            $table->foreignId('counterparty_id')->nullable()->constrained('users')->nullOnDelete(); // legacy payeer_id

            $table->string('direction');    // App\Enums\TxnDirection (legacy type)
            $table->string('source');       // App\Enums\TxnSource     (legacy system)
            $table->string('currency', 4)->default('EUR');  // App\Enums\Currency — a shop can hold several

            $table->decimal('amount', 20, 4);
            $table->decimal('balance_before', 20, 4)->default(0);     // legacy old
            $table->decimal('secondary_amount', 20, 4)->nullable();   // legacy sum2
            $table->unsignedInteger('multiplier')->default(1);        // legacy hh_multiplier

            $table->nullableMorphs('reference');   // legacy item_id
            $table->string('title')->nullable();
            $table->unsignedTinyInteger('status')->default(1);

            $table->json('context')->nullable();     // ip/ua/geo/device
            $table->json('accounting')->nullable();  // legacy w_statistics_add

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['shop_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['counterparty_id', 'created_at']);
            $table->index('source');
            $table->index(['shop_id', 'currency', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
