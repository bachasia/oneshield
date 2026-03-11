<?php

namespace App\Services;

class HmacService
{
    /**
     * Sign a payload with the given token secret.
     *
     * @param  array  $payload  Request body array
     * @param  string  $tokenSecret  HMAC signing key
     * @param  int  $timestamp  Unix timestamp (defaults to now)
     */
    public function sign(array $payload, string $tokenSecret, int $timestamp = 0): string
    {
        if ($timestamp === 0) {
            $timestamp = time();
        }

        $message = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $timestamp;

        return hash_hmac('sha256', $message, $tokenSecret);
    }

    /**
     * Verify a signed request.
     *
     * @param  array  $payload  Request body array
     * @param  string  $signature  Signature from X-OneShield-Signature header
     * @param  int  $timestamp  Timestamp from X-OneShield-Timestamp header
     * @param  string  $tokenSecret  HMAC signing key
     * @param  int  $maxAgeSeconds  Maximum allowed age in seconds (default 5 minutes)
     */
    public function verify(
        array $payload,
        string $signature,
        int $timestamp,
        string $tokenSecret,
        int $maxAgeSeconds = 300
    ): bool {
        // Reject stale requests (replay attack prevention)
        if (abs(time() - $timestamp) > $maxAgeSeconds) {
            return false;
        }

        $expected = $this->sign($payload, $tokenSecret, $timestamp);

        return hash_equals($expected, $signature);
    }

    /**
     * Verify a signed request using the raw request body string.
     *
     * This avoids json_encode discrepancies that occur when using $request->all()
     * (decoded then re-encoded array). The PHP plugin signs the raw JSON string
     * directly, so we verify against the same string.
     *
     * @param  string  $rawBody  Raw request body (JSON string)
     * @param  string  $signature  Signature from X-OneShield-Signature header
     * @param  int  $timestamp  Timestamp from X-OneShield-Timestamp header
     * @param  string  $tokenSecret  HMAC signing key
     * @param  int  $maxAgeSeconds  Maximum allowed age in seconds (default 5 minutes)
     */
    public function verifyRaw(
        string $rawBody,
        string $signature,
        int $timestamp,
        string $tokenSecret,
        int $maxAgeSeconds = 300
    ): bool {
        if (abs(time() - $timestamp) > $maxAgeSeconds) {
            return false;
        }

        $message  = $rawBody . $timestamp;
        $expected = hash_hmac('sha256', $message, $tokenSecret);

        return hash_equals($expected, $signature);
    }

    /**
     * Generate a cryptographically secure random token.
     */
    public function generateToken(int $length = 64): string
    {
        return bin2hex(random_bytes($length / 2));
    }
}
