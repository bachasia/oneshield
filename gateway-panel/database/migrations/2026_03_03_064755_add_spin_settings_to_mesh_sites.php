<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesh_sites', function (Blueprint $table) {
            // Per-gateway enable/disable flags
            $table->boolean('paypal_enabled')->default(false)->after('paypal_mode');
            $table->boolean('stripe_enabled')->default(false)->after('stripe_mode');

            // Spin / rotation settings (per site)
            $table->string('receive_cycle', 20)->default('lifetime')->after('stripe_enabled')
                  ->comment('lifetime | monthly | weekly | daily');

            // PayPal spin limits
            $table->decimal('paypal_income_limit', 12, 2)->default(0)->after('receive_cycle')
                  ->comment('0 = unlimited');
            $table->decimal('paypal_max_per_order', 12, 2)->default(0)->after('paypal_income_limit')
                  ->comment('0 = unlimited');

            // Stripe spin limits
            $table->decimal('stripe_income_limit', 12, 2)->default(0)->after('paypal_max_per_order')
                  ->comment('0 = unlimited');
            $table->decimal('stripe_max_per_order', 12, 2)->default(0)->after('stripe_income_limit')
                  ->comment('0 = unlimited');
        });
    }

    public function down(): void
    {
        Schema::table('mesh_sites', function (Blueprint $table) {
            $table->dropColumn([
                'paypal_enabled', 'stripe_enabled', 'receive_cycle',
                'paypal_income_limit', 'paypal_max_per_order',
                'stripe_income_limit', 'stripe_max_per_order',
            ]);
        });
    }
};
