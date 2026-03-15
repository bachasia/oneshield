<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shield_sites', function (Blueprint $table) {
            $table->string('blacklist_action')->default('hide')->after('sort_order'); // 'hide' | 'trap'
            $table->unsignedBigInteger('trap_shield_id')->nullable()->index()->after('blacklist_action');
        });
    }

    public function down(): void
    {
        Schema::table('shield_sites', function (Blueprint $table) {
            $table->dropColumn(['blacklist_action', 'trap_shield_id']);
        });
    }
};
