<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Encrypted billing details from the money site (name, email, address).
            // Stored encrypted at rest; only readable by the shield site via
            // the /api/connect/billing/{transaction_id} endpoint (requires site_key auth).
            $table->text('billing_data')->nullable()->after('raw_response')
                ->comment('AES-encrypted billing details JSON');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('billing_data');
        });
    }
};
