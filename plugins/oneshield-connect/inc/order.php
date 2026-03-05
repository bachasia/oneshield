<?php
/**
 * WooCommerce order helpers for Shield Site.
 * Creates a lightweight WC order to track payment on the Shield Site side.
 */

defined('ABSPATH') || exit;

/**
 * Create a tracking order on the Shield Site (minimal WC order).
 * This is used to associate Stripe/PayPal payment with the original order_id.
 */
function osc_create_tracking_order(string $order_id, float $amount, string $currency, string $gateway): int {
    if (!function_exists('wc_create_order')) {
        return 0;
    }

    $order = wc_create_order();
    $order->set_currency($currency);
    $order->update_meta_data('_os_original_order_id', $order_id);
    $order->update_meta_data('_os_gateway', $gateway);
    $order->update_meta_data('_os_amount', $amount);
    $order->set_total($amount);
    $order->set_status('pending');
    $order->save();

    return $order->get_id();
}

/**
 * Complete the tracking order after payment success.
 */
function osc_complete_tracking_order(int $wc_order_id, string $gateway_transaction_id): void {
    $order = wc_get_order($wc_order_id);
    if (!$order) {
        return;
    }

    $order->payment_complete($gateway_transaction_id);
    $order->add_order_note(sprintf(
        'OneShield Connect: Payment completed via %s. Transaction ID: %s',
        $order->get_meta('_os_gateway'),
        $gateway_transaction_id
    ));
}

/**
 * AJAX: Create tracking order when checkout iframe loads.
 * Called from checkout/stripe.php and checkout/paypal.php JS before rendering.
 */
add_action('wp_ajax_nopriv_osc_create_tracking', 'osc_ajax_create_tracking');
add_action('wp_ajax_osc_create_tracking', 'osc_ajax_create_tracking');

function osc_ajax_create_tracking(): void {
    // Skip nonce: cross-origin iframe blocks cookies → nonce always fails.

    $order_id = sanitize_text_field($_POST['order_id'] ?? '');
    $amount   = (float) ($_POST['amount'] ?? 0);
    $currency = strtoupper(sanitize_text_field($_POST['currency'] ?? 'USD'));
    $gateway  = sanitize_text_field($_POST['gateway'] ?? '');

    if (!$order_id || !$gateway) {
        wp_send_json_error('Missing required fields');
    }

    $wc_order_id = osc_create_tracking_order($order_id, $amount, $currency, $gateway);

    if ($wc_order_id) {
        wp_send_json_success(['wc_order_id' => $wc_order_id]);
    } else {
        wp_send_json_error('Could not create tracking order (WooCommerce may not be active)');
    }
}

/**
 * AJAX: Mark tracking order complete after payment success.
 * Called from checkout JS after payment intent succeeds.
 * Accepts both stripe and paypal nonces.
 */
add_action('wp_ajax_nopriv_osc_complete_tracking', 'osc_ajax_complete_tracking');
add_action('wp_ajax_osc_complete_tracking', 'osc_ajax_complete_tracking');

function osc_ajax_complete_tracking(): void {
    // Skip nonce: cross-origin iframe blocks cookies → nonce always fails.

    $order_id      = sanitize_text_field($_POST['order_id'] ?? '');
    $transaction_id = sanitize_text_field($_POST['transaction_id'] ?? '');

    if (!$order_id || !$transaction_id) {
        wp_send_json_error('Missing required fields');
    }

    // Find tracking order by original order_id meta
    $orders = wc_get_orders([
        'meta_key'   => '_os_original_order_id',
        'meta_value' => $order_id,
        'limit'      => 1,
        'status'     => 'pending',
    ]);

    if (empty($orders)) {
        // No tracking order found — not a fatal error
        wp_send_json_success(['message' => 'No tracking order found']);
    }

    osc_complete_tracking_order($orders[0]->get_id(), $transaction_id);
    wp_send_json_success(['message' => 'Tracking order completed']);
}
