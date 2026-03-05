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
        if ($desc = $this->get_option('description')) {
            echo '<p>' . wp_kses_post($desc) . '</p>';
        }
        echo '<p style="margin:8px 0 0;font-size:13px;color:#6b7280;">'
           . esc_html__('You will be prompted to enter your card details securely after placing the order.', 'oneshield-paygates')
           . '</p>';
    }

    public function process_payment($order_id) {
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
        $order->set_status('pending', 'Awaiting payment via OneShield.');
        $order->save();

        // WC requires a 'redirect' key in the response.
        // We pass a special URL that JS detects and swaps for the iframe modal.
        return [
            'result'            => 'success',
            'redirect'          => '#osp-iframe',   // JS intercepts this, no real redirect
            'iframe_url'        => $result['iframe_url'],
            'os_transaction_id' => $result['transaction_id'],
            'wc_order_id'       => $order->get_id(),
            'gateway'           => $this->gateway_name,
            'messages'          => '',
            'nonce'             => wp_create_nonce('osp_confirm_nonce'),
        ];
    }
}
