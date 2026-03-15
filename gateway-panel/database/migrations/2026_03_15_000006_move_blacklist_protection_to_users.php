<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add blacklist_action and trap_shield_id to users (global/account-level)
        Schema::table('users', function (Blueprint $table) {
            $table->string('blacklist_action')->default('hide')->after('use_system_blacklist');
            $table->unsignedBigInteger('trap_shield_id')->nullable()->after('blacklist_action');
        });

        // Remove from shield_sites (no longer per-site)
        Schema::table('shield_sites', function (Blueprint $table) {
            $table->dropIndex(['trap_shield_id']);
            $table->dropColumn(['blacklist_action', 'trap_shield_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['blacklist_action', 'trap_shield_id']);
        });

        Schema::table('shield_sites', function (Blueprint $table) {
            $table->string('blacklist_action')->default('hide')->after('sort_order');
            $table->unsignedBigInteger('trap_shield_id')->nullable()->index()->after('blacklist_action');
        });
    }
};
