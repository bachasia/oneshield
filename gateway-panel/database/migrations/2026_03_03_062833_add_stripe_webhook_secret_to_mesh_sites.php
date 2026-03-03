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
        Schema::table('mesh_sites', function (Blueprint $table) {
            // Stripe webhook endpoint secret (whsec_...) for signature verification
            $table->text('stripe_webhook_secret')->nullable()->after('stripe_mode');

            // Circuit breaker: track when site was auto-disabled for auto-reset logic
            $table->timestamp('disabled_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('mesh_sites', function (Blueprint $table) {
            $table->dropColumn(['stripe_webhook_secret', 'disabled_at']);
        });
    }
};
