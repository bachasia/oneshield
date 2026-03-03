<?php
/**
 * HTTP request helper for OneShield Paygates.
 * Handles signed API communication with the Gateway Panel.
 */

defined('ABSPATH') || exit;

class OS_Request {

    private string $gateway_url;
    private string $token_secret;

    public function __construct(string $gateway_url, string $token_secret) {
        $this->gateway_url  = rtrim($gateway_url, '/');
        $this->token_secret = $token_secret;
    }

    /**
     * Build HMAC-signed headers for a request payload.
     */
    public function build_headers(array $payload): array {
        $timestamp = time();
        $body      = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $message   = $body . $timestamp;
        $signature = hash_hmac('sha256', $message, $this->token_secret);

        return [
            'Content-Type'          => 'application/json',
            'X-OneShield-Signature' => $signature,
            'X-OneShield-Timestamp' => (string) $timestamp,
            'X-OneShield-Token'     => $this->token_secret,
        ];
    }

    /**
     * POST JSON payload to a Gateway Panel endpoint.
     *
     * @param string $path   e.g. '/api/paygates/get-site'
     * @param array  $payload
     * @param int    $timeout Seconds (default 15)
     * @return array|null Decoded response body, or null on failure
     */
    public function post(string $path, array $payload, int $timeout = 15): ?array {
        $url  = $this->gateway_url . $path;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = wp_remote_post($url, [
            'timeout' => $timeout,
            'headers' => $this->build_headers($payload),
            'body'    => $body,
        ]);

        return $this->parse_response($response);
    }

    /**
     * GET request to a Gateway Panel endpoint.
     *
     * @param string $path    e.g. '/api/paygates/iframe-url'
     * @param array  $params  Query parameters
     * @param int    $timeout Seconds (default 15)
     * @return array|null Decoded response body, or null on failure
     */
    public function get(string $path, array $params = [], int $timeout = 15): ?array {
        $url = $this->gateway_url . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $response = wp_remote_get($url, [
            'timeout' => $timeout,
            'headers' => $this->build_headers($params),
        ]);

        return $this->parse_response($response);
    }

    /**
     * Parse a WP HTTP response; returns decoded body or null on error.
     */
    private function parse_response(mixed $response): ?array {
        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[OneShield OS_Request] WP_Error: ' . $response->get_error_message());
            }
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[OneShield OS_Request] HTTP ' . $code . ': ' . ($body['error'] ?? wp_remote_retrieve_body($response)));
            }
            return null;
        }

        return is_array($body) ? $body : null;
    }

    /**
     * Check connectivity to the Gateway Panel health endpoint.
     * Returns true if the panel is reachable.
     */
    public function ping(): bool {
        $response = wp_remote_get($this->gateway_url . '/api/health', [
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        return wp_remote_retrieve_response_code($response) === 200;
    }
}
