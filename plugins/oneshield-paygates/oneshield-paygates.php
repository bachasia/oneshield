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
    // When WC "ship to same address" is selected, shipping_* inputs are hidden/absent.
    // In that case fall back to billing address for shipping.
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

    // If shipping address is empty, use billing as shipping
    if (empty($shipping) && !empty($billing)) {
        $shipping = array_filter([
            'first_name' => $billing['first_name'] ?? '',
            'last_name'  => $billing['last_name']  ?? '',
            'phone'      => $billing['phone']       ?? '',
            'address_1'  => $billing['address_1']   ?? '',
            'address_2'  => $billing['address_2']   ?? '',
            'city'       => $billing['city']        ?? '',
            'state'      => $billing['state']       ?? '',
            'postcode'   => $billing['postcode']    ?? '',
            'country'    => $billing['country']     ?? '',
        ]);
    }

    if (empty($billing)) {
        // send_billing may be disabled — just confirm success so JS can proceed
        wp_send_json_success(['skipped' => true]);
    }

    $result = $gateway_class->send_billing_to_panel($os_txn_id, $billing, $os_checkout_id, $shipping);
    if ($result) {
        wp_send_json_success();
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

// ── PayPal: create draft WC order early so invoice_id is known when creating PayPal order ──
// Called by Shield Site iframe just before creating the PayPal order.
// Returns a stable (invoice_id, draft_order_id) pair for the current cart session.
add_action('wp_ajax_nopriv_osp_get_paypal_invoice_id', 'osp_ajax_get_paypal_invoice_id');
add_action('wp_ajax_osp_get_paypal_invoice_id',        'osp_ajax_get_paypal_invoice_id');

function osp_ajax_get_paypal_invoice_id(): void {
    $checkout_session_id = sanitize_text_field($_POST['checkout_session_id'] ?? '');

    if (empty($checkout_session_id)) {
        wp_send_json_error('Missing checkout_session_id');
    }

    // Use transient keyed to checkout_session_id so multiple calls return same draft order
    $transient_key = 'osp_paypal_draft_' . md5($checkout_session_id);
    $cached = get_transient($transient_key);
    if ($cached && !empty($cached['draft_order_id']) && wc_get_order($cached['draft_order_id'])) {
        $draft_order = wc_get_order($cached['draft_order_id']);
        // Only reuse if still pending/on-hold (not already completed/cancelled)
        if (in_array($draft_order->get_status(), ['pending', 'on-hold'], true)) {
            wp_send_json_success($cached);
        }
    }

    // Need WooCommerce session and cart to create a draft order
    if (!WC()->cart || WC()->cart->is_empty()) {
        wp_send_json_error('Cart is empty');
    }

    // Get PayPal gateway instance to read invoice_prefix
    $gw = osp_get_gateway_instance('paypal');
    $invoice_prefix = $gw ? $gw->get_option('invoice_prefix', '') : '';

    // Create a pending WC order from the current cart
    $checkout = WC()->checkout();
    $draft_order = wc_create_order([
        'status'        => 'pending',
        'customer_id'   => get_current_user_id(),
        'customer_note' => '',
        'created_via'   => 'oneshield_paypal_draft',
    ]);

    if (is_wp_error($draft_order)) {
        wp_send_json_error('Failed to create draft order: ' . $draft_order->get_error_message());
    }

    // Copy cart items into draft order
    foreach (WC()->cart->get_cart() as $cart_item) {
        $draft_order->add_product(
            $cart_item['data'],
            $cart_item['quantity'],
            [
                'variation' => $cart_item['variation'] ?? [],
                'totals'    => [
                    'subtotal'     => $cart_item['line_subtotal'],
                    'subtotal_tax' => $cart_item['line_subtotal_tax'],
                    'total'        => $cart_item['line_total'],
                    'tax'          => $cart_item['line_tax'],
                ],
            ]
        );
    }

    // Copy fees, shipping, totals
    foreach (WC()->cart->get_fees() as $fee) {
        $draft_order->add_fee($fee);
    }

    $draft_order->set_currency(get_woocommerce_currency());
    $draft_order->set_payment_method('os_paypal');
    $draft_order->set_payment_method_title('PayPal');
    $draft_order->calculate_totals();

    // Tag the draft so we can clean it up later
    $draft_order->update_meta_data('_osp_paypal_draft', '1');
    $draft_order->update_meta_data('_osp_checkout_session_id', $checkout_session_id);
    $draft_order->save();

    $draft_order_id = $draft_order->get_id();
    $invoice_id = !empty($invoice_prefix)
        ? $invoice_prefix . '-' . $draft_order_id
        : (string) $draft_order_id;

    $result = [
        'draft_order_id' => $draft_order_id,
        'invoice_id'     => $invoice_id,
    ];

    // Cache for 30 minutes (PayPal checkout session lifespan)
    set_transient($transient_key, $result, 30 * MINUTE_IN_SECONDS);

    wp_send_json_success($result);
}

// ── Cleanup stale PayPal draft orders (older than 2 hours) ──────────────────
add_action('osp_cleanup_paypal_drafts', 'osp_do_cleanup_paypal_drafts');
if (!wp_next_scheduled('osp_cleanup_paypal_drafts')) {
    wp_schedule_event(time(), 'hourly', 'osp_cleanup_paypal_drafts');
}

function osp_do_cleanup_paypal_drafts(): void {
    $orders = wc_get_orders([
        'status'       => ['pending'],
        'meta_key'     => '_osp_paypal_draft',
        'meta_value'   => '1',
        'date_created' => '<' . (time() - 2 * HOUR_IN_SECONDS),
        'limit'        => 50,
    ]);
    foreach ($orders as $order) {
        $order->update_status('cancelled', 'Auto-cancelled: PayPal draft order expired.');
    }
}

// ── PayPal iframe outside payment box ───────────────────────────────────────
// Rendered after the Place Order button so WooCommerce's updated_checkout
// does NOT rebuild it (it lives outside .payment_box / #order_review).
// Visibility is controlled by checkout.js based on selected payment method.

add_action('woocommerce_review_order_after_submit', 'osp_render_paypal_iframe_outside');

function osp_render_paypal_iframe_outside(): void {
    $gw = osp_get_gateway_instance('paypal');
    if (!$gw || !$gw->is_available()) {
        return;
    }

    $result = $gw->get_paypal_iframe_result();
    if (!$result || empty($result['iframe_url'])) {
        return;
    }

    $iframe_url  = esc_url($result['iframe_url']);
    $loading_id  = 'osp-iframe-loading-paypal';
    $initial_h   = '200';

    ?>
    <div id="osp-paypal-button-wrap" style="display:none;margin-top:12px;">
        <div class="osp-iframe-wrap" style="position:relative;">
            <iframe
                id="osp-iframe-paypal"
                src="<?php echo $iframe_url; ?>"
                style="width:100%;height:<?php echo $initial_h; ?>px;border:none;display:block;"
                scrolling="no"
                allow="payment"
                sandbox="allow-forms allow-scripts allow-same-origin allow-popups allow-top-navigation-by-user-activation"
                referrerpolicy="no-referrer"
                onload="var l=document.getElementById('<?php echo esc_attr($loading_id); ?>');if(l)l.style.display='none';"
            ></iframe>
            <div id="<?php echo esc_attr($loading_id); ?>"
                 style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:#fff;font-size:13px;color:#9ca3af;">
                <?php esc_html_e('Loading payment form…', 'oneshield-paygates'); ?>
            </div>
        </div>
    </div>
    <?php
}

// ── Orders list: Shield URL column ──────────────────────────────────────────
// Supports both WooCommerce HPOS (woocommerce_shop_order_list_table_columns)
// and the legacy CPT list (manage_edit-shop_order_columns).

add_filter('woocommerce_shop_order_list_table_columns', 'osp_add_shield_url_column');  // HPOS
add_filter('manage_edit-shop_order_columns',            'osp_add_shield_url_column');  // Legacy

function osp_add_shield_url_column(array $columns): array {
    $new = [];
    foreach ($columns as $key => $label) {
        $new[$key] = $label;
        // Insert after 'order_status'
        if ($key === 'order_status') {
            $new['osp_shield_url'] = __('Shield URL', 'oneshield-paygates');
        }
    }
    // Fallback: append at end if order_status column not found
    if (!array_key_exists('osp_shield_url', $new)) {
        $new['osp_shield_url'] = __('Shield URL', 'oneshield-paygates');
    }
    return $new;
}

add_action('woocommerce_shop_order_list_table_custom_column', 'osp_render_shield_url_column', 10, 2); // HPOS
add_action('manage_shop_order_posts_custom_column',           'osp_render_shield_url_column', 10, 2); // Legacy

function osp_render_shield_url_column(string $column, $order_or_id): void {
    if ($column !== 'osp_shield_url') {
        return;
    }

    $order = ($order_or_id instanceof \WC_Order)
        ? $order_or_id
        : wc_get_order((int) $order_or_id);

    if (!$order) {
        return;
    }

    // Only show for OneShield gateways
    $payment_method = $order->get_payment_method();
    if (!in_array($payment_method, ['os_stripe', 'os_paypal'], true)) {
        return;
    }

    $shield_url = $order->get_meta('_os_shield_url', true);
    $gateway    = $order->get_meta('_os_shield_gateway', true);

    if (empty($shield_url)) {
        echo '<span style="color:#9ca3af;font-size:12px;">—</span>';
        return;
    }

    $gateway_label = $gateway === 'paypal' ? 'PayPal' : 'Stripe';
    $domain        = preg_replace('#^https?://#i', '', rtrim($shield_url, '/'));

    printf(
        '<span style="font-size:12px;line-height:1.4;">'
        . '<span style="display:inline-block;background:#e0f2fe;color:#0369a1;border-radius:3px;padding:1px 5px;font-size:11px;font-weight:600;margin-bottom:3px;">[%s]</span><br>'
        . '<a href="%s" target="_blank" style="color:#374151;text-decoration:none;" title="%s">%s</a>'
        . '</span>',
        esc_html($gateway_label),
        esc_url($shield_url),
        esc_attr($shield_url),
        esc_html($domain)
    );
}
