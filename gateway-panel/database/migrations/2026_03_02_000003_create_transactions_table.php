<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('mesh_sites')->onDelete('restrict');
            $table->string('order_id')->comment('WooCommerce order ID from money site');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('USD');
            $table->enum('gateway', ['paypal', 'stripe', 'airwallex']);
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])->default('pending');
            $table->string('gateway_transaction_id')->nullable()->comment('PayPal/Stripe transaction ID');
            $table->string('money_site_domain')->comment('Domain of the money site that initiated this transaction');
            $table->json('raw_response')->nullable()->comment('Full raw webhook/IPN payload');
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('money_site_domain');
            $table->index('gateway_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
