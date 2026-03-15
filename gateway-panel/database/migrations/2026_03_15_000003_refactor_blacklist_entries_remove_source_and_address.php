<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove all address-type entries (pgprints-imported, no longer supported)
        DB::table('blacklist_entries')->where('type', 'address')->delete();

        // Drop the source column (no longer needed)
        Schema::table('blacklist_entries', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }

    public function down(): void
    {
        Schema::table('blacklist_entries', function (Blueprint $table) {
            $table->string('source')->default('custom')->after('value');
        });
    }
};
