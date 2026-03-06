<?php

namespace Tests\Feature;

use App\Models\ShieldSite;
use App\Models\SiteGroup;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\OneShieldTestHelpers;
use Tests\TestCase;

/**
 * Tests for Paygates Plugin API.
 *
 * Endpoints:
 *   POST /api/paygates/get-site
 *   POST /api/paygates/confirm
 *   GET  /api/paygates/iframe-url
 */
class PaygatesApiTest extends TestCase
{
    use RefreshDatabase, OneShieldTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHmac();
    }

    // =========================================================================
    // POST /api/paygates/get-site
    // =========================================================================

    /** @test */
    public function get_site_returns_iframe_url_and_transaction_id(): void
    {
        $user = $this->createUser();
        $this->createShieldSite($user);

        $payload = [
            'gateway'  => 'stripe',
            'order_id' => 'ORDER-001',
            'amount'   => 99.99,
            'currency' => 'USD',
        ];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $response = $this->postJson('/api/paygates/get-site', $payload, $headers);

        $response->assertStatus(200)
                 ->assertJsonStructure(['site_id', 'transaction_id', 'iframe_url', 'token']);

        // A pending transaction should be recorded
        $this->assertDatabaseHas('transactions', [
            'order_id' => 'ORDER-001',
            'gateway'  => 'stripe',
            'status'   => 'pending',
            'amount'   => 99.99,
            'currency' => 'USD',
        ]);
    }

    /** @test */
    public function get_site_iframe_url_contains_gateway_and_order_id(): void
    {
        $user = $this->createUser();
        $this->createShieldSite($user);

        $payload = [
            'gateway'  => 'paypal',
            'order_id' => 'ORD-123',
            'amount'   => 50.00,
            'currency' => 'EUR',
        ];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $response = $this->postJson('/api/paygates/get-site', $payload, $headers);

        $iframeUrl = $response->json('iframe_url');
        $this->assertStringContainsString('os-checkout=1', $iframeUrl);
        $this->assertStringContainsString('gateway=paypal', $iframeUrl);
        $this->assertStringContainsString('order_id=ORD-123', $iframeUrl);
        $this->assertStringContainsString('token=', $iframeUrl);
    }

    /** @test */
    public function get_site_returns_503_when_no_active_site_available(): void
    {
        $user = $this->createUser();
        // Site is inactive
        $this->createShieldSite($user, ['is_active' => false]);

        $payload = [
            'gateway'  => 'stripe',
            'order_id' => 'ORD-999',
            'amount'   => 10.00,
            'currency' => 'USD',
        ];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $this->postJson('/api/paygates/get-site', $payload, $headers)->assertStatus(503);
    }

    /** @test */
    public function get_site_returns_503_when_site_does_not_support_requested_gateway(): void
    {
        $user = $this->createUser();
        // Stripe-only site, no PayPal keys
        $this->createShieldSite($user, [
            'paypal_client_id' => null,
            'paypal_secret'    => null,
        ]);

        $payload = [
            'gateway'  => 'paypal',
            'order_id' => 'ORD-PP',
            'amount'   => 25.00,
            'currency' => 'USD',
        ];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $this->postJson('/api/paygates/get-site', $payload, $headers)->assertStatus(503);
    }

    /** @test */
    public function get_site_respects_group_id_filter(): void
    {
        $user   = $this->createUser();
        $groupA = SiteGroup::create(['user_id' => $user->id, 'name' => 'Group A']);
        $groupB = SiteGroup::create(['user_id' => $user->id, 'name' => 'Group B']);

        // One site in Group A, one in Group B
        $siteA = $this->createShieldSite($user, ['group_id' => $groupA->id]);
        $siteB = $this->createShieldSite($user, [
            'url'      => 'https://site-b.example.com',
            'group_id' => $groupB->id,
        ]);

        $payload = [
            'gateway'  => 'stripe',
            'order_id' => 'ORD-GROUP',
            'amount'   => 10.00,
            'currency' => 'USD',
            'group_id' => $groupA->id,
        ];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $response = $this->postJson('/api/paygates/get-site', $payload, $headers);

        $response->assertStatus(200);
        // The site returned must be siteA (from group A)
        $this->assertEquals($siteA->id, $response->json('site_id'));
    }

    /** @test */
    public function get_site_validates_required_fields(): void
    {
        $user    = $this->createUser();
        $payload = [];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $this->postJson('/api/paygates/get-site', $payload, $headers)
             ->assertStatus(422)
             ->assertJsonValidationErrors(['gateway', 'order_id', 'amount', 'currency']);
    }

    /** @test */
    public function get_site_rejects_invalid_gateway(): void
    {
        $user    = $this->createUser();
        $payload = [
            'gateway'  => 'bitcoin',
            'order_id' => 'ORD-X',
            'amount'   => 1.00,
            'currency' => 'USD',
        ];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $this->postJson('/api/paygates/get-site', $payload, $headers)
             ->assertStatus(422)
             ->assertJsonValidationErrors(['gateway']);
    }

    // =========================================================================
    // POST /api/paygates/confirm
    // =========================================================================

    /** @test */
    public function confirm_marks_transaction_as_completed(): void
    {
        $user        = $this->createUser();
        $site        = $this->createShieldSite($user);
        $transaction = Transaction::create([
            'site_id'           => $site->id,
            'order_id'          => 'ORD-CONFIRM',
            'amount'            => 50.00,
            'currency'          => 'USD',
            'gateway'           => 'stripe',
            'status'            => 'pending',
            'money_site_domain' => 'money.example.com',
        ]);

        $payload = [
            'site_id'                => $site->id,
            'order_id'               => 'ORD-CONFIRM',
            'gateway_transaction_id' => 'ch_test_12345',
            'status'                 => 'completed',
        ];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $response = $this->postJson('/api/paygates/confirm', $payload, $headers);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('transactions', [
            'id'                     => $transaction->id,
            'status'                 => 'completed',
            'gateway_transaction_id' => 'ch_test_12345',
        ]);
    }

    /** @test */
    public function confirm_marks_transaction_as_failed(): void
    {
        $user        = $this->createUser();
        $site        = $this->createShieldSite($user);
        Transaction::create([
            'site_id'           => $site->id,
            'order_id'          => 'ORD-FAIL',
            'amount'            => 10.00,
            'currency'          => 'USD',
            'gateway'           => 'paypal',
            'status'            => 'pending',
            'money_site_domain' => 'money.example.com',
        ]);

        $payload = [
            'site_id'                => $site->id,
            'order_id'               => 'ORD-FAIL',
            'gateway_transaction_id' => 'paypal-txn-xxx',
            'status'                 => 'failed',
        ];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $this->postJson('/api/paygates/confirm', $payload, $headers)
             ->assertStatus(200)
             ->assertJsonPath('status', 'failed');
    }

    /** @test */
    public function confirm_returns_404_for_unknown_order(): void
    {
        $user    = $this->createUser();
        $site    = $this->createShieldSite($user);
        $payload = [
            'site_id'                => $site->id,
            'order_id'               => 'NON-EXISTENT',
            'gateway_transaction_id' => 'ch_xxx',
            'status'                 => 'completed',
        ];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $this->postJson('/api/paygates/confirm', $payload, $headers)->assertStatus(404);
    }

    /** @test */
    public function confirm_cannot_update_already_completed_transaction(): void
    {
        $user = $this->createUser();
        $site = $this->createShieldSite($user);
        Transaction::create([
            'site_id'                => $site->id,
            'order_id'               => 'ORD-DONE',
            'amount'                 => 10.00,
            'currency'               => 'USD',
            'gateway'                => 'stripe',
            'status'                 => 'completed', // already done
            'money_site_domain'      => 'money.example.com',
            'gateway_transaction_id' => 'ch_original',
        ]);

        $payload = [
            'site_id'                => $site->id,
            'order_id'               => 'ORD-DONE',
            'gateway_transaction_id' => 'ch_duplicate',
            'status'                 => 'completed',
        ];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        // Should 404 because query filters status=pending
        $this->postJson('/api/paygates/confirm', $payload, $headers)->assertStatus(404);
    }

    // =========================================================================
    // GET /api/paygates/iframe-url
    // =========================================================================

    /** @test */
    public function iframe_url_returns_url_for_active_site(): void
    {
        $user = $this->createUser();
        $this->createShieldSite($user);

        $payload = [
            'gateway'  => 'stripe',
            'order_id' => 'ORD-IFRAME',
        ];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $response = $this->getJson('/api/paygates/iframe-url?' . http_build_query($payload), $headers);

        $response->assertStatus(200)
                 ->assertJsonStructure(['iframe_url']);

        $this->assertStringContainsString('os-checkout=1', $response->json('iframe_url'));
    }

    /** @test */
    public function iframe_url_returns_503_when_no_site_available(): void
    {
        $user = $this->createUser();
        // No sites

        $payload = ['gateway' => 'stripe', 'order_id' => 'ORD-NONE'];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $this->getJson('/api/paygates/iframe-url?' . http_build_query($payload), $headers)
             ->assertStatus(503);
    }
}
