<?php
/**
 * WooCommerce order helpers for mesh site.
 * Creates a lightweight WC order to track payment on the mesh site side.
 */

defined('ABSPATH') || exit;

/**
 * Create a tracking order on the mesh site (minimal WC order).
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
