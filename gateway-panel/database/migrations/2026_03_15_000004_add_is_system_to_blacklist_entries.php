<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blacklist_entries', function (Blueprint $table) {
            // System entries managed by super admin; customer entries remain false
            $table->boolean('is_system')->default(false)->after('notes');
            $table->unsignedBigInteger('user_id')->nullable()->after('is_system');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('blacklist_entries', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn(['is_system', 'user_id']);
        });
    }
};
