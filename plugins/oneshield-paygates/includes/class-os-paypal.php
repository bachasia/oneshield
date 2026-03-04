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
        $this->method_description = __('Accept PayPal payments via OneShield Shield Sites.', 'oneshield-paygates');
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

    public function payment_fields(): void {
        echo '<div id="os-paypal-container" data-gateway="paypal"></div>';
        echo '<p class="os-loading" style="text-align:center;color:#888;">' . esc_html__('Loading PayPal...', 'oneshield-paygates') . '</p>';
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        $result = $this->get_iframe_url($order);
        if (!$result) {
            wc_add_notice(__('PayPal service temporarily unavailable. Please try again.', 'oneshield-paygates'), 'error');
            return ['result' => 'failure'];
        }

        $order->update_meta_data('_os_transaction_id', $result['transaction_id']);
        $order->update_meta_data('_os_site_id', $result['site_id']);
        $order->update_meta_data('_os_iframe_url', $result['iframe_url']);
        $order->save();

        return [
            'result'            => 'success',
            'iframe_url'        => $result['iframe_url'],
            'os_transaction_id' => $result['transaction_id'],
            'wc_order_id'       => $order->get_id(),
            'gateway'           => $this->gateway_name,
            'nonce'             => wp_create_nonce('osp_confirm_nonce'),
        ];
    }
}
