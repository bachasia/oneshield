<?php

namespace Tests\Feature;

use App\Models\ShieldSite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\OneShieldTestHelpers;
use Tests\TestCase;

/**
 * Tests for Connect Plugin API.
 *
 * Endpoints:
 *   POST /api/connect/register
 *   POST /api/connect/heartbeat
 *   GET  /api/connect/status/{site_id}
 */
class ConnectApiTest extends TestCase
{
    use RefreshDatabase, OneShieldTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHmac();
    }

    // =========================================================================
    // POST /api/connect/register
    // =========================================================================

    /** @test */
    public function register_creates_a_new_shield_site(): void
    {
        $user    = $this->createUser();
        $payload = [
            'site_url'  => 'https://new-mesh-site.example.com',
            'site_name' => 'My Shield Site',
        ];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $response = $this->postJson('/api/connect/register', $payload, $headers);

        $response->assertStatus(201)
                 ->assertJsonFragment(['status' => 'registered'])
                 ->assertJsonStructure(['site_id', 'site_key', 'status']);

        $this->assertDatabaseHas('shield_sites', [
            'user_id' => $user->id,
            'name'    => 'My Shield Site',
            'url'     => 'https://new-mesh-site.example.com',
        ]);
    }

    /** @test */
    public function register_strips_trailing_slash_from_url(): void
    {
        $user    = $this->createUser();
        $payload = [
            'site_url'  => 'https://new-mesh-site.example.com/',
            'site_name' => 'My Site',
        ];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $this->postJson('/api/connect/register', $payload, $headers)->assertStatus(201);

        $this->assertDatabaseHas('shield_sites', [
            'url' => 'https://new-mesh-site.example.com',
        ]);
    }

    /** @test */
    public function register_returns_already_registered_for_duplicate_url(): void
    {
        $user = $this->createUser();
        $this->createShieldSite($user, ['url' => 'https://existing.example.com']);

        $payload = [
            'site_url'  => 'https://existing.example.com',
            'site_name' => 'Duplicate',
        ];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $response = $this->postJson('/api/connect/register', $payload, $headers);

        $response->assertStatus(200)
                 ->assertJsonFragment(['status' => 'already_registered']);
    }

    /** @test */
    public function register_requires_site_url_and_site_name(): void
    {
        $user    = $this->createUser();
        $payload = [];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $response = $this->postJson('/api/connect/register', $payload, $headers);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['site_url', 'site_name']);
    }

    /** @test */
    public function register_rejects_invalid_url(): void
    {
        $user    = $this->createUser();
        $payload = ['site_url' => 'not-a-url', 'site_name' => 'Bad'];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $this->postJson('/api/connect/register', $payload, $headers)
             ->assertStatus(422)
             ->assertJsonValidationErrors(['site_url']);
    }

    // =========================================================================
    // POST /api/connect/heartbeat
    // =========================================================================

    /** @test */
    public function heartbeat_updates_last_heartbeat_at(): void
    {
        $user = $this->createUser();
        $site = $this->createShieldSite($user, [
            'last_heartbeat_at' => now()->subHour(),
        ]);

        $payload = ['site_id' => $site->id];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $response = $this->postJson('/api/connect/heartbeat', $payload, $headers);

        $response->assertStatus(200)
                 ->assertJsonFragment(['status' => 'ok'])
                 ->assertJsonStructure(['status', 'config']);

        $site->refresh();
        $this->assertGreaterThan(now()->subMinute(), $site->last_heartbeat_at);
    }

    /** @test */
    public function heartbeat_returns_gateway_config(): void
    {
        $user = $this->createUser();
        $site = $this->createShieldSite($user, [
            'stripe_mode' => 'live',
            'paypal_mode' => 'live',
        ]);

        $payload = ['site_id' => $site->id];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $response = $this->postJson('/api/connect/heartbeat', $payload, $headers);

        $response->assertStatus(200)
                 ->assertJsonPath('config.stripe_mode', 'live')
                 ->assertJsonPath('config.paypal_mode', 'live')
                 ->assertJsonPath('config.is_active', true);
    }

    /** @test */
    public function heartbeat_rejects_site_belonging_to_another_user(): void
    {
        $userA  = $this->createUser();
        $userB  = $this->createUser();
        $site   = $this->createShieldSite($userB); // belongs to B

        $payload = ['site_id' => $site->id];
        $headers = $this->hmacHeaders($payload, $userA->token_secret);

        // 404 because site doesn't exist "for userA"
        $this->postJson('/api/connect/heartbeat', $payload, $headers)->assertStatus(404);
    }

    /** @test */
    public function heartbeat_fails_for_nonexistent_site(): void
    {
        $user    = $this->createUser();
        $payload = ['site_id' => 99999];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $this->postJson('/api/connect/heartbeat', $payload, $headers)->assertStatus(404);
    }

    // =========================================================================
    // GET /api/connect/status/{site_id}
    // =========================================================================

    /** @test */
    public function status_returns_site_info_and_gateway_capabilities(): void
    {
        $user = $this->createUser();
        $site = $this->createShieldSite($user);

        $payload = [];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $response = $this->getJson("/api/connect/status/{$site->id}", $headers);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'site' => ['id', 'name', 'url', 'is_active'],
                     'gateways_configured' => ['paypal', 'stripe', 'airwallex'],
                     'modes' => ['paypal', 'stripe'],
                 ])
                 ->assertJsonPath('gateways_configured.stripe', true)
                 ->assertJsonPath('gateways_configured.paypal', true)
                 ->assertJsonPath('gateways_configured.airwallex', false);
    }

    /** @test */
    public function status_returns_404_for_another_users_site(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $site  = $this->createShieldSite($userB);

        $headers = $this->hmacHeaders([], $userA->token_secret);

        $this->getJson("/api/connect/status/{$site->id}", $headers)->assertStatus(404);
    }

    /** @test */
    public function status_shows_site_without_gateway_as_unconfigured(): void
    {
        $user = $this->createUser();
        $site = $this->createShieldSite($user, [
            'stripe_public_key' => null,
            'stripe_secret_key' => null,
            'paypal_client_id'  => null,
            'paypal_secret'     => null,
        ]);

        $headers = $this->hmacHeaders([], $user->token_secret);

        $response = $this->getJson("/api/connect/status/{$site->id}", $headers);

        $response->assertStatus(200)
                 ->assertJsonPath('gateways_configured.stripe', false)
                 ->assertJsonPath('gateways_configured.paypal', false);
    }
}
