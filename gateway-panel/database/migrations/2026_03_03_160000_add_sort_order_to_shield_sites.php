<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = Schema::hasTable('shield_sites') ? 'shield_sites' : 'mesh_sites';

        if (!Schema::hasColumn($table_name, 'sort_order')) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->unsignedInteger('sort_order')->default(0)->after('id')
                      ->comment('Display order for drag-to-reorder; lower = higher in list');
            });
        }

        // Seed sort_order with existing row order (id ASC) so current order is preserved
        DB::statement("UPDATE {$table_name} SET sort_order = id WHERE sort_order = 0");
    }

    public function down(): void
    {
        $table_name = Schema::hasTable('shield_sites') ? 'shield_sites' : 'mesh_sites';

        Schema::table($table_name, function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
