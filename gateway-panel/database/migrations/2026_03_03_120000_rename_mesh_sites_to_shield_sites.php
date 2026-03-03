<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mesh_sites') || Schema::hasTable('shield_sites')) {
            return;
        }

        Schema::rename('mesh_sites', 'shield_sites');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('site_id')->references('id')->on('shield_sites')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shield_sites') || Schema::hasTable('mesh_sites')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
        });

        Schema::rename('shield_sites', 'mesh_sites');

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('site_id')->references('id')->on('mesh_sites')->onDelete('restrict');
        });
    }
};
