<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mesh_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('group_id')->nullable()->constrained('site_groups')->onDelete('set null');
            $table->string('name');
            $table->string('url');
            $table->string('site_key', 64)->unique()->comment('Unique key assigned to this mesh site for authentication');

            // PayPal credentials (AES-256 encrypted)
            $table->text('paypal_client_id')->nullable();
            $table->text('paypal_secret')->nullable();
            $table->enum('paypal_mode', ['sandbox', 'live'])->default('sandbox');

            // Stripe credentials (AES-256 encrypted)
            $table->text('stripe_public_key')->nullable();
            $table->text('stripe_secret_key')->nullable();
            $table->enum('stripe_mode', ['test', 'live'])->default('test');

            // Airwallex (Phase 2)
            $table->text('airwallex_client_id')->nullable();
            $table->text('airwallex_api_key')->nullable();

            // Status & reliability
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('failure_count')->default(0)->comment('Consecutive failures for circuit breaker');
            $table->timestamp('last_heartbeat_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['group_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mesh_sites');
    }
};
