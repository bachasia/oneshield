<?php
/**
 * Base Payment Gateway class for OneShield Paygates.
 * Extends WC_Payment_Gateway to provide shared Gateway Panel communication.
 */

defined('ABSPATH') || exit;

abstract class OS_Payment_Base extends WC_Payment_Gateway {

    protected string $gateway_name = '';
    protected string $gateway_url  = '';
    protected string $token_secret = '';
    protected string $group_id     = '';

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'oneshield-paygates'),
                'type'    => 'checkbox',
                'label'   => __('Enable this payment method', 'oneshield-paygates'),
                'default' => 'no',
            ],
            'title' => [
                'title'       => __('Title', 'oneshield-paygates'),
                'type'        => 'text',
                'description' => __('Displayed to customer at checkout.', 'oneshield-paygates'),
                'default'     => $this->get_default_title(),
                'desc_tip'    => true,
            ],
            'gateway_url' => [
                'title'       => __('Gateway Panel URL', 'oneshield-paygates'),
                'type'        => 'text',
                'description' => __('URL of your OneShield Gateway Panel.', 'oneshield-paygates'),
                'placeholder' => 'https://gateway.oneshield.io',
            ],
            'token_secret' => [
                'title'       => __('Token Secret', 'oneshield-paygates'),
                'type'        => 'password',
                'description' => __('Token Secret from your Gateway Panel (Settings page).', 'oneshield-paygates'),
            ],
            'connection_status' => [
                'title' => __('Connection Status', 'oneshield-paygates'),
                'type'  => 'connection_status',
            ],
            'group_id' => [
                'title'       => __('Group ID', 'oneshield-paygates'),
                'type'        => 'text',
                'description' => __('Optional. Route payments to a specific group of Shield Sites.', 'oneshield-paygates'),
                'default'     => '',
            ],
            'debug' => [
                'title'   => __('Debug Log', 'oneshield-paygates'),
                'type'    => 'checkbox',
                'label'   => __('Enable logging to WooCommerce log file.', 'oneshield-paygates'),
                'default' => 'no',
            ],
        ];
    }

    /**
     * Render the custom "connection_status" field type.
     * WooCommerce calls generate_{type}_html for custom field types.
     */
    public function generate_connection_status_html(string $key, array $data): string {
        $gateway_url  = $this->get_option('gateway_url', '');
        $token_secret = $this->get_option('token_secret', '');
        $nonce        = wp_create_nonce('osp_status_nonce');
        $ajax_url     = admin_url('admin-ajax.php');
        $gateway_name = strtoupper($this->gateway_name);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <?php echo esc_html($data['title']); ?>
            </th>
            <td class="forminp">
                <div id="osp-status-<?php echo esc_attr($this->id); ?>" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span id="osp-status-badge-<?php echo esc_attr($this->id); ?>"
                          style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;font-size:13px;font-weight:600;background:#f3f4f6;color:#6b7280;">
                        <span style="width:8px;height:8px;border-radius:50%;background:#9ca3af;display:inline-block;"></span>
                        <?php esc_html_e('Not checked', 'oneshield-paygates'); ?>
                    </span>
                    <button type="button"
                            id="osp-test-btn-<?php echo esc_attr($this->id); ?>"
                            class="button button-secondary"
                            style="display:flex;align-items:center;gap:6px;"
                            onclick="ospTestConnection('<?php echo esc_js($this->id); ?>', '<?php echo esc_js($ajax_url); ?>', '<?php echo esc_js($nonce); ?>')">
                        <span id="osp-test-spinner-<?php echo esc_attr($this->id); ?>" class="spinner" style="display:none;float:none;margin:0;"></span>
                        <?php esc_html_e('Test Connection', 'oneshield-paygates'); ?>
                    </button>
                    <span id="osp-status-detail-<?php echo esc_attr($this->id); ?>"
                          style="font-size:12px;color:#6b7280;"></span>
                </div>
                <p style="margin-top:6px;font-size:12px;color:#9ca3af;">
                    <?php esc_html_e('Tests the connection to your Gateway Panel using the URL and Token Secret above. Save settings first if you made changes.', 'oneshield-paygates'); ?>
                </p>
            </td>
        </tr>

        <script>
        function ospTestConnection(gatewayId, ajaxUrl, nonce) {
            var badge   = document.getElementById('osp-status-badge-'  + gatewayId);
            var detail  = document.getElementById('osp-status-detail-' + gatewayId);
            var spinner = document.getElementById('osp-test-spinner-'  + gatewayId);
            var btn     = document.getElementById('osp-test-btn-'      + gatewayId);

            // Loading state
            spinner.style.display = 'inline-block';
            btn.disabled = true;
            badge.innerHTML = '<span style="width:8px;height:8px;border-radius:50%;background:#9ca3af;display:inline-block;"></span> Checking&hellip;';
            badge.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;font-size:13px;font-weight:600;background:#f3f4f6;color:#6b7280;';
            detail.textContent = '';

            jQuery.post(ajaxUrl, {
                action:     'osp_test_connection',
                gateway_id: gatewayId,
                nonce:      nonce,
            }, function(resp) {
                spinner.style.display = 'none';
                btn.disabled = false;

                if (resp && resp.success && resp.data && resp.data.ok) {
                    var d = resp.data;
                    badge.innerHTML = '<span style="width:8px;height:8px;border-radius:50%;background:#16a34a;display:inline-block;"></span> Connected';
                    badge.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;font-size:13px;font-weight:600;background:#dcfce7;color:#15803d;';
                    detail.textContent = d.account.name + ' · ' + d.sites.stripe + ' Stripe / ' + d.sites.paypal + ' PayPal site(s) active · ' + d.sites.online + ' online';
                } else {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not connect. Check Gateway URL and Token Secret.';
                    badge.innerHTML = '<span style="width:8px;height:8px;border-radius:50%;background:#dc2626;display:inline-block;"></span> Failed';
                    badge.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;font-size:13px;font-weight:600;background:#fee2e2;color:#dc2626;';
                    detail.textContent = msg;
                }
            }).fail(function() {
                spinner.style.display = 'none';
                btn.disabled = false;
                badge.innerHTML = '<span style="width:8px;height:8px;border-radius:50%;background:#dc2626;display:inline-block;"></span> Request failed';
                badge.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;font-size:13px;font-weight:600;background:#fee2e2;color:#dc2626;';
                detail.textContent = 'Network error — could not reach the server.';
            });
        }
        </script>
        <?php
        return ob_get_clean();
    }

    abstract protected function get_default_title(): string;

    /**
     * Render the checkout iframe directly inside the WC payment section.
     *
     * Called from payment_fields() — runs PHP-side (synchronous) when the
     * checkout page loads. Calls the Gateway Panel immediately to get the
     * iframe URL (shield site selected by rotation/config), then renders
     * the iframe + hidden inputs that process_payment() will read later.
     *
     * @param string $gateway 'stripe' or 'paypal'
     */
    protected function render_iframe_field(string $gateway): void {
        // Description text
        if ($desc = $this->get_option('description')) {
            echo '<p style="margin-bottom:8px;">' . wp_kses_post($desc) . '</p>';
        }

        if (empty($this->gateway_url) || empty($this->token_secret)) {
            echo '<p style="color:#dc2626;font-size:13px;">'
               . esc_html__('Payment gateway is not configured. Please contact the store owner.', 'oneshield-paygates')
               . '</p>';
            return;
        }

        // Use a temporary order_id placeholder — WC hasn't created the order yet.
        // The actual WC order ID is set after process_payment(); the gateway panel
        // uses its own internal transaction_id to track the payment session.
        $temp_order_id = 'checkout-' . uniqid();

        $payload = [
            'gateway'  => $gateway,
            'order_id' => $temp_order_id,
            'amount'   => (float) WC()->cart->get_total('raw'),
            'currency' => get_woocommerce_currency(),
            'group_id' => $this->group_id ?: null,
        ];

        $result = $this->get_iframe_url_from_payload($payload);

        if (!$result || empty($result['iframe_url'])) {
            echo '<p style="color:#dc2626;font-size:13px;">'
               . esc_html__('Payment service temporarily unavailable. Please refresh the page or try again shortly.', 'oneshield-paygates')
               . '</p>';

            // Show detailed error to logged-in admins only
            if (current_user_can('manage_woocommerce') && $this->last_error) {
                echo '<p style="color:#9ca3af;font-size:11px;margin-top:4px;">'
                   . '<strong>Admin debug:</strong> ' . esc_html($this->last_error)
                   . '</p>';
            }
            return;
        }

        $iframe_url  = esc_url($result['iframe_url']);
        $os_txn_id   = esc_attr((string) ($result['transaction_id'] ?? ''));
        $os_site_id  = esc_attr((string) ($result['site_id'] ?? ''));
        $field_prefix = 'osp_' . $gateway;
        $iframe_height = $gateway === 'paypal' ? '300' : '440';
        ?>
        <div class="osp-iframe-wrap" style="position:relative;margin-top:8px;">

            <iframe
                id="osp-iframe-<?php echo esc_attr($gateway); ?>"
                src="<?php echo $iframe_url; ?>"
                style="width:100%;height:<?php echo $iframe_height; ?>px;border:1px solid #e5e7eb;border-radius:8px;display:block;"
                scrolling="no"
                allow="payment"
            ></iframe>

            <div id="osp-iframe-loading-<?php echo esc_attr($gateway); ?>"
                 style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:#f9fafb;border-radius:8px;font-size:13px;color:#6b7280;">
                <?php esc_html_e('Loading payment form…', 'oneshield-paygates'); ?>
            </div>

        </div>

        <!-- Hidden inputs written by JS after postMessage from iframe -->
        <input type="hidden" name="<?php echo $field_prefix; ?>_transaction_id"    id="<?php echo $field_prefix; ?>_transaction_id"    value="" />
        <input type="hidden" name="<?php echo $field_prefix; ?>_os_transaction_id" id="<?php echo $field_prefix; ?>_os_transaction_id" value="<?php echo $os_txn_id; ?>" />
        <input type="hidden" name="<?php echo $field_prefix; ?>_os_site_id"        id="<?php echo $field_prefix; ?>_os_site_id"        value="<?php echo $os_site_id; ?>" />
        <?php
    }

    /**
     * Call Gateway Panel to get an iframe URL for a given payload.
     * Extracted so it can be reused without a WC_Order instance.
     */
    /** @var string|null Last error from get_iframe_url_from_payload() for display. */
    protected ?string $last_error = null;

    protected function get_iframe_url_from_payload(array $payload): ?array {
        $url = rtrim($this->gateway_url, '/') . '/api/paygates/get-site';

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => $this->sign_request($payload),
            'body'    => json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            $this->last_error = 'Network error: ' . $response->get_error_message();
            $this->log('get-site error: ' . $this->last_error);
            return null;
        }

        $code     = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $body     = json_decode($raw_body, true);

        if ($code >= 400) {
            $api_error = $body['error'] ?? $raw_body;
            $debug_hint = '';
            if (!empty($body['debug'])) {
                $d = $body['debug'];
                $debug_hint = sprintf(
                    ' [total=%s, active=%s, gw_enabled=%s, online=%s | hint: %s]',
                    $d['total_sites']           ?? '?',
                    $d['active_sites']          ?? '?',
                    $d['gateway_enabled_sites'] ?? '?',
                    $d['online_sites']          ?? '?',
                    $d['hint']                  ?? '-'
                );
            }
            $this->last_error = 'HTTP ' . $code . ': ' . $api_error . $debug_hint;
            $this->log('get-site HTTP ' . $code . ': ' . $api_error . $debug_hint);
            return null;
        }

        return $body;
    }

    protected function load_settings(): void {
        $this->gateway_url  = $this->get_option('gateway_url', '');
        $this->token_secret = $this->get_option('token_secret', '');
        $this->group_id     = $this->get_option('group_id', '');
    }

    /**
     * Sign a request payload for Gateway Panel API.
     */
    protected function sign_request(array $payload): array {
        $timestamp = time();
        $message   = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $timestamp;
        $signature = hash_hmac('sha256', $message, $this->token_secret);

        return [
            'Content-Type'          => 'application/json',
            'X-OneShield-Signature' => $signature,
            'X-OneShield-Timestamp' => (string) $timestamp,
            'X-OneShield-Token'     => $this->token_secret,
        ];
    }

    /**
     * Call Gateway Panel: get-site and return iframe_url.
     */
    protected function get_iframe_url(\WC_Order $order): ?array {
        $payload = [
            'gateway'  => $this->gateway_name,
            'order_id' => (string) $order->get_id(),
            'amount'   => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'group_id' => $this->group_id ?: null,
        ];

        $response = wp_remote_post(rtrim($this->gateway_url, '/') . '/api/paygates/get-site', [
            'timeout' => 15,
            'headers' => $this->sign_request($payload),
            'body'    => json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            $this->log('get-site error: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $this->log('get-site HTTP ' . $code . ': ' . ($body['error'] ?? 'unknown'));
            return null;
        }

        return $body; // { site_id, transaction_id, iframe_url, token }
    }

    /**
     * Confirm payment with Gateway Panel after postMessage received.
     *
     * @param int    $os_site_id    ShieldSite ID (stored as _os_site_id on WC order)
     * @param int    $wc_order_id   WooCommerce order ID (used as order_id in Gateway Panel)
     * @param string $gateway_tx_id Gateway transaction ID (PayPal capture ID / Stripe PI ID)
     */
    public function confirm_with_panel(int $os_site_id, int $wc_order_id, string $gateway_tx_id): bool {
        $payload = [
            'site_id'                => $os_site_id,
            'order_id'               => (string) $wc_order_id,
            'gateway_transaction_id' => $gateway_tx_id,
            'status'                 => 'completed',
        ];

        $response = wp_remote_post(rtrim($this->gateway_url, '/') . '/api/paygates/confirm', [
            'timeout' => 15,
            'headers' => $this->sign_request($payload),
            'body'    => json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return (bool) ($body['success'] ?? false);
    }

    protected function log(string $message): void {
        if ($this->get_option('debug') === 'yes') {
            wc_get_logger()->info('[OneShield ' . strtoupper($this->gateway_name) . '] ' . $message, ['source' => 'oneshield-paygates']);
        }
    }
}
