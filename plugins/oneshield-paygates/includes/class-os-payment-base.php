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
