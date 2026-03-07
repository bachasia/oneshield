<?php
/**
 * Plugin Name: OneShield Paygates
 * Plugin URI: https://oneshield.io
 * Description: WooCommerce payment gateways that route payments through the OneShield Gateway Panel to Shield Sites.
 * Version: 1.0.1
 * Author: OneShield
 * License: GPL-2.0+
 * Text Domain: oneshield-paygates
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

defined('ABSPATH') || exit;

define('OSP_VERSION', '1.0.1');
define('OSP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OSP_PLUGIN_URL', plugin_dir_url(__FILE__));

// WooCommerce HPOS compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Load after WooCommerce is loaded
add_action('plugins_loaded', 'osp_init_gateways');
function osp_init_gateways(): void {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once OSP_PLUGIN_DIR . 'includes/class-os-payment-base.php';
    require_once OSP_PLUGIN_DIR . 'includes/class-os-stripe.php';
    require_once OSP_PLUGIN_DIR . 'includes/class-os-paypal.php';
    require_once OSP_PLUGIN_DIR . 'includes/class-os-request.php';
    require_once OSP_PLUGIN_DIR . 'includes/class-os-ipn-handler.php';
    require_once OSP_PLUGIN_DIR . 'includes/functions.php';

    // Register payment gateways
    add_filter('woocommerce_payment_gateways', 'osp_add_gateways');
}

function osp_add_gateways(array $gateways): array {
    $gateways[] = 'OS_Stripe_Gateway';
    $gateways[] = 'OS_PayPal_Gateway';
    return $gateways;
}

// AJAX handler: send billing to gateway panel just before Place Order
// Called by checkout.js when user clicks Place Order and OneShield gateway is selected.
add_action('wp_ajax_nopriv_osp_send_billing', 'osp_ajax_send_billing');
add_action('wp_ajax_osp_send_billing', 'osp_ajax_send_billing');

function osp_ajax_send_billing(): void {
    check_ajax_referer('osp_confirm_nonce', 'nonce');

    $gateway        = sanitize_text_field($_POST['gateway']        ?? '');
    $os_txn_id      = absint($_POST['os_txn_id']                  ?? 0);
    $os_checkout_id = sanitize_text_field($_POST['os_checkout_id'] ?? '');

    if ((!$os_txn_id && !$os_checkout_id) || !in_array($gateway, ['stripe', 'paypal'], true)) {
        wp_send_json_error('Invalid params');
    }

    $gateway_class = osp_get_gateway_instance($gateway);
    if (!$gateway_class) {
        wp_send_json_error('Gateway not found');
    }

    // Read billing directly from POST — JS sends the form fields explicitly
    // because WC()->customer session is not yet updated at AJAX call time.
    $billing = array_filter([
        'first_name' => sanitize_text_field($_POST['billing_first_name'] ?? ''),
        'last_name'  => sanitize_text_field($_POST['billing_last_name']  ?? ''),
        'email'      => sanitize_email($_POST['billing_email']           ?? ''),
        'phone'      => sanitize_text_field($_POST['billing_phone']      ?? ''),
        'address_1'  => sanitize_text_field($_POST['billing_address_1']  ?? ''),
        'address_2'  => sanitize_text_field($_POST['billing_address_2']  ?? ''),
        'city'       => sanitize_text_field($_POST['billing_city']       ?? ''),
        'state'      => sanitize_text_field($_POST['billing_state']      ?? ''),
        'postcode'   => sanitize_text_field($_POST['billing_postcode']   ?? ''),
        'country'    => sanitize_text_field($_POST['billing_country']    ?? ''),
    ]);

    // Shipping address — may differ from billing
    $shipping = array_filter([
        'first_name' => sanitize_text_field($_POST['shipping_first_name'] ?? ''),
        'last_name'  => sanitize_text_field($_POST['shipping_last_name']  ?? ''),
        'phone'      => sanitize_text_field($_POST['shipping_phone']      ?? ''),
        'address_1'  => sanitize_text_field($_POST['shipping_address_1']  ?? ''),
        'address_2'  => sanitize_text_field($_POST['shipping_address_2']  ?? ''),
        'city'       => sanitize_text_field($_POST['shipping_city']       ?? ''),
        'state'      => sanitize_text_field($_POST['shipping_state']      ?? ''),
        'postcode'   => sanitize_text_field($_POST['shipping_postcode']   ?? ''),
        'country'    => sanitize_text_field($_POST['shipping_country']    ?? ''),
    ]);

    if (empty($billing)) {
        // send_billing may be disabled — just confirm success so JS can proceed
        wp_send_json_success(['skipped' => true]);
    }

    $result = $gateway_class->send_billing_to_panel($os_txn_id, $billing, $os_checkout_id, $shipping);
    if ($result) {
        wp_send_json_success([
            '_debug' => [
                'billing_keys'   => array_keys($billing),
                'shipping_keys'  => array_keys($shipping),
                'ship_address1'  => $shipping['address_1'] ?? '(empty)',
                'ship_city'      => $shipping['city']      ?? '(empty)',
                'ship_country'   => $shipping['country']   ?? '(empty)',
            ],
        ]);
    } else {
        // Non-fatal: proceed anyway (payment still works, just without billing on PaymentMethod)
        wp_send_json_success(['warning' => 'billing_update_failed']);
    }
}

// AJAX handler for iframe checkout postMessage confirm
add_action('wp_ajax_nopriv_osp_confirm_payment', 'osp_ajax_confirm_payment');
add_action('wp_ajax_osp_confirm_payment', 'osp_ajax_confirm_payment');

function osp_ajax_confirm_payment(): void {
    check_ajax_referer('osp_confirm_nonce', 'nonce');

    $order_id      = absint($_POST['wc_order_id'] ?? 0);
    $gateway_tx_id = sanitize_text_field($_POST['transaction_id'] ?? '');
    $gateway       = sanitize_text_field($_POST['gateway'] ?? '');

    $order = wc_get_order($order_id);
    if (!$order || $order->get_status() !== 'pending') {
        wp_send_json_error('Invalid order');
    }

    // Retrieve the ShieldSite ID stored on the order during process_payment()
    $os_site_id = absint($order->get_meta('_os_site_id'));
    if (!$os_site_id) {
        wp_send_json_error('Missing Shield Site ID on order — cannot confirm');
    }

    // Confirm with Gateway Panel using the correct site_id
    $gateway_class = osp_get_gateway_instance($gateway);
    if ($gateway_class) {
        $confirmed = $gateway_class->confirm_with_panel($os_site_id, $order_id, $gateway_tx_id);
        if (!$confirmed) {
            wp_send_json_error('Panel confirmation failed');
        }
    }

    // Complete WC order
    $order->payment_complete($gateway_tx_id);
    $order->add_order_note(sprintf(
        'OneShield: Payment completed via %s. Gateway TX: %s',
        strtoupper($gateway),
        $gateway_tx_id
    ));

    wp_send_json_success([
        'redirect' => $order->get_checkout_order_received_url(),
    ]);
}

function osp_get_gateway_instance(string $gateway): ?OS_Payment_Base {
    $instances = WC()->payment_gateways()->payment_gateways();
    $map = ['stripe' => 'os_stripe', 'paypal' => 'os_paypal'];
    $key = $map[$gateway] ?? null;
    return $key ? ($instances[$key] ?? null) : null;
}
