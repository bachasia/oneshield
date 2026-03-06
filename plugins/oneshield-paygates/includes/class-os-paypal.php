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
        $this->render_iframe_field('paypal');
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

        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        ];
    }
}
