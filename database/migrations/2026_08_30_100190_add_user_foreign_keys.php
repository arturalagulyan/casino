<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deferred FKs on `users` — added here because users <-> shops is circular
 * and roles are created after the users table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('shop_id')->references('id')->on('shops')->nullOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
            $table->foreign('parent_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('inviter_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['shop_id']);
            $table->dropForeign(['role_id']);
            $table->dropForeign(['parent_id']);
            $table->dropForeign(['inviter_id']);
        });
    }
};
