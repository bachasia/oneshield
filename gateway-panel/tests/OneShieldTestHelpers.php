<?php

namespace Tests;

use App\Models\GatewayToken;
use App\Models\MeshSite;
use App\Models\SiteGroup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HmacService;

/**
 * Shared helpers for OneShield API tests.
 */
trait OneShieldTestHelpers
{
    protected HmacService $hmac;

    protected function setUpHmac(): void
    {
        $this->hmac = new HmacService();
    }

    /**
     * Create a User with a known token_secret.
     */
    protected function createUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'token_secret' => 'test-token-secret-' . uniqid(),
        ], $overrides));
    }

    /**
     * Create a GatewayToken for a user.
     */
    protected function createGatewayToken(User $user, array $overrides = []): GatewayToken
    {
        return GatewayToken::create(array_merge([
            'user_id'  => $user->id,
            'name'     => 'Test Token',
            'token'    => 'gt-' . bin2hex(random_bytes(16)),
            'is_active' => true,
        ], $overrides));
    }

    /**
     * Create a MeshSite with Stripe credentials by default.
     */
    protected function createMeshSite(User $user, array $overrides = []): MeshSite
    {
        return MeshSite::create(array_merge([
            'user_id'            => $user->id,
            'name'               => 'Test Site',
            'url'                => 'https://mesh-site.example.com',
            'site_key'           => bin2hex(random_bytes(32)),
            'stripe_public_key'  => 'pk_test_xxx',
            'stripe_secret_key'  => 'sk_test_xxx',
            'stripe_mode'        => 'test',
            'paypal_client_id'   => 'paypal-client-id',
            'paypal_secret'      => 'paypal-secret',
            'paypal_mode'        => 'sandbox',
            'is_active'          => true,
            'failure_count'      => 0,
            'last_heartbeat_at'  => now(),
        ], $overrides));
    }

    /**
     * Build HMAC-signed headers for a request.
     *
     * @param  array   $payload      Request body
     * @param  string  $tokenSecret  Token to authenticate with (value sent in X-OneShield-Token)
     * @param  int     $timestamp    Override timestamp (for replay tests)
     */
    protected function hmacHeaders(array $payload, string $tokenSecret, int $timestamp = 0): array
    {
        if ($timestamp === 0) {
            $timestamp = time();
        }

        $signature = $this->hmac->sign($payload, $tokenSecret, $timestamp);

        return [
            'X-OneShield-Signature' => $signature,
            'X-OneShield-Timestamp' => (string) $timestamp,
            'X-OneShield-Token'     => $tokenSecret,
            'Accept'                => 'application/json',
            'Content-Type'          => 'application/json',
        ];
    }
}
