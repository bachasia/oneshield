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

    $register_site_id = (int) osc_register_site_id();
    if ($register_site_id <= 0) {
        return new \WP_Error('no_site_id', 'Site ID not set. Please enter the Site ID from Gateway Panel.');
    }

    $authorize_key = osc_authorize_key();
    if (empty($authorize_key)) {
        return new \WP_Error('no_authorize_key', 'Authorize Key not set. Please enter the Authorize Key from Gateway Panel.');
    }

    $payload = [
        'site_id'       => $register_site_id,
        'authorize_key' => $authorize_key,
        'site_url'      => get_site_url(),
        'site_name'     => get_bloginfo('name'),
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

        // Persist credentials pushed from Gateway Panel
        osc_sync_credentials($body['credentials'] ?? []);
    }
}

/**
 * Save gateway credentials received from the Gateway Panel heartbeat response.
 * Credentials are only written when present — missing keys are left unchanged.
 *
 * @param array $credentials  e.g. ['stripe' => [...], 'paypal' => [...]]
 */
function osc_sync_credentials(array $credentials): void {
    if (!empty($credentials['stripe'])) {
        $stripe = $credentials['stripe'];
        if (!empty($stripe['public_key'])) {
            osc_update_option('stripe_public_key', sanitize_text_field($stripe['public_key']));
        }
        if (!empty($stripe['secret_key'])) {
            osc_update_option('stripe_secret_key', sanitize_text_field($stripe['secret_key']));
        }
        if (!empty($stripe['mode'])) {
            osc_update_option('stripe_mode', sanitize_text_field($stripe['mode']));
        }
        osc_log('Stripe credentials synced from Panel');
    }

    if (!empty($credentials['paypal'])) {
        $paypal = $credentials['paypal'];
        if (!empty($paypal['client_id'])) {
            osc_update_option('paypal_client_id', sanitize_text_field($paypal['client_id']));
        }
        if (!empty($paypal['client_secret'])) {
            osc_update_option('paypal_secret', sanitize_text_field($paypal['client_secret']));
        }
        if (!empty($paypal['mode'])) {
            osc_update_option('paypal_mode', sanitize_text_field($paypal['mode']));
        }
        osc_log('PayPal credentials synced from Panel');
    }
}
