<?php

namespace Tests\Unit;

use App\Services\HmacService;
use Tests\TestCase;

/**
 * Unit tests for HmacService.
 *
 * Covers:
 * - sign() produces a valid hex string
 * - verify() accepts a valid signature
 * - verify() rejects wrong secret
 * - verify() rejects tampered payload
 * - verify() rejects stale timestamp
 * - generateToken() returns correct length
 */
class HmacServiceTest extends TestCase
{
    private HmacService $hmac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hmac = new HmacService();
    }

    /** @test */
    public function sign_returns_64_char_hex_string(): void
    {
        $signature = $this->hmac->sign(['foo' => 'bar'], 'my-secret');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
    }

    /** @test */
    public function sign_is_deterministic_for_same_inputs(): void
    {
        $payload   = ['amount' => 100, 'currency' => 'USD'];
        $secret    = 'deterministic-secret';
        $timestamp = 1700000000;

        $sig1 = $this->hmac->sign($payload, $secret, $timestamp);
        $sig2 = $this->hmac->sign($payload, $secret, $timestamp);

        $this->assertEquals($sig1, $sig2);
    }

    /** @test */
    public function sign_produces_different_output_for_different_secrets(): void
    {
        $payload   = ['foo' => 'bar'];
        $timestamp = time();

        $sig1 = $this->hmac->sign($payload, 'secret-a', $timestamp);
        $sig2 = $this->hmac->sign($payload, 'secret-b', $timestamp);

        $this->assertNotEquals($sig1, $sig2);
    }

    /** @test */
    public function sign_produces_different_output_for_different_payloads(): void
    {
        $secret    = 'shared-secret';
        $timestamp = time();

        $sig1 = $this->hmac->sign(['amount' => 10], $secret, $timestamp);
        $sig2 = $this->hmac->sign(['amount' => 20], $secret, $timestamp);

        $this->assertNotEquals($sig1, $sig2);
    }

    /** @test */
    public function verify_accepts_valid_signature(): void
    {
        $payload   = ['order_id' => 'ABC-123', 'amount' => 99.99];
        $secret    = 'valid-secret';
        $timestamp = time();
        $signature = $this->hmac->sign($payload, $secret, $timestamp);

        $this->assertTrue($this->hmac->verify($payload, $signature, $timestamp, $secret));
    }

    /** @test */
    public function verify_rejects_wrong_secret(): void
    {
        $payload   = ['order_id' => 'ABC'];
        $timestamp = time();
        $signature = $this->hmac->sign($payload, 'correct-secret', $timestamp);

        $this->assertFalse($this->hmac->verify($payload, $signature, $timestamp, 'wrong-secret'));
    }

    /** @test */
    public function verify_rejects_tampered_payload(): void
    {
        $secret    = 'my-secret';
        $timestamp = time();
        $original  = ['amount' => 10];
        $tampered  = ['amount' => 1000]; // attacker changes amount

        $signature = $this->hmac->sign($original, $secret, $timestamp);

        $this->assertFalse($this->hmac->verify($tampered, $signature, $timestamp, $secret));
    }

    /** @test */
    public function verify_rejects_stale_timestamp_by_default(): void
    {
        $payload   = ['foo' => 'bar'];
        $secret    = 'stale-test';
        $timestamp = time() - 301; // just over 5 minutes
        $signature = $this->hmac->sign($payload, $secret, $timestamp);

        $this->assertFalse($this->hmac->verify($payload, $signature, $timestamp, $secret));
    }

    /** @test */
    public function verify_accepts_timestamp_within_custom_window(): void
    {
        $payload   = ['foo' => 'bar'];
        $secret    = 'window-test';
        $timestamp = time() - 599; // just under 10 min
        $signature = $this->hmac->sign($payload, $secret, $timestamp);

        // Use 600-second window
        $this->assertTrue($this->hmac->verify($payload, $signature, $timestamp, $secret, 600));
    }

    /** @test */
    public function verify_rejects_future_timestamp_beyond_window(): void
    {
        $payload   = ['foo' => 'bar'];
        $secret    = 'future-test';
        $timestamp = time() + 400; // 400 seconds in the future
        $signature = $this->hmac->sign($payload, $secret, $timestamp);

        $this->assertFalse($this->hmac->verify($payload, $signature, $timestamp, $secret));
    }

    /** @test */
    public function generate_token_returns_correct_length(): void
    {
        $token = $this->hmac->generateToken(64);

        // bin2hex(32 bytes) = 64 hex chars
        $this->assertEquals(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $token);
    }

    /** @test */
    public function generate_token_returns_different_value_each_call(): void
    {
        $token1 = $this->hmac->generateToken(64);
        $token2 = $this->hmac->generateToken(64);

        $this->assertNotEquals($token1, $token2);
    }
}
