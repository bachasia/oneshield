<?php

namespace Tests\Feature;

use App\Models\GatewayToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\OneShieldTestHelpers;
use Tests\TestCase;

/**
 * Tests for the HmacAuthentication middleware.
 *
 * Covers:
 * - Missing headers → 401
 * - Invalid token → 401
 * - Stale timestamp (replay attack) → 401
 * - Valid user.token_secret → 200
 * - Valid GatewayToken → 200
 * - Tampered signature → 401
 * - Inactive GatewayToken → 401
 */
class HmacAuthenticationTest extends TestCase
{
    use RefreshDatabase, OneShieldTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHmac();
    }

    /** A simple HMAC-protected route to test against. */
    private function makeRequest(array $payload, array $headers): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/connect/register', $payload, $headers);
    }

    /** @test */
    public function it_rejects_requests_missing_all_headers(): void
    {
        $response = $this->postJson('/api/connect/register', [
            'site_url'  => 'https://example.com',
            'site_name' => 'Test',
        ]);

        $response->assertStatus(401)
                 ->assertJsonFragment(['error' => 'Missing authentication headers']);
    }

    /** @test */
    public function it_rejects_requests_with_only_partial_headers(): void
    {
        $response = $this->postJson('/api/connect/register', [], [
            'X-OneShield-Token' => 'some-token',
            'Accept'            => 'application/json',
        ]);

        $response->assertStatus(401)
                 ->assertJsonFragment(['error' => 'Missing authentication headers']);
    }

    /** @test */
    public function it_rejects_unknown_token(): void
    {
        $payload = ['site_url' => 'https://example.com', 'site_name' => 'Test'];
        $headers = $this->hmacHeaders($payload, 'totally-unknown-token');

        $this->makeRequest($payload, $headers)->assertStatus(401)
             ->assertJsonFragment(['error' => 'Invalid token']);
    }

    /** @test */
    public function it_rejects_stale_timestamp_replay_attack(): void
    {
        $user    = $this->createUser();
        $payload = ['site_url' => 'https://example.com', 'site_name' => 'Test'];

        // Timestamp 10 minutes ago
        $staleTimestamp = time() - 600;
        $headers        = $this->hmacHeaders($payload, $user->token_secret, $staleTimestamp);

        $this->makeRequest($payload, $headers)->assertStatus(401)
             ->assertJsonFragment(['error' => 'Invalid or expired signature']);
    }

    /** @test */
    public function it_rejects_a_tampered_signature(): void
    {
        $user    = $this->createUser();
        $payload = ['site_url' => 'https://example.com', 'site_name' => 'Test'];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        // Corrupt the signature
        $headers['X-OneShield-Signature'] = 'aaabbbccc000';

        $this->makeRequest($payload, $headers)->assertStatus(401)
             ->assertJsonFragment(['error' => 'Invalid or expired signature']);
    }

    /** @test */
    public function it_accepts_a_valid_user_token_secret(): void
    {
        $user    = $this->createUser();
        $payload = ['site_url' => 'https://example.com', 'site_name' => 'My Site'];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        // 201 = site registered successfully
        $this->makeRequest($payload, $headers)->assertStatus(201);
    }

    /** @test */
    public function it_accepts_a_valid_gateway_token(): void
    {
        $user  = $this->createUser();
        $gToken = $this->createGatewayToken($user);

        $payload = ['site_url' => 'https://example.com', 'site_name' => 'My Site'];
        $headers = $this->hmacHeaders($payload, $gToken->token);

        $this->makeRequest($payload, $headers)->assertStatus(201);
    }

    /** @test */
    public function it_rejects_inactive_gateway_token(): void
    {
        $user  = $this->createUser();
        $gToken = $this->createGatewayToken($user, ['is_active' => false]);

        $payload = ['site_url' => 'https://example.com', 'site_name' => 'My Site'];
        $headers = $this->hmacHeaders($payload, $gToken->token);

        // Token is inactive → should fall through to user lookup (fail since token != token_secret)
        $this->makeRequest($payload, $headers)->assertStatus(401);
    }

    /** @test */
    public function it_rejects_signature_computed_with_wrong_secret(): void
    {
        $user    = $this->createUser();
        $payload = ['site_url' => 'https://example.com', 'site_name' => 'My Site'];

        // Sign with a different secret, but send the correct token
        $timestamp = time();
        $wrongSig  = $this->hmac->sign($payload, 'completely-wrong-secret', $timestamp);

        $headers = [
            'X-OneShield-Signature' => $wrongSig,
            'X-OneShield-Timestamp' => (string) $timestamp,
            'X-OneShield-Token'     => $user->token_secret,
            'Accept'                => 'application/json',
            'Content-Type'          => 'application/json',
        ];

        $this->makeRequest($payload, $headers)->assertStatus(401);
    }

    /** @test */
    public function it_accepts_timestamp_within_five_minutes(): void
    {
        $user      = $this->createUser();
        $payload   = ['site_url' => 'https://valid.com', 'site_name' => 'Borderline'];
        $timestamp = time() - 299; // just inside the 5-minute window
        $headers   = $this->hmacHeaders($payload, $user->token_secret, $timestamp);

        $this->makeRequest($payload, $headers)->assertStatus(201);
    }
}
