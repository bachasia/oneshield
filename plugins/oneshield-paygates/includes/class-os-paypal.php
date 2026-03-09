<?php
/**
 * OneShield PayPal Payment Gateway.
 */

defined('ABSPATH') || exit;

class OS_PayPal_Gateway extends OS_Payment_Base {

    public string $gateway_name = 'paypal';

    public function __construct() {
        $this->id                 = 'os_paypal';
        $this->method_title       = __('OneShield PayPal', 'oneshield-paygates');
        $this->method_description = __('PayPal payment via OneShield Shield Sites.', 'oneshield-paygates');
        $this->has_fields         = true;
        $this->supports           = ['products'];

        $this->init_form_fields();
        $this->init_settings();
        $this->load_settings();

        $this->title       = $this->get_option('title', 'PayPal');
        $this->description = $this->get_option('description', 'Pay securely with your PayPal account.');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    protected function get_default_title(): string {
        return __('PayPal', 'oneshield-paygates');
    }

    protected function get_default_description(): string {
        return __('Pay securely with your PayPal account.', 'oneshield-paygates');
    }

    /**
     * PayPal-specific form fields.
     */
    protected function get_gateway_form_fields(): array {
        return [
            'send_billing' => [
                'title'       => __('Send Billing Address', 'oneshield-paygates'),
                'type'        => 'checkbox',
                'label'       => __('Send billing address to PayPal', 'oneshield-paygates'),
                'default'     => 'yes',
                'desc_tip'    => true,
                'description' => __('Include customer billing info in the PayPal payment request.', 'oneshield-paygates'),
            ],
            'test_mode' => [
                'title'       => __('Test Mode', 'oneshield-paygates'),
                'type'        => 'checkbox',
                'label'       => __('Enable test mode (sandbox)', 'oneshield-paygates'),
                'default'     => 'no',
                'desc_tip'    => true,
                'description' => __('When enabled, payments will be processed in PayPal sandbox mode.', 'oneshield-paygates'),
            ],
            'debug' => [
                'title'       => __('Debug Log', 'oneshield-paygates'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'oneshield-paygates'),
                'default'     => 'no',
                'desc_tip'    => true,
                'description' => __('Log events to WooCommerce → Status → Logs.', 'oneshield-paygates'),
            ],
        ];
    }

    public function payment_fields(): void {
        // Render only hidden inputs here. The actual iframe is rendered
        // outside the payment box (via woocommerce_review_order_after_submit)
        // so WC's updated_checkout does not destroy it.
        $result = $this->get_paypal_iframe_result();
        if (!$result || empty($result['iframe_url'])) {
            echo '<p style="color:#dc2626;font-size:13px;">'
               . esc_html__('Payment service temporarily unavailable. Please try again shortly.', 'oneshield-paygates')
               . '</p>';
            return;
        }

        $os_txn_id      = esc_attr((string) ($result['transaction_id'] ?? ''));
        $os_site_id     = esc_attr((string) ($result['site_id'] ?? ''));
        $os_checkout_id = esc_attr((string) ($result['checkout_id'] ?? ''));
        $iframe_url     = esc_attr($result['iframe_url']);

        // Save shield domain in session for order meta
        $parsed = wp_parse_url($result['iframe_url']);
        $shield_domain = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        if (WC()->session && !empty($shield_domain)) {
            WC()->session->set('osp_paypal_shield_url', $shield_domain);
        }

        // Hidden inputs for process_payment()
        echo '<input type="hidden" name="osp_paypal_transaction_id"    id="osp_paypal_transaction_id"    value="" />';
        echo '<input type="hidden" name="osp_paypal_os_transaction_id" id="osp_paypal_os_transaction_id" value="' . $os_txn_id . '" />';
        echo '<input type="hidden" name="osp_paypal_os_site_id"        id="osp_paypal_os_site_id"        value="' . $os_site_id . '" />';
        echo '<input type="hidden" name="osp_paypal_os_checkout_id"    id="osp_paypal_os_checkout_id"    value="' . $os_checkout_id . '" />';

        // Store iframe URL so the outside iframe container can use it
        echo '<span id="osp-paypal-iframe-src" data-src="' . $iframe_url . '" style="display:none;"></span>';
    }

    /**
     * Get iframe result for PayPal, with session-based fingerprinting to avoid
     * re-creating a new order on every page refresh (same as render_iframe_field).
     * Result is cached in WC session so the after-submit hook does not make a
     * second Panel request on the same page load.
     */
    public function get_paypal_iframe_result(): ?array {
        if (empty($this->gateway_url) || empty($this->token_secret)) {
            return null;
        }

        $cart_fingerprint = md5(json_encode([
            (float) WC()->cart->get_total('raw'),
            get_woocommerce_currency(),
            WC()->cart->get_cart_hash(),
        ]));

        // Return cached result if fingerprint matches
        if (WC()->session) {
            $cached_fp  = (string) WC()->session->get('osp_paypal_result_fp', '');
            $cached_raw = WC()->session->get('osp_paypal_result_cache', null);
            if ($cached_fp === $cart_fingerprint && is_array($cached_raw)) {
                return $cached_raw;
            }
        }

        $temp_order_id = '';
        if (WC()->session) {
            $saved_id = (string) WC()->session->get('osp_paypal_temp_order_id', '');
            $saved_fp = (string) WC()->session->get('osp_paypal_temp_order_fp', '');
            if (!empty($saved_id) && $saved_fp === $cart_fingerprint) {
                $temp_order_id = $saved_id;
            } else {
                $temp_order_id = 'checkout-' . wp_generate_uuid4();
                WC()->session->set('osp_paypal_temp_order_id', $temp_order_id);
                WC()->session->set('osp_paypal_temp_order_fp', $cart_fingerprint);
            }
        }
        if (empty($temp_order_id)) {
            $temp_order_id = 'checkout-' . wp_generate_uuid4();
        }

        $payload = [
            'gateway'         => 'paypal',
            'order_id'        => $temp_order_id,
            'amount'          => (float) WC()->cart->get_total('raw'),
            'currency'        => get_woocommerce_currency(),
            'group_id'        => $this->group_id ?: null,
            'idempotency_key' => 'osp:paypal:' . $temp_order_id,
            'meta'            => [
                'money_site_domain' => parse_url(home_url(), PHP_URL_HOST),
            ],
            'extra_params'    => $this->get_iframe_extra_params(),
        ];

        $result = $this->get_iframe_url_from_payload($payload);

        // Cache result in session so the after-submit hook reuses it
        if ($result && WC()->session) {
            WC()->session->set('osp_paypal_result_cache', $result);
            WC()->session->set('osp_paypal_result_fp', $cart_fingerprint);
        }

        return $result;
    }

    public function validate_fields(): bool {
        $txn_id = sanitize_text_field($_POST['osp_paypal_transaction_id'] ?? '');

        if (empty($txn_id)) {
            wc_add_notice(__('Please complete the PayPal payment in the form above before placing the order.', 'oneshield-paygates'), 'error');
            return false;
        }

        return true;
    }

    public function process_payment($order_id) {
        $order      = wc_get_order($order_id);
        $txn_id     = sanitize_text_field($_POST['osp_paypal_transaction_id']     ?? '');
        $os_txn_id  = sanitize_text_field($_POST['osp_paypal_os_transaction_id']  ?? '');
        $os_site_id = (int) ($_POST['osp_paypal_os_site_id'] ?? 0);

        if (empty($txn_id)) {
            wc_add_notice(__('Payment not completed. Please try again.', 'oneshield-paygates'), 'error');
            return ['result' => 'failure'];
        }

        if ($os_site_id && $os_txn_id) {
            $confirmed = $this->confirm_with_panel($os_site_id, $order->get_id(), $txn_id);
            if (!$confirmed) {
                $this->log('confirm_with_panel failed for txn ' . $txn_id);
            }
        }

        $order->payment_complete($txn_id);
        $order->add_order_note(sprintf(
            'OneShield: PayPal payment completed. Transaction ID: %s',
            $txn_id
        ));

        // Persist the shield site URL on the order for display in the orders list.
        if (WC()->session) {
            $shield_url = (string) WC()->session->get('osp_paypal_shield_url', '');
            if (!empty($shield_url)) {
                $order->update_meta_data('_os_shield_url', $shield_url);
                $order->update_meta_data('_os_shield_gateway', 'paypal');
                $order->save();
                WC()->session->__unset('osp_paypal_shield_url');
            }
        }

        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        ];
    }
}
