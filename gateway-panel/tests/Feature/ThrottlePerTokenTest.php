<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\OneShieldTestHelpers;
use Tests\TestCase;

/**
 * Tests for ThrottlePerToken middleware.
 *
 * We set the limit to 3 requests per minute in these tests for speed.
 */
class ThrottlePerTokenTest extends TestCase
{
    use RefreshDatabase, OneShieldTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHmac();
        // Low limit so we can test throttling quickly
        config(['oneshield.rate_limits.api_per_token' => 3]);
        RateLimiter::clear('api_token:' . sha1('fake-token-for-rate-test'));
    }

    /** @test */
    public function requests_within_limit_return_rate_limit_headers(): void
    {
        $user    = $this->createUser();
        $payload = ['site_url' => 'https://rl-test.example.com', 'site_name' => 'RL Test'];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $response = $this->postJson('/api/connect/register', $payload, $headers);

        // Should succeed and include rate limit headers
        $response->assertStatus(201);
        $this->assertNotEmpty($response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('3', $response->headers->get('X-RateLimit-Limit'));
    }

    /** @test */
    public function too_many_requests_returns_429(): void
    {
        $user = $this->createUser();

        // Exhaust the 3-request limit using unique URLs (so each register succeeds)
        for ($i = 1; $i <= 3; $i++) {
            $payload = [
                'site_url'  => "https://rl-exhaust-{$i}.example.com",
                'site_name' => "Site {$i}",
            ];
            $headers = $this->hmacHeaders($payload, $user->token_secret);
            $this->postJson('/api/connect/register', $payload, $headers);
        }

        // 4th request should be throttled
        $payload = ['site_url' => 'https://rl-over.example.com', 'site_name' => 'Over Limit'];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $this->postJson('/api/connect/register', $payload, $headers)
             ->assertStatus(429)
             ->assertJsonFragment(['error' => 'Too many requests'])
             ->assertHeader('Retry-After');
    }

    /** @test */
    public function rate_limit_is_per_token_not_per_ip(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();

        // Exhaust limit for userA
        for ($i = 1; $i <= 3; $i++) {
            $payload = [
                'site_url'  => "https://usera-site-{$i}.example.com",
                'site_name' => "UserA Site {$i}",
            ];
            $headers = $this->hmacHeaders($payload, $userA->token_secret);
            $this->postJson('/api/connect/register', $payload, $headers);
        }

        // userB (different token) should still be able to make requests
        $payload = ['site_url' => 'https://userb-site.example.com', 'site_name' => 'UserB Site'];
        $headers = $this->hmacHeaders($payload, $userB->token_secret);

        $this->postJson('/api/connect/register', $payload, $headers)
             ->assertStatus(201); // NOT throttled
    }

    /** @test */
    public function x_rate_limit_remaining_header_decrements(): void
    {
        $user    = $this->createUser();
        $payload = ['site_url' => 'https://hdr-test.example.com', 'site_name' => 'Header Test'];
        $headers = $this->hmacHeaders($payload, $user->token_secret);

        $response = $this->postJson('/api/connect/register', $payload, $headers);
        $response->assertStatus(201);

        $remaining = (int) $response->headers->get('X-RateLimit-Remaining');
        $this->assertLessThan(3, $remaining); // Should be < max after first hit
    }
}
