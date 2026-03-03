<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = Schema::hasTable('shield_sites') ? 'shield_sites' : 'mesh_sites';

        if (!Schema::hasColumn($table_name, 'disabled_at')) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->timestamp('disabled_at')->nullable()->after('failure_count')
                      ->comment('Timestamp when circuit breaker disabled this site; null = not disabled');
            });
        }
    }

    public function down(): void
    {
        $table_name = Schema::hasTable('shield_sites') ? 'shield_sites' : 'mesh_sites';

        Schema::table($table_name, function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
