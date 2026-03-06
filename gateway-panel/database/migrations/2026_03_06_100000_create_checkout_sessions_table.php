<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Ownership
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('shield_sites')->cascadeOnDelete();

            // Gateway
            $table->string('gateway', 20); // stripe | paypal | airwallex

            // Order reference from money site
            $table->string('order_ref', 255);

            // Amount
            $table->unsignedBigInteger('amount_minor');   // in smallest currency unit (cents)
            $table->string('currency', 3);                 // ISO 4217 lowercase, e.g. usd
            $table->string('amount_display', 20);          // e.g. "19.99"

            // Payment options
            $table->string('mode', 10)->default('live');              // live | test
            $table->string('capture_method', 20)->default('automatic'); // automatic | manual
            $table->boolean('enable_wallets')->default(true);
            $table->string('descriptor', 22)->nullable();
            $table->string('description_format', 255)->nullable();

            // Billing (encrypted JSON)
            $table->text('billing_snapshot')->nullable(); // encrypted JSON

            // Linked transaction (set when processing starts)
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            // Idempotency
            $table->string('idempotency_key', 100)->unique()->nullable();

            // Status lifecycle: created → processing → completed | expired | cancelled
            $table->string('status', 20)->default('created');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();

            // Result references (set when completed)
            $table->string('stripe_payment_intent_id', 100)->nullable();
            $table->string('gateway_transaction_id', 100)->nullable();

            // Misc metadata (JSON)
            $table->json('meta')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['site_id', 'status']);
            $table->index('expires_at');
            $table->index('order_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_sessions');
    }
};
