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
        $this->method_description = __('Accept Stripe payments via OneShield Shield Sites.', 'oneshield-paygates');
        $this->has_fields         = true;
        $this->supports           = ['products'];

        $this->init_form_fields();
        $this->init_settings();
        $this->load_settings();

        $this->title       = $this->get_option('title', 'Credit / Debit Card');
        $this->description = $this->get_option('description', 'Pay securely with your credit or debit card.');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    protected function get_default_title(): string {
        return __('Credit / Debit Card', 'oneshield-paygates');
    }

    public function payment_fields(): void {
        echo '<div id="os-stripe-container" data-gateway="stripe"></div>';
        echo '<p class="os-loading" style="text-align:center;color:#888;">' . esc_html__('Loading secure payment form...', 'oneshield-paygates') . '</p>';
    }

    public function process_payment(int $order_id): array {
        $order = wc_get_order($order_id);

        $result = $this->get_iframe_url($order);
        if (!$result) {
            wc_add_notice(__('Payment service temporarily unavailable. Please try again.', 'oneshield-paygates'), 'error');
            return ['result' => 'failure'];
        }

        // Store OS transaction data on the order
        $order->update_meta_data('_os_transaction_id', $result['transaction_id']);
        $order->update_meta_data('_os_site_id', $result['site_id']);
        $order->update_meta_data('_os_iframe_url', $result['iframe_url']);
        $order->save();

        // Return the iframe URL for JS to pick up
        return [
            'result'    => 'success',
            'iframe_url' => $result['iframe_url'],
            'os_transaction_id' => $result['transaction_id'],
            'nonce' => wp_create_nonce('osp_confirm_nonce'),
        ];
    }
}
