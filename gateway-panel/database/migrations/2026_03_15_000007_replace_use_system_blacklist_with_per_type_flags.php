<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('use_system_blacklist');

            $table->boolean('use_system_blacklist_emails')->default(false)->after('cors_origins');
            $table->boolean('use_system_blacklist_cities')->default(false)->after('use_system_blacklist_emails');
            $table->boolean('use_system_blacklist_states')->default(false)->after('use_system_blacklist_cities');
            $table->boolean('use_system_blacklist_zipcodes')->default(false)->after('use_system_blacklist_states');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'use_system_blacklist_emails',
                'use_system_blacklist_cities',
                'use_system_blacklist_states',
                'use_system_blacklist_zipcodes',
            ]);

            $table->boolean('use_system_blacklist')->default(false)->after('cors_origins');
        });
    }
};
