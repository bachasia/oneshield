<?php

namespace Tests\Feature;

use App\Models\MeshSite;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\OneShieldTestHelpers;
use Tests\TestCase;

/**
 * Tests for Webhook / IPN handlers.
 *
 * Endpoints:
 *   POST /api/webhook/stripe/{site_id}
 *   POST /api/webhook/paypal/{site_id}
 *   GET  /api/health
 *
 * Note: These endpoints are NOT HMAC-authenticated (they come from Stripe/PayPal).
 */
class WebhookHandlerTest extends TestCase
{
    use RefreshDatabase, OneShieldTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHmac();
        Http::preventStrayRequests(); // safety: all HTTP calls must be faked
    }

    // =========================================================================
    // GET /api/health
    // =========================================================================

    /** @test */
    public function health_check_returns_ok(): void
    {
        $this->getJson('/api/health')
             ->assertStatus(200)
             ->assertJsonFragment(['status' => 'ok'])
             ->assertJsonStructure(['status', 'timestamp']);
    }

    // =========================================================================
    // POST /api/webhook/stripe/{site_id}
    // =========================================================================

    /** @test */
    public function stripe_webhook_processes_payment_intent_succeeded(): void
    {
        $user        = $this->createUser();
        $site        = $this->createMeshSite($user);
        $transaction = Transaction::create([
            'site_id'           => $site->id,
            'order_id'          => 'WC-101',
            'amount'            => 99.00,
            'currency'          => 'USD',
            'gateway'           => 'stripe',
            'status'            => 'pending',
            'money_site_domain' => 'shop.example.com',
        ]);

        $payload = $this->buildStripeEvent('payment_intent.succeeded', 'pi_test_xxx', 'WC-101');

        $this->postJson("/api/webhook/stripe/{$site->id}", $payload, [
            'Content-Type' => 'application/json',
        ])->assertStatus(200)->assertJsonFragment(['status' => 'received']);

        $this->assertDatabaseHas('transactions', [
            'id'     => $transaction->id,
            'status' => 'completed',
            'gateway_transaction_id' => 'pi_test_xxx',
        ]);
    }

    /** @test */
    public function stripe_webhook_processes_charge_succeeded(): void
    {
        $user = $this->createUser();
        $site = $this->createMeshSite($user);
        $tx   = Transaction::create([
            'site_id'           => $site->id,
            'order_id'          => 'WC-102',
            'amount'            => 10.00,
            'currency'          => 'USD',
            'gateway'           => 'stripe',
            'status'            => 'pending',
            'money_site_domain' => 'shop.example.com',
        ]);

        $payload = $this->buildStripeEvent('charge.succeeded', 'ch_test_abc', 'WC-102');

        $this->postJson("/api/webhook/stripe/{$site->id}", $payload)->assertStatus(200);

        $this->assertDatabaseHas('transactions', [
            'id'     => $tx->id,
            'status' => 'completed',
        ]);
    }

    /** @test */
    public function stripe_webhook_processes_payment_intent_failed(): void
    {
        $user = $this->createUser();
        $site = $this->createMeshSite($user);
        $tx   = Transaction::create([
            'site_id'           => $site->id,
            'order_id'          => 'WC-103',
            'amount'            => 25.00,
            'currency'          => 'USD',
            'gateway'           => 'stripe',
            'status'            => 'pending',
            'money_site_domain' => 'shop.example.com',
        ]);

        $payload = $this->buildStripeEvent('payment_intent.payment_failed', 'pi_failed_xxx', 'WC-103');

        $this->postJson("/api/webhook/stripe/{$site->id}", $payload)->assertStatus(200);

        $this->assertDatabaseHas('transactions', [
            'id'     => $tx->id,
            'status' => 'failed',
        ]);
    }

    /** @test */
    public function stripe_webhook_ignores_unknown_order_id(): void
    {
        $user = $this->createUser();
        $site = $this->createMeshSite($user);

        $payload = $this->buildStripeEvent('payment_intent.succeeded', 'pi_no_tx', 'NON-EXISTENT');

        // Should still return 200 (idempotent) but no transaction updated
        $this->postJson("/api/webhook/stripe/{$site->id}", $payload)->assertStatus(200);
    }

    /** @test */
    public function stripe_webhook_with_valid_signature_is_accepted(): void
    {
        $webhookSecret = 'whsec_test_secret_key';
        $user          = $this->createUser();
        $site          = $this->createMeshSite($user, [
            'stripe_webhook_secret' => $webhookSecret,
        ]);
        Transaction::create([
            'site_id'           => $site->id,
            'order_id'          => 'WC-SIG',
            'amount'            => 20.00,
            'currency'          => 'USD',
            'gateway'           => 'stripe',
            'status'            => 'pending',
            'money_site_domain' => 'shop.example.com',
        ]);

        $payload   = json_encode($this->buildStripeEvent('payment_intent.succeeded', 'pi_sig_test', 'WC-SIG'));
        $timestamp = time();
        $sigHeader = $this->buildStripeSignature($payload, $webhookSecret, $timestamp);

        $this->call(
            'POST',
            "/api/webhook/stripe/{$site->id}",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE-SIGNATURE' => $sigHeader],
            $payload
        )->assertStatus(200)->assertJsonFragment(['status' => 'received']);
    }

    /** @test */
    public function stripe_webhook_with_invalid_signature_is_rejected(): void
    {
        $webhookSecret = 'whsec_real_secret';
        $user          = $this->createUser();
        $site          = $this->createMeshSite($user, [
            'stripe_webhook_secret' => $webhookSecret,
        ]);

        $payload   = json_encode($this->buildStripeEvent('payment_intent.succeeded', 'pi_bad_sig', 'WC-BAD'));
        $timestamp = time();
        // Sign with wrong secret
        $sigHeader = $this->buildStripeSignature($payload, 'whsec_wrong_secret', $timestamp);

        $this->call(
            'POST',
            "/api/webhook/stripe/{$site->id}",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE-SIGNATURE' => $sigHeader],
            $payload
        )->assertStatus(400)->assertJsonFragment(['error' => 'Invalid signature']);
    }

    /** @test */
    public function stripe_webhook_with_stale_signature_timestamp_is_rejected(): void
    {
        $webhookSecret = 'whsec_stale_test';
        $user          = $this->createUser();
        $site          = $this->createMeshSite($user, [
            'stripe_webhook_secret' => $webhookSecret,
        ]);

        $payload   = json_encode($this->buildStripeEvent('payment_intent.succeeded', 'pi_stale', 'WC-STALE'));
        $timestamp = time() - 600; // 10 minutes ago
        $sigHeader = $this->buildStripeSignature($payload, $webhookSecret, $timestamp);

        $this->call(
            'POST',
            "/api/webhook/stripe/{$site->id}",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE-SIGNATURE' => $sigHeader],
            $payload
        )->assertStatus(400);
    }

    // =========================================================================
    // POST /api/webhook/paypal/{site_id}
    // =========================================================================

    /** @test */
    public function paypal_webhook_processes_completed_payment(): void
    {
        $user = $this->createUser();
        $site = $this->createMeshSite($user);
        $tx   = Transaction::create([
            'site_id'           => $site->id,
            'order_id'          => 'WC-PP-001',
            'amount'            => 45.00,
            'currency'          => 'USD',
            'gateway'           => 'paypal',
            'status'            => 'pending',
            'money_site_domain' => 'shop.example.com',
        ]);

        // Mock PayPal IPN verification to return VERIFIED
        Http::fake([
            'ipnpb.sandbox.paypal.com/*' => Http::response('VERIFIED', 200),
        ]);

        $payload = [
            'txn_id'         => 'PAYPAL-TXN-123',
            'payment_status' => 'Completed',
            'invoice'        => 'WC-PP-001',
            'mc_gross'       => '45.00',
        ];

        $this->postJson("/api/webhook/paypal/{$site->id}", $payload)->assertStatus(200)
             ->assertJsonFragment(['status' => 'received']);

        $this->assertDatabaseHas('transactions', [
            'id'                     => $tx->id,
            'status'                 => 'completed',
            'gateway_transaction_id' => 'PAYPAL-TXN-123',
        ]);
    }

    /** @test */
    public function paypal_webhook_processes_refunded_payment(): void
    {
        $user = $this->createUser();
        $site = $this->createMeshSite($user);
        $tx   = Transaction::create([
            'site_id'           => $site->id,
            'order_id'          => 'WC-PP-REF',
            'amount'            => 30.00,
            'currency'          => 'USD',
            'gateway'           => 'paypal',
            'status'            => 'pending',
            'money_site_domain' => 'shop.example.com',
        ]);

        Http::fake([
            'ipnpb.sandbox.paypal.com/*' => Http::response('VERIFIED', 200),
        ]);

        $payload = [
            'txn_id'         => 'PAYPAL-REF-456',
            'payment_status' => 'Refunded',
            'invoice'        => 'WC-PP-REF',
        ];

        $this->postJson("/api/webhook/paypal/{$site->id}", $payload)->assertStatus(200);

        $this->assertDatabaseHas('transactions', [
            'id'     => $tx->id,
            'status' => 'refunded',
        ]);
    }

    /** @test */
    public function paypal_webhook_returns_invalid_when_ipn_verification_fails(): void
    {
        $user = $this->createUser();
        $site = $this->createMeshSite($user);

        // PayPal returns INVALID
        Http::fake([
            'ipnpb.sandbox.paypal.com/*' => Http::response('INVALID', 200),
        ]);

        $payload = [
            'txn_id'         => 'PAYPAL-FAKE',
            'payment_status' => 'Completed',
            'invoice'        => 'WC-FAKE',
        ];

        $this->postJson("/api/webhook/paypal/{$site->id}", $payload)
             ->assertStatus(200)
             ->assertJsonFragment(['status' => 'invalid']);
    }

    /** @test */
    public function paypal_webhook_returns_404_for_unknown_site(): void
    {
        Http::fake([
            'ipnpb.sandbox.paypal.com/*' => Http::response('VERIFIED', 200),
        ]);

        $this->postJson('/api/webhook/paypal/99999', ['txn_id' => 'X'])
             ->assertStatus(404);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function buildStripeEvent(string $type, string $objectId, string $orderId): array
    {
        return [
            'type' => $type,
            'data' => [
                'object' => [
                    'id'       => $objectId,
                    'metadata' => [
                        'order_id' => $orderId,
                    ],
                ],
            ],
        ];
    }

    /**
     * Build a valid Stripe-Signature header value.
     */
    private function buildStripeSignature(string $payload, string $secret, int $timestamp): string
    {
        $signedPayload = $timestamp . '.' . $payload;
        $sig           = hash_hmac('sha256', $signedPayload, $secret);
        return "t={$timestamp},v1={$sig}";
    }
}
