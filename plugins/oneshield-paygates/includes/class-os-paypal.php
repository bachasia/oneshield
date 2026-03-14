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
            'invoice_prefix' => [
                'title'       => __('Invoice Prefix', 'oneshield-paygates'),
                'type'        => 'text',
                'description' => __('Prefix added to the invoice ID sent to PayPal. E.g. "N167-FAN" → invoice ID will be "N167-FAN-{order_id}".', 'oneshield-paygates'),
                'default'     => '',
                'desc_tip'    => false,
            ],
            'overwrite_product_title' => [
                'title'       => __('Overwrite product title', 'oneshield-paygates'),
                'type'        => 'select',
                'description' => __('Choose how to display the product title in PayPal transaction details.', 'oneshield-paygates'),
                'default'     => 'keep_original',
                'desc_tip'    => false,
                'options'     => [
                    'use_last_word'    => __('Use the last word', 'oneshield-paygates'),
                    'user_define'      => __('User define', 'oneshield-paygates'),
                    'keep_original'    => __('Keep the original (Not recommended)', 'oneshield-paygates'),
                ],
            ],
            'user_define_title' => [
                'title'       => __('User define title', 'oneshield-paygates'),
                'type'        => 'text',
                'description' => __('This will be appeared on PayPal transaction as product title, when overwrite product title is "User define". You can define title with <strong>[order_id]</strong> or <strong>[last_word]</strong> or <strong>[rand_title_from_list]</strong> and <strong>[rand_N]</strong> (random a N length string, N is a number > 1) shortcode.<br>For example: Order #[order_id] or [rand_10] product or [last_word] product.', 'oneshield-paygates'),
                'default'     => '[order_id] [rand_12] item',
                'desc_tip'    => false,
            ],
            'random_title_list' => [
                'title'       => __('Random title list', 'oneshield-paygates'),
                'type'        => 'textarea',
                'description' => __('Please enter a list of titles to randomize, separated by commas. For example: T-Shirt, Personalized Hoodie, Gift for dad', 'oneshield-paygates'),
                'default'     => '',
                'desc_tip'    => false,
                'css'         => 'height:80px;',
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

    /**
     * Custom HTML renderer for the 'user_define_title' and 'random_title_list' fields
     * so we can add show/hide JS based on the 'overwrite_product_title' select.
     */
    public function generate_user_define_title_html(string $key, array $data): string {
        $field_key = $this->get_field_key($key);
        $value     = $this->get_option($key, $data['default'] ?? '');
        ob_start();
        ?>
        <tr valign="top" class="osp-paypal-user-define-row">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($data['title']); ?></label>
            </th>
            <td class="forminp">
                <input type="text"
                       name="<?php echo esc_attr($field_key); ?>"
                       id="<?php echo esc_attr($field_key); ?>"
                       value="<?php echo esc_attr($value); ?>"
                       style="width:100%;max-width:460px;"
                       placeholder="[order_id] [rand_12] item"
                />
                <p class="description"><?php echo wp_kses_post($data['description'] ?? ''); ?></p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    public function generate_random_title_list_html(string $key, array $data): string {
        $field_key = $this->get_field_key($key);
        $value     = $this->get_option($key, $data['default'] ?? '');
        ob_start();
        ?>
        <tr valign="top" class="osp-paypal-random-list-row">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($data['title']); ?></label>
            </th>
            <td class="forminp">
                <textarea
                    name="<?php echo esc_attr($field_key); ?>"
                    id="<?php echo esc_attr($field_key); ?>"
                    style="width:100%;max-width:460px;height:80px;"
                    placeholder="Vintage Design, Birthday Gift, Personalized, New Collection"
                ><?php echo esc_textarea($value); ?></textarea>
                <p class="description"><?php echo esc_html($data['description'] ?? ''); ?></p>
            </td>
        </tr>
        <script>
        (function() {
            var selectId = '<?php echo esc_js($this->get_field_key('overwrite_product_title')); ?>';
            var userDefineRows = document.querySelectorAll('.osp-paypal-user-define-row, .osp-paypal-random-list-row');

            function toggleRows() {
                var sel = document.getElementById(selectId);
                if (!sel) return;
                var show = (sel.value === 'user_define');
                userDefineRows.forEach(function(row) {
                    row.style.display = show ? '' : 'none';
                });
            }

            document.addEventListener('DOMContentLoaded', function() {
                var sel = document.getElementById(selectId);
                if (sel) {
                    toggleRows();
                    sel.addEventListener('change', toggleRows);
                }
            });
        })();
        </script>
        <?php
        return ob_get_clean();
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
            // Temp debug: always show raw error so we can diagnose on production
            if ($this->last_error) {
                echo '<p style="color:#9ca3af;font-size:11px;margin-top:4px;word-break:break-all;">'
                   . '<strong>[Debug]</strong> ' . esc_html($this->last_error)
                   . '</p>';
            }
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
     *
     * NOTE: We do NOT cache the full result (including iframe_url / checkout_id)
     * in WC session because the CheckoutSession expires after 30 min. Always fetch
     * a fresh URL from the Panel — the Panel's idempotency logic returns the existing
     * active session, or creates a new one if the old one expired.
     *
     * Within a single PHP request (payment_fields + after-submit hook both call this),
     * we use a static variable to avoid a second HTTP round-trip.
     */
    public function get_paypal_iframe_result(): ?array {
        static $request_cache = null;

        if ($request_cache !== null) {
            return $request_cache;
        }

        if (empty($this->gateway_url) || empty($this->token_secret)) {
            return null;
        }

        $cart_fingerprint = md5(json_encode([
            (float) WC()->cart->get_total('raw'),
            get_woocommerce_currency(),
            WC()->cart->get_cart_hash(),
        ]));

        // Reuse the same temp_order_id for the same cart (avoids creating a new
        // CheckoutSession on every refresh), but always fetch a fresh iframe URL.
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

        $request_cache = $this->get_iframe_url_from_payload($payload);
        return $request_cache;
    }

    /**
     * Override to include PayPal order info settings in extra_params passed to the iframe.
     */
    protected function get_iframe_extra_params(): array {
        $params = parent::get_iframe_extra_params();

        // Only include non-empty values to keep payload clean.
        $invoice_prefix = $this->get_option('invoice_prefix', '');
        if ($invoice_prefix !== '') {
            $params['invoice_prefix'] = $invoice_prefix;
        }

        $title_mode = $this->get_option('overwrite_product_title', 'keep_original');
        if ($title_mode !== 'keep_original') {
            $params['overwrite_product_title'] = $title_mode;
        }

        if ($title_mode === 'user_define') {
            $user_define_title = $this->get_option('user_define_title', '');
            if ($user_define_title !== '') {
                $params['user_define_title'] = $user_define_title;
            }
            $random_title_list = $this->get_option('random_title_list', '');
            if ($random_title_list !== '') {
                $params['random_title_list'] = $random_title_list;
            }
        }

        // Pass the first product name from the cart for title shortcodes ([last_word])
        $product_name = $this->get_cart_first_product_name();
        if ($product_name !== '') {
            $params['product_name'] = $product_name;
        }

        // Pass shipping total so Shield Site can build the correct PayPal breakdown
        $shipping_total = (float) WC()->cart->get_shipping_total();
        if ($shipping_total > 0) {
            $params['shipping_total'] = number_format($shipping_total, 2, '.', '');
        }

        return $params;
    }

    /**
     * Get the name of the first product in the WooCommerce cart.
     */
    private function get_cart_first_product_name(): string {
        if (!WC()->cart) {
            return '';
        }
        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if ($product && method_exists($product, 'get_name')) {
                return $product->get_name();
            }
        }
        return '';
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
        $order           = wc_get_order($order_id);
        $txn_id          = sanitize_text_field($_POST['osp_paypal_transaction_id']    ?? '');
        $os_txn_id       = sanitize_text_field($_POST['osp_paypal_os_transaction_id'] ?? '');
        $os_site_id      = (int) ($_POST['osp_paypal_os_site_id']                     ?? 0);
        $os_checkout_id  = sanitize_text_field($_POST['osp_paypal_os_checkout_id']    ?? '');
        $paypal_order_id = sanitize_text_field($_POST['osp_paypal_paypal_order_id']   ?? '');

        if (empty($txn_id)) {
            wc_add_notice(__('Payment not completed. Please try again.', 'oneshield-paygates'), 'error');
            return ['result' => 'failure'];
        }

        // ── Collect billing for Panel ─────────────────────────────────────────
        if (!empty($os_checkout_id)) {
            $billing = array_filter([
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'state'      => $order->get_billing_state(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
            ]);

            if (!empty($billing)) {
                $shipping = array_filter([
                    'first_name' => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
                    'last_name'  => $order->get_shipping_last_name()  ?: $order->get_billing_last_name(),
                    'address_1'  => $order->get_shipping_address_1()  ?: $order->get_billing_address_1(),
                    'address_2'  => $order->get_shipping_address_2()  ?: $order->get_billing_address_2(),
                    'city'       => $order->get_shipping_city()        ?: $order->get_billing_city(),
                    'state'      => $order->get_shipping_state()       ?: $order->get_billing_state(),
                    'postcode'   => $order->get_shipping_postcode()    ?: $order->get_billing_postcode(),
                    'country'    => $order->get_shipping_country()     ?: $order->get_billing_country(),
                ]);
                $this->send_billing_to_panel(0, $billing, $os_checkout_id, $shipping);
            }

            $this->complete_checkout_session($os_checkout_id, $txn_id, (string) $order->get_id());
        }

        // Legacy confirm
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

        if (!empty($paypal_order_id)) {
            $order->update_meta_data('_osp_paypal_order_id', $paypal_order_id);
            $order->save();
        }

        if (!empty($os_checkout_id)) {
            $order->update_meta_data('_os_checkout_id', $os_checkout_id);
            $order->save();
        }

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

    /**
     * Patch PayPal order invoice_id with real WC order ID via Shield Site AJAX.
     * Fire-and-forget — failure here is non-fatal, only affects PayPal dashboard display.
     */
    private function patch_paypal_invoice(string $paypal_order_id, string $invoice_id): void {
        // Get shield site URL from session (set during payment_fields rendering)
        $shield_url = '';
        if (WC()->session) {
            $shield_url = (string) WC()->session->get('osp_paypal_shield_url', '');
        }
        if (empty($shield_url)) {
            $this->log('patch_paypal_invoice: no shield_url in session');
            return;
        }

        $ajax_url = rtrim($shield_url, '/') . '/wp-admin/admin-ajax.php';

        $response = wp_remote_post($ajax_url, [
            'timeout' => 8,
            'body'    => [
                'action'          => 'osc_patch_paypal_invoice',
                'paypal_order_id' => $paypal_order_id,
                'invoice_id'      => $invoice_id,
            ],
        ]);

        if (is_wp_error($response)) {
            $this->log('patch_paypal_invoice error: ' . $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->log('patch_paypal_invoice HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
        }
    }

    /**
     * Mark checkout session as completed via Gateway Panel API.
     */
    public function complete_checkout_session(string $checkout_id, string $gateway_txn_id, string $wc_order_id = ''): void {
        if (empty($this->gateway_url) || empty($this->token_secret)) {
            return;
        }

        $payload = [
            'gateway_transaction_id' => $gateway_txn_id,
        ];

        if (!empty($wc_order_id)) {
            $payload['wc_order_id'] = $wc_order_id;
        }

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
