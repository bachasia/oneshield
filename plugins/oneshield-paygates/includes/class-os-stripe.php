<?php
/**
 * OneShield Stripe Payment Gateway.
 */

defined('ABSPATH') || exit;

class OS_Stripe_Gateway extends OS_Payment_Base {

    public string $gateway_name = 'stripe';

    public function __construct() {
        $this->id                 = 'os_stripe';
        $this->method_title       = __('OneShield Stripe', 'oneshield-paygates');
        $this->method_description = __('Credit Card payment via Stripe.', 'oneshield-paygates');
        $this->has_fields         = true;
        $this->supports           = ['products'];

        $this->init_form_fields();
        $this->init_settings();
        $this->load_settings();

        $this->title       = $this->get_option('title', 'Credit Card');
        $this->description = $this->get_option('description', 'Pay with your credit card via Stripe.');
        $this->icon        = $this->get_card_icons_html();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    protected function get_default_title(): string {
        return __('Credit Card', 'oneshield-paygates');
    }

    protected function get_default_description(): string {
        return __('Pay with your credit card via Stripe.', 'oneshield-paygates');
    }

    /**
     * Stripe-specific form fields added after the shared base fields.
     */
    protected function get_gateway_form_fields(): array {
        return [
            'capture_method' => [
                'title'       => __('Capture Method', 'oneshield-paygates'),
                'type'        => 'select',
                'description' => __('Automatic captures the payment immediately. Manual authorizes and captures later.', 'oneshield-paygates'),
                'default'     => 'automatic',
                'desc_tip'    => true,
                'options'     => [
                    'automatic' => __('Automatic', 'oneshield-paygates'),
                    'manual'    => __('Manual', 'oneshield-paygates'),
                ],
            ],
            'enable_wallets' => [
                'title'       => __('Enable Wallets', 'oneshield-paygates'),
                'type'        => 'checkbox',
                'label'       => __('Enable wallets ApplePay, GooglePay, Amazon Pay, etc.', 'oneshield-paygates'),
                'default'     => 'yes',
                'desc_tip'    => true,
                'description' => __('When enabled, customers can pay with digital wallets if available.', 'oneshield-paygates'),
            ],
            'statement_descriptor' => [
                'title'       => __('Statement Descriptor', 'oneshield-paygates'),
                'type'        => 'text',
                'description' => __('Provides information about a card payment that customers see on their statements. Concatenated with the prefix (shortened descriptor) or statement descriptor that\'s set on the account to form the complete statement descriptor. The concatenated descriptor must contain 1-22 characters.', 'oneshield-paygates'),
                'default'     => '',
                'placeholder' => get_bloginfo('name'),
            ],
            'description_format' => [
                'title'       => __('Description Format', 'oneshield-paygates'),
                'type'        => 'text',
                'description' => __(
                    'The order description format on Stripe. Available shortcodes:<br/>'
                    . '[order_id] : Order ID<br/>'
                    . '[first_name] : Firstname of customer<br/>'
                    . '[last_name] : Lastname of customer<br/>'
                    . '[rand_str] : Random n characters.<br/>'
                    . '[merchant_site] : Domain of the money site (e.g. example.com)<br/>'
                    . 'Leave blank to use OneShield default format.',
                    'oneshield-paygates'
                ),
                'default'     => '',
                'placeholder' => 'Order #[order_id] - [first_name] [last_name]',
            ],
            'send_billing' => [
                'title'       => __('Send Billing Address', 'oneshield-paygates'),
                'type'        => 'checkbox',
                'label'       => __('Send billing address to Stripe', 'oneshield-paygates'),
                'default'     => 'yes',
                'desc_tip'    => true,
                'description' => __('Include customer billing info in the payment request.', 'oneshield-paygates'),
            ],
            'test_mode' => [
                'title'       => __('Test Mode', 'oneshield-paygates'),
                'type'        => 'checkbox',
                'label'       => __('Enable test mode', 'oneshield-paygates'),
                'default'     => 'no',
                'desc_tip'    => true,
                'description' => __('When enabled, payments will be processed in test/sandbox mode.', 'oneshield-paygates'),
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

    /**
     * Extra parameters to pass via gateway panel to the iframe URL.
     */
    protected function get_iframe_extra_params(): array {
        $params = parent::get_iframe_extra_params();

        $capture = $this->get_option('capture_method', 'automatic');
        if ($capture) {
            $params['capture_method'] = $capture;
        }

        if ($this->get_option('enable_wallets', 'yes') === 'yes') {
            $params['enable_wallets'] = '1';
        }

        $descriptor = $this->get_option('statement_descriptor', '');
        if ($descriptor) {
            $params['statement_descriptor'] = $descriptor;
        }

        $desc_format = $this->get_option('description_format', '');
        if ($desc_format) {
            $params['description_format'] = $desc_format;
        }

        // Pass send_billing flag so the iframe knows whether to fetch billing
        // from the Gateway Panel and attach it to the PaymentIntent.
        if ($this->get_option('send_billing', 'yes') === 'yes') {
            $params['send_billing'] = 'yes';
        }

        return $params;
    }

    /**
     * Card brand icons HTML for the gateway title.
     */
    private function get_card_icons_html(): string {
        $icons_url = plugin_dir_url(dirname(__FILE__)) . 'assets/images/';
        // Use inline SVG-style or WC default icons
        return '';
    }

    /**
     * Render the payment iframe directly inside the checkout payment section.
     */
    public function payment_fields(): void {
        $this->render_iframe_field('stripe');
    }

    /**
     * Validate that the payment has been completed inside the iframe.
     */
    public function validate_fields(): bool {
        $txn_id = sanitize_text_field($_POST['osp_stripe_transaction_id'] ?? '');

        if (empty($txn_id)) {
            wc_add_notice(__('Please complete the card payment in the form above before placing the order.', 'oneshield-paygates'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Process payment after iframe confirms success.
     */
    public function process_payment($order_id) {
        $order          = wc_get_order($order_id);
        $txn_id         = sanitize_text_field($_POST['osp_stripe_transaction_id']    ?? '');
        $os_txn_id      = sanitize_text_field($_POST['osp_stripe_os_transaction_id'] ?? '');
        $os_site_id     = (int) ($_POST['osp_stripe_os_site_id']   ?? 0);
        $os_checkout_id = sanitize_text_field($_POST['osp_stripe_os_checkout_id']    ?? '');

        if (empty($txn_id)) {
            wc_add_notice(__('Payment not completed. Please try again.', 'oneshield-paygates'), 'error');
            return ['result' => 'failure'];
        }

        // Phase 1/2: if checkout_id present, complete the session (idempotent)
        if (!empty($os_checkout_id)) {
            $this->complete_checkout_session($os_checkout_id, $txn_id);
        }

        // Legacy confirm (always run for backward compatibility / webhook reconciliation)
        if ($os_site_id && $os_txn_id) {
            $confirmed = $this->confirm_with_panel($os_site_id, $order->get_id(), $txn_id);
            if (!$confirmed) {
                $this->log('confirm_with_panel failed for txn ' . $txn_id);
            }
        }

        $order->payment_complete($txn_id);
        $order->add_order_note(sprintf(
            'OneShield: Stripe payment completed. Transaction ID: %s',
            $txn_id
        ));

        if (!empty($os_checkout_id)) {
            $order->update_meta_data('_os_checkout_id', $os_checkout_id);
            $order->save();

            // Patch Stripe PI metadata with the real WC order ID (fire-and-forget).
            // The iframe received a temp checkout-uuid as order_id; this corrects it
            // on the Stripe dashboard so operators can cross-reference by WC order number.
            $this->patch_pi_order_id($os_checkout_id, (int) $order_id);
        }

        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        ];
    }

    /**
     * Mark checkout session as completed via Gateway Panel API.
     */
    private function complete_checkout_session(string $checkout_id, string $gateway_txn_id): void {
        if (empty($this->gateway_url) || empty($this->token_secret)) {
            return;
        }

        $payload = [
            'gateway_transaction_id'   => $gateway_txn_id,
            'stripe_payment_intent_id' => $gateway_txn_id,
        ];

        $response = wp_remote_post(
            rtrim($this->gateway_url, '/') . '/api/checkout-sessions/' . rawurlencode($checkout_id) . '/complete',
            [
                'timeout' => 10,
                'headers' => $this->sign_request($payload),
                'body'    => json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            $this->log('complete_checkout_session error: ' . $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->log('complete_checkout_session HTTP ' . $code);
        }
    }
}
