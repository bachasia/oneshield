<?php
/**
 * API calls to Gateway Panel.
 */

defined('ABSPATH') || exit;

/**
 * Register this site with the Gateway Panel.
 */
function osc_register_site(): array|\WP_Error {
    $gateway_url = osc_gateway_url();
    if (empty($gateway_url)) {
        return new \WP_Error('no_gateway', 'Gateway URL not set.');
    }

    // We need an initial token to call the API — ask user for token_secret on connect
    $token_secret = osc_get_option('token_secret', '');
    if (empty($token_secret)) {
        return new \WP_Error('no_token', 'Token Secret not set. Please enter the Token Secret from your Gateway Panel.');
    }

    $payload = [
        'site_url'  => get_site_url(),
        'site_name' => get_bloginfo('name'),
    ];

    $timestamp = time();
    $message   = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $timestamp;
    $signature = hash_hmac('sha256', $message, $token_secret);

    $response = wp_remote_post($gateway_url . '/api/connect/register', [
        'timeout' => 15,
        'headers' => [
            'Content-Type'          => 'application/json',
            'X-OneShield-Signature' => $signature,
            'X-OneShield-Timestamp' => (string) $timestamp,
            'X-OneShield-Token'     => $token_secret,
        ],
        'body' => json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code >= 400) {
        return new \WP_Error('api_error', $body['error'] ?? 'Registration failed (HTTP ' . $code . ')');
    }

    // Save credentials
    osc_update_option('site_id', $body['site_id']);
    osc_update_option('site_key', $body['site_key']);

    return $body;
}

/**
 * Send heartbeat to Gateway Panel.
 */
function osc_run_heartbeat(): void {
    if (!osc_is_connected()) {
        return;
    }

    $payload = ['site_id' => (int) osc_site_id()];
    $headers = osc_build_headers($payload);

    $response = wp_remote_post(osc_gateway_url() . '/api/connect/heartbeat', [
        'timeout' => 10,
        'headers' => $headers,
        'body'    => json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        osc_log('Heartbeat failed: ' . $response->get_error_message());
        return;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (($body['status'] ?? '') === 'ok') {
        osc_update_option('last_heartbeat', time());
        osc_log('Heartbeat OK', ['config' => $body['config'] ?? []]);
    }
}
