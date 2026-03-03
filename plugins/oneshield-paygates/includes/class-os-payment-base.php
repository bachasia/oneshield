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
