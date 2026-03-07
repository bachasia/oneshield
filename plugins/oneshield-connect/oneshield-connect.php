<?php
/**
 * Plugin Name: OneShield Connect
 * Plugin URI: https://oneshield.io
 * Description: Connects this Shield Site to the OneShield Gateway Panel. Handles payment iframes for PayPal and Stripe.
 * Version: 1.0.4
 * Author: OneShield
 * License: GPL-2.0+
 * Text Domain: oneshield-connect
 */

defined('ABSPATH') || exit;

define('OSC_VERSION', '1.0.4');
define('OSC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OSC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load core files
require_once OSC_PLUGIN_DIR . 'inc/base.php';
require_once OSC_PLUGIN_DIR . 'inc/settings.php';
require_once OSC_PLUGIN_DIR . 'inc/remote.php';
require_once OSC_PLUGIN_DIR . 'inc/heartbeat.php';
require_once OSC_PLUGIN_DIR . 'inc/ping.php';

// Load checkout handlers unconditionally so AJAX actions are always registered.
// (Previously only loaded conditionally — causing AJAX actions to 404.)
require_once OSC_PLUGIN_DIR . 'checkout/stripe.php';
require_once OSC_PLUGIN_DIR . 'checkout/paypal.php';
require_once OSC_PLUGIN_DIR . 'inc/order.php';

// WooCommerce payment gateway integration
require_once OSC_PLUGIN_DIR . 'inc/gateway.php';

// Activation
register_activation_hook(__FILE__, 'osc_activate');
function osc_activate() {
    // Schedule heartbeat
    if (!wp_next_scheduled('osc_heartbeat_cron')) {
        wp_schedule_event(time(), 'five_minutes', 'osc_heartbeat_cron');
    }
}

// Deactivation
register_deactivation_hook(__FILE__, 'osc_deactivate');
function osc_deactivate() {
    wp_clear_scheduled_hook('osc_heartbeat_cron');
}

// Custom cron schedule: every 5 minutes
add_filter('cron_schedules', function ($schedules) {
    $schedules['five_minutes'] = [
        'interval' => 300,
        'display'  => __('Every 5 Minutes', 'oneshield-connect'),
    ];
    return $schedules;
});

// Heartbeat cron
add_action('osc_heartbeat_cron', 'osc_run_heartbeat');

// Handle checkout iframe requests (?os-checkout=1)
add_action('init', 'osc_handle_checkout_request');
function osc_handle_checkout_request() {
    if (!isset($_GET['os-checkout'])) {
        return;
    }

    // ── Phase 1: Prefer checkout_id over legacy query params ──────────────
    $checkout_id = sanitize_text_field($_GET['checkout_id'] ?? '');

    if (!empty($checkout_id)) {
        // New flow: resolve context from gateway panel via checkout_id
        osc_handle_checkout_by_id($checkout_id);
        return;
    }

    // ── Legacy fallback: read context from query params ───────────────────
    $gateway  = sanitize_text_field($_GET['gateway'] ?? '');
    $order_id = sanitize_text_field($_GET['order_id'] ?? '');
    $token    = sanitize_text_field($_GET['token'] ?? '');

    if (!$gateway || !$order_id) {
        wp_die('Invalid checkout request', 'OneShield Connect', ['response' => 400]);
    }

    osc_apply_iframe_headers();

    switch ($gateway) {
        case 'stripe':
            osc_render_stripe_checkout($order_id, $token);
            break;
        case 'paypal':
            osc_render_paypal_checkout($order_id, $token);
            break;
        default:
            wp_die('Unsupported gateway: ' . esc_html($gateway), 'OneShield Connect', ['response' => 400]);
    }

    exit;
}

/**
 * Handle checkout request using checkout_id (Phase 1 / Phase 2 flow).
 * Fetches session context from gateway panel, then renders appropriate checkout.
 */
function osc_handle_checkout_by_id(string $checkout_id): void {
    // Validate UUID format (basic check)
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $checkout_id)) {
        wp_die('Invalid checkout ID format.', 'OneShield Connect', ['response' => 400]);
    }

    if (!osc_is_connected()) {
        wp_die('Shield Site is not connected to Gateway Panel.', 'OneShield Connect', ['response' => 503]);
    }

    // Fetch session from gateway panel
    $session = osc_resolve_checkout_session($checkout_id);

    if (is_wp_error($session)) {
        $msg = $session->get_error_message();
        $status = match ($session->get_error_code()) {
            'checkout_session_not_found'  => 404,
            'checkout_session_expired'    => 410,
            'checkout_session_site_mismatch' => 403,
            default => 422,
        };
        wp_die(esc_html($msg), 'OneShield Connect', ['response' => $status]);
    }

    osc_apply_iframe_headers();

    $gateway  = $session['gateway'] ?? '';
    $order_id = $session['order_ref'] ?? '';
    $token    = ''; // No token needed — session is already validated server-side

    // Inject session data into $_GET so existing checkout renderers can read it
    // This is done for backward compatibility with stripe.php / paypal.php
    $_GET['amount']             = (string) round($session['amount_minor'] / 100, 2);
    $_GET['currency']           = $session['currency'] ?? 'usd';
    $_GET['capture_method']     = $session['capture_method'] ?? 'automatic';
    $_GET['statement_descriptor'] = $session['descriptor'] ?? '';
    $_GET['enable_wallets']     = ($session['enable_wallets'] ?? true) ? '1' : '0';
    $_GET['description_format'] = $session['description_format'] ?? '';
    $_GET['mode']               = $session['mode'] ?? 'live';

    // Pass checkout_id in meta for AJAX handlers (replacing txn_id/site_id pattern)
    $meta = $session['meta'] ?? [];
    if (!empty($meta['txn_id'])) {
        $_GET['txn_id']   = (string) $meta['txn_id'];
        $_GET['site_id']  = (string) ($meta['site_id'] ?? osc_site_id());
    }

    // Store checkout_id for AJAX billing refresh
    $_GET['checkout_id'] = $checkout_id;

    switch ($gateway) {
        case 'stripe':
            osc_render_stripe_checkout($order_id, $token);
            break;
        case 'paypal':
            osc_render_paypal_checkout($order_id, $token);
            break;
        default:
            wp_die('Unsupported gateway: ' . esc_html($gateway), 'OneShield Connect', ['response' => 400]);
    }

    exit;
}

/**
 * Resolve a checkout session from the gateway panel.
 *
 * @return array|WP_Error Session payload array or WP_Error on failure.
 */
function osc_resolve_checkout_session(string $checkout_id): array|\WP_Error {
    $response = wp_remote_get(osc_gateway_url() . '/api/checkout-sessions/' . rawurlencode($checkout_id), [
        'timeout' => 10,
        // GET /checkout-sessions/{id} does not send request body/query payload.
        // Sign an empty payload so HMAC verification matches Request::all() on Laravel.
        'headers' => osc_build_headers([]),
    ]);

    if (is_wp_error($response)) {
        return new \WP_Error('network_error', $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 404) {
        return new \WP_Error('checkout_session_not_found', 'Checkout session not found.');
    }
    if ($code === 410) {
        return new \WP_Error('checkout_session_expired', 'Checkout session has expired.');
    }
    if ($code === 403) {
        return new \WP_Error('checkout_session_site_mismatch', 'Checkout session does not belong to this site.');
    }
    if ($code !== 200) {
        $err = $body['error'] ?? 'Failed to resolve checkout session (HTTP ' . $code . ')';
        return new \WP_Error('resolve_failed', $err);
    }

    return $body;
}

/**
 * Apply iframe security headers (shared between legacy and checkout_id flows).
 */
function osc_apply_iframe_headers(): void {
    // Origin validation — only allow iframe embeds
    $sec_fetch_dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
    $sec_fetch_site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    $referer        = $_SERVER['HTTP_REFERER'] ?? '';

    $is_iframe   = ($sec_fetch_dest === 'iframe');
    $has_referer = !empty($referer);

    if (!$is_iframe && !$has_referer && $sec_fetch_dest === 'document' && $sec_fetch_site === 'none') {
        wp_die('This page can only be accessed through a checkout process.', 'Access Denied', ['response' => 403]);
    }

    header_remove('X-Frame-Options');
    header_remove('Permissions-Policy');
    header('Content-Security-Policy: frame-ancestors *');
    header('Permissions-Policy: payment=(self "https://*.stripe.com" "https://*.paypal.com")');
    header_remove('X-XSS-Protection');
}
