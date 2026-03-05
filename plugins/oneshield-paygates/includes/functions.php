<?php
defined('ABSPATH') || exit;

// Enqueue frontend JavaScript for iframe + postMessage handling
add_action('wp_enqueue_scripts', 'osp_enqueue_scripts');
function osp_enqueue_scripts(): void {
    if (!is_checkout()) {
        return;
    }

    wp_enqueue_script(
        'oneshield-paygates-checkout',
        OSP_PLUGIN_URL . 'assets/js/checkout.js',
        ['jquery'],
        OSP_VERSION,
        true
    );

    wp_localize_script('oneshield-paygates-checkout', 'osp_data', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('osp_confirm_nonce'),
    ]);
}

// ── Admin: Test Connection AJAX ───────────────────────────────────────────

add_action('wp_ajax_osp_test_connection', 'osp_ajax_test_connection');

function osp_ajax_test_connection(): void {
    check_ajax_referer('osp_status_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Insufficient permissions.']);
    }

    $gateway_id = sanitize_text_field($_POST['gateway_id'] ?? '');

    // Load settings for the requested gateway instance
    $instances = WC()->payment_gateways()->payment_gateways();
    $gateway   = $instances[$gateway_id] ?? null;

    if (!$gateway || !($gateway instanceof OS_Payment_Base)) {
        wp_send_json_error(['message' => 'Gateway not found.']);
    }

    $gateway_url  = $gateway->get_option('gateway_url', '');
    $token_secret = $gateway->get_option('token_secret', '');

    if (empty($gateway_url) || empty($token_secret)) {
        wp_send_json_error(['message' => 'Gateway URL and Token Secret must be filled in first.']);
    }

    // Build signed request to /api/paygates/status
    $payload   = [];
    $timestamp = time();
    $message   = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $timestamp;
    $signature = hash_hmac('sha256', $message, $token_secret);

    $response = wp_remote_get(rtrim($gateway_url, '/') . '/api/paygates/status', [
        'timeout' => 10,
        'headers' => [
            'Content-Type'          => 'application/json',
            'X-OneShield-Signature' => $signature,
            'X-OneShield-Timestamp' => (string) $timestamp,
            'X-OneShield-Token'     => $token_secret,
        ],
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Could not reach Gateway Panel: ' . $response->get_error_message()]);
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 401) {
        wp_send_json_error(['message' => 'Invalid Token Secret — authentication failed.']);
    }

    if ($code >= 400 || empty($body['ok'])) {
        wp_send_json_error(['message' => 'Gateway Panel returned error (HTTP ' . $code . ').']);
    }

    wp_send_json_success($body);
}
