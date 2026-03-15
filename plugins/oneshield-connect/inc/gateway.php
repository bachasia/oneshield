<?php
/**
 * WooCommerce Payment Gateways for OneShield Connect.
 *
 * Registers two payment methods visible on the WooCommerce checkout page:
 *  - OneShield Pay via Stripe
 *  - OneShield Pay via PayPal
 *
 * Each gateway embeds a checkout iframe pointing to this site's
 * ?os-checkout endpoint, which renders the Stripe/PayPal payment form.
 */

defined('ABSPATH') || exit;

/**
 * Register gateway classes with WooCommerce.
 */
add_filter('woocommerce_payment_gateways', 'osc_register_payment_gateways');

function osc_register_payment_gateways(array $gateways): array {
    if (class_exists('WC_Payment_Gateway')) {
        $gateways[] = 'WC_OneShield_Stripe';
        $gateways[] = 'WC_OneShield_PayPal';
    }
    return $gateways;
}

/**
 * Instantiate gateway classes after WooCommerce is loaded.
 */
add_action('plugins_loaded', 'osc_load_gateway_classes', 11);

function osc_load_gateway_classes(): void {
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }

    // ── Stripe Gateway ────────────────────────────────────────────────────

    class WC_OneShield_Stripe extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                 = 'oneshield_stripe';
            $this->method_title       = 'OneShield Pay (Stripe)';
            $this->method_description = 'Accept Stripe payments via OneShield Connect. Credentials are managed from your Gateway Panel.';
            $this->has_fields         = true;
            $this->supports           = ['products'];

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title', 'Pay by Card (Stripe)');
            $this->description = $this->get_option('description', 'Secure card payment powered by Stripe.');
            $this->enabled     = $this->get_option('enabled', 'yes');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields(): void {
            $this->form_fields = [
                'enabled' => [
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable OneShield Stripe payment',
                    'default' => 'yes',
                ],
                'title' => [
                    'title'   => 'Title',
                    'type'    => 'text',
                    'default' => 'Pay by Card (Stripe)',
                ],
                'description' => [
                    'title'   => 'Description',
                    'type'    => 'textarea',
                    'default' => 'Secure card payment powered by Stripe.',
                ],
            ];
        }

        /**
         * Display the payment iframe on checkout.
         */
        public function payment_fields(): void {
            $config = osc_get_stripe_config();

            if (empty($config['publishable_key'])) {
                echo '<p style="color:#dc2626;">Stripe is not yet configured. Please contact the site administrator.</p>';
                return;
            }

            $checkout_url = osc_build_checkout_url('stripe');
            echo '<div id="oneshield-stripe-wrapper" style="width:100%;min-height:360px;">';
            echo '<iframe id="oneshield-stripe-iframe"
                    src="' . esc_url($checkout_url) . '"
                    style="width:100%;height:360px;border:none;border-radius:8px;"
                    scrolling="no"
                    allowpaymentrequest></iframe>';
            echo '</div>';
            osc_print_iframe_listener_script('stripe');
        }

        public function process_payment($order_id): array {
            // Payment is handled inside the iframe; WC order is confirmed via postMessage → JS below.
            // This method is called only if someone bypasses JS — treat as pending.
            $order = wc_get_order($order_id);
            $order->update_status('pending', 'Awaiting Stripe payment via OneShield.');

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        }

        public function validate_fields(): bool {
            // Validation is handled server-side by Stripe; no extra WC validation needed.
            return true;
        }
    }

    // ── PayPal Gateway ────────────────────────────────────────────────────

    class WC_OneShield_PayPal extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                 = 'oneshield_paypal';
            $this->method_title       = 'OneShield Pay (PayPal)';
            $this->method_description = 'Accept PayPal payments via OneShield Connect. Credentials are managed from your Gateway Panel.';
            $this->has_fields         = true;
            $this->supports           = ['products'];

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title', 'Pay via PayPal');
            $this->description = $this->get_option('description', 'Secure payment via PayPal.');
            $this->enabled     = $this->get_option('enabled', 'yes');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields(): void {
            $this->form_fields = [
                'enabled' => [
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable OneShield PayPal payment',
                    'default' => 'yes',
                ],
                'title' => [
                    'title'   => 'Title',
                    'type'    => 'text',
                    'default' => 'Pay via PayPal',
                ],
                'description' => [
                    'title'   => 'Description',
                    'type'    => 'textarea',
                    'default' => 'Secure payment via PayPal.',
                ],
            ];
        }

        public function payment_fields(): void {
            $config = osc_get_paypal_config();

            if (empty($config['client_id'])) {
                echo '<p style="color:#dc2626;">PayPal is not yet configured. Please contact the site administrator.</p>';
                return;
            }

            $checkout_url = osc_build_checkout_url('paypal');
            echo '<div id="oneshield-paypal-wrapper" style="width:100%;min-height:240px;">';
            echo '<iframe id="oneshield-paypal-iframe"
                    src="' . esc_url($checkout_url) . '"
                    style="width:100%;height:240px;border:none;border-radius:8px;"
                    scrolling="no"
                    allowpaymentrequest></iframe>';
            echo '</div>';
            osc_print_iframe_listener_script('paypal');
        }

        public function process_payment($order_id): array {
            $order = wc_get_order($order_id);
            $order->update_status('pending', 'Awaiting PayPal payment via OneShield.');

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        }

        public function validate_fields(): bool {
            return true;
        }
    }
}

// ── Blacklist Gateway Filter ──────────────────────────────────────────────

/**
 * Hard block at order submission time (Place Order click).
 *
 * Fires during WC checkout validation, before process_payment().
 * Uses actual POST billing fields — reliable regardless of session/display state.
 * action=hide → add WC error, abort order. action=trap → tag session only.
 */
add_action('woocommerce_after_checkout_validation', 'osc_blacklist_checkout_validation', 10, 2);

function osc_blacklist_checkout_validation(array $data, \WP_Error $errors): void {
    // Only intercept OneShield payment methods
    $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');
    if (strpos($payment_method, 'oneshield') === false) {
        return;
    }

    $action = get_option('osc_blacklist_action', 'hide');

    // For trap action: tag session here so process_payment picks it up (already handled by filter)
    if ($action !== 'hide') {
        return;
    }

    // Check using POST billing fields directly — most reliable at submission time
    $email   = sanitize_email($data['billing_email']    ?? '');
    $city    = sanitize_text_field($data['billing_city']       ?? '');
    $state   = sanitize_text_field($data['billing_state']      ?? '');
    $zipcode = sanitize_text_field($data['billing_postcode']   ?? '');

    // Normalize and check
    $list = osc_get_blacklist();

    $email   = strtolower(trim($email));
    $city    = strtolower(trim($city));
    $state   = strtolower(trim($state));
    $zipcode = strtolower(trim($zipcode));

    $blacklisted = ($email   && in_array($email,   $list['emails'],   true))
                || ($city    && in_array($city,    $list['cities'],   true))
                || ($state   && in_array($state,   $list['states'],   true))
                || ($zipcode && in_array($zipcode, $list['zipcodes'], true));

    if ($blacklisted) {
        $errors->add(
            'osc_blacklisted',
            __('This payment method is not available for your billing address.', 'oneshield-connect')
        );
    }
}

/**
 * Hide or trap blacklisted buyers at checkout display level.
 *
 * - action=hide  → remove all OneShield gateways from the available list
 * - action=trap  → store trap_shield_id in WC session so checkout payload uses it
 */
add_filter('woocommerce_available_payment_gateways', 'osc_blacklist_gateway_filter');

function osc_blacklist_gateway_filter(array $gateways): array {
    // Skip on admin pages. During the update_order_review AJAX call, is_checkout()
    // returns false (no main page query), but WC defines WOOCOMMERCE_CHECKOUT constant
    // before rebuilding the payment fragment — accept either.
    if (is_admin()) {
        return $gateways;
    }
    $in_checkout = (function_exists('is_checkout') && is_checkout())
                || defined('WOOCOMMERCE_CHECKOUT');
    if (!$in_checkout) {
        return $gateways;
    }

    $action = get_option('osc_blacklist_action', 'hide'); // 'hide' | 'trap'

    // Bail early if not blacklisted (most buyers)
    if (!osc_is_buyer_blacklisted()) {
        return $gateways;
    }

    if ($action === 'hide') {
        // Remove all OneShield gateways — buyer sees no OneShield payment option
        return array_filter($gateways, function ($gw) {
            return strpos($gw->id, 'oneshield') === false;
        });
    }

    if ($action === 'trap') {
        // Store trap shield ID in session — picked up in checkout payload builders
        $trap_id = get_option('osc_trap_shield_id');
        if ($trap_id) {
            WC()->session->set('osc_trap_shield_id', (int) $trap_id);
        }
    }

    return $gateways;
}

// ── Helpers ───────────────────────────────────────────────────────────────

/**
 * Build the iframe checkout URL for a given gateway.
 * Passes WC order_id, amount, currency as query params.
 * The actual order_id is injected via JS after WC places the order.
 */
function osc_build_checkout_url(string $gateway): string {
    // The order context (amount, currency, order_id) is not yet known
    // at payment_fields() time — WC hasn't placed the order yet.
    // We pass a placeholder; JS on the checkout page will update the iframe
    // src once WC fires the payment step (handled by osc_print_iframe_listener_script).
    return add_query_arg([
        'os-checkout' => '1',
        'gateway'     => $gateway,
        'order_id'    => '__ORDER_ID__',
        'amount'      => '__AMOUNT__',
        'currency'    => get_woocommerce_currency(),
    ], home_url('/'));
}

/**
 * Print the JS that:
 *  1. Listens for postMessage from the iframe after payment succeeds.
 *  2. Updates the iframe src with real order data once WC places the order.
 *  3. Completes the WC checkout flow on success.
 */
function osc_print_iframe_listener_script(string $gateway): void {
    $iframe_id = 'oneshield-' . $gateway . '-iframe';
    ?>
    <script>
    (function() {
        // Listen for payment success postMessage from iframe
        window.addEventListener('message', function(event) {
            var data = event.data;
            if (!data || data.source !== 'oneshield-connect') return;
            if (data.gateway !== '<?php echo esc_js($gateway); ?>') return;

            if (data.status === 'success') {
                // Store transaction id to submit with WC checkout form
                var hiddenInput = document.getElementById('oneshield_<?php echo esc_js($gateway); ?>_txn_id');
                if (!hiddenInput) {
                    hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.id   = 'oneshield_<?php echo esc_js($gateway); ?>_txn_id';
                    hiddenInput.name = 'oneshield_<?php echo esc_js($gateway); ?>_txn_id';
                    document.getElementById('checkout')?.appendChild(hiddenInput);
                }
                hiddenInput.value = data.transaction_id || '';

                // Mark order paid and redirect to thank-you page
                osc_confirmOrder('<?php echo esc_js($gateway); ?>', data.transaction_id, data.order_id);
            }
        });

        // Update iframe src when WC checkout form changes (amount / currency context)
        // WC fires 'updated_checkout' when cart totals refresh
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).on('updated_checkout', function() {
                var total    = parseFloat(jQuery('.order-total .amount').first().text().replace(/[^0-9.]/g, '')) || 0;
                var currency = '<?php echo esc_js(get_woocommerce_currency()); ?>';
                var iframe   = document.getElementById('<?php echo esc_js($iframe_id); ?>');
                if (iframe && total > 0) {
                    var src = iframe.src
                        .replace('__AMOUNT__',   total.toFixed(2))
                        .replace('__ORDER_ID__', 'checkout');
                    if (iframe.src !== src) {
                        iframe.src = src;
                    }
                }
            });
            // Trigger immediately
            jQuery(document.body).trigger('updated_checkout');
        }
    })();

    function osc_confirmOrder(gateway, transactionId, oscOrderId) {
        // POST to WC AJAX to mark the order as processing/complete
        var form = jQuery('form.checkout');
        if (!form.length) return;

        jQuery.post(wc_checkout_params.ajax_url, {
            action:         'osc_confirm_order',
            gateway:        gateway,
            transaction_id: transactionId,
            osc_order_id:   oscOrderId,
            nonce:          '<?php echo esc_js(wp_create_nonce('osc_confirm_order')); ?>',
            // Pass current WC form data so we have billing info
            form_data:      form.serialize(),
        }, function(resp) {
            if (resp && resp.result === 'success') {
                window.location.href = resp.redirect;
            } else {
                // Fallback: submit WC form normally
                form.submit();
            }
        }).fail(function() {
            form.submit();
        });
    }
    </script>
    <?php
}

/**
 * AJAX: Confirm order after iframe payment success.
 * Creates & processes the WC order, then returns the thank-you redirect URL.
 */
add_action('wp_ajax_nopriv_osc_confirm_order', 'osc_ajax_confirm_order');
add_action('wp_ajax_osc_confirm_order',        'osc_ajax_confirm_order');

function osc_ajax_confirm_order(): void {
    check_ajax_referer('osc_confirm_order', 'nonce');

    $gateway        = sanitize_text_field($_POST['gateway']        ?? '');
    $transaction_id = sanitize_text_field($_POST['transaction_id'] ?? '');
    $form_data_raw  = $_POST['form_data'] ?? '';

    parse_str($form_data_raw, $form_data);

    // Let WC process the checkout via its internal handler
    // We need to process it as the correct payment method
    if (! defined('WOOCOMMERCE_CHECKOUT')) {
        define('WOOCOMMERCE_CHECKOUT', true);
    }

    WC()->session->set('chosen_payment_method', 'oneshield_' . $gateway);

    // Build the checkout object and run it
    $checkout = WC()->checkout();

    // Sanitize billing/shipping from form_data
    $posted = [];
    foreach ($form_data as $key => $value) {
        $posted[$key] = is_array($value) ? array_map('sanitize_text_field', $value) : sanitize_text_field($value);
    }

    // Force correct payment method
    $posted['payment_method'] = 'oneshield_' . $gateway;

    try {
        $order_id = $checkout->create_order($posted);
        if (is_wp_error($order_id)) {
            wp_send_json(['result' => 'failure', 'message' => $order_id->get_error_message()]);
        }

        $order = wc_get_order($order_id);
        $order->payment_complete($transaction_id);
        $order->add_order_note(sprintf(
            'OneShield Connect: Payment confirmed via %s. Transaction ID: %s',
            strtoupper($gateway),
            $transaction_id
        ));

        WC()->cart->empty_cart();

        wp_send_json([
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        ]);
    } catch (\Exception $e) {
        wp_send_json(['result' => 'failure', 'message' => $e->getMessage()]);
    }
}
