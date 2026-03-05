<?php
/**
 * Plugin Name: OneShield Connect
 * Plugin URI: https://oneshield.io
 * Description: Connects this Shield Site to the OneShield Gateway Panel. Handles payment iframes for PayPal and Stripe.
 * Version: 1.0.0
 * Author: OneShield
 * License: GPL-2.0+
 * Text Domain: oneshield-connect
 */

defined('ABSPATH') || exit;

define('OSC_VERSION', '1.0.0');
define('OSC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OSC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load core files
require_once OSC_PLUGIN_DIR . 'inc/base.php';
require_once OSC_PLUGIN_DIR . 'inc/settings.php';
require_once OSC_PLUGIN_DIR . 'inc/remote.php';
require_once OSC_PLUGIN_DIR . 'inc/heartbeat.php';
require_once OSC_PLUGIN_DIR . 'inc/ping.php';

// Load checkout handlers unconditionally so AJAX actions are always registered.
// (Previously only loaded on ?fe-checkout requests — causing AJAX to 404.)
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

// Handle ?fe-checkout requests
add_action('init', 'osc_handle_checkout_request');
function osc_handle_checkout_request() {
    if (!isset($_GET['fe-checkout'])) {
        return;
    }

    $gateway  = sanitize_text_field($_GET['gateway'] ?? '');
    $order_id = sanitize_text_field($_GET['order_id'] ?? '');
    $token    = sanitize_text_field($_GET['token'] ?? '');

    if (!$gateway || !$order_id) {
        wp_die('Invalid checkout request', 'OneShield Connect', ['response' => 400]);
    }

    // Allow this page to be embedded in an iframe on any origin.
    // Remove ALL restrictive headers that WordPress, security plugins, or the
    // web-server (LiteSpeed, Nginx, etc.) may add by default.
    header_remove('X-Frame-Options');
    header_remove('Permissions-Policy');
    header('Content-Security-Policy: frame-ancestors *');
    header('Permissions-Policy: payment=(self "https://*.stripe.com" "https://*.paypal.com")');
    // Strip X-XSS-Protection which can interfere with cross-origin iframes
    header_remove('X-XSS-Protection');

    // Render the appropriate checkout page
    // (checkout files already loaded globally at plugin init)
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
