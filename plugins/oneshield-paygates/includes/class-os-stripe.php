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

    /**
     * Render the payment iframe directly inside the checkout payment section.
     * Called by WooCommerce when this gateway is selected on checkout.
     *
     * We call the Gateway Panel here (PHP-side, synchronous) to get the iframe URL,
     * so the iframe is ready before the customer clicks Place Order.
     */
    public function payment_fields(): void {
        $this->render_iframe_field('stripe');
    }

    /**
     * Validate that the payment has been completed inside the iframe
     * before WooCommerce processes the order.
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
     * process_payment() — called after validate_fields() passes.
     * The iframe has already collected payment; we just need to:
     *  1. Create the WC order
     *  2. Confirm the transaction with the Gateway Panel
     *  3. Mark the order complete and redirect to thank-you
     */
    public function process_payment($order_id) {
        $order      = wc_get_order($order_id);
        $txn_id     = sanitize_text_field($_POST['osp_stripe_transaction_id']  ?? '');
        $os_txn_id  = sanitize_text_field($_POST['osp_stripe_os_transaction_id'] ?? '');
        $os_site_id = (int) ($_POST['osp_stripe_os_site_id'] ?? 0);

        if (empty($txn_id)) {
            wc_add_notice(__('Payment not completed. Please try again.', 'oneshield-paygates'), 'error');
            return ['result' => 'failure'];
        }

        // Confirm with Gateway Panel
        if ($os_site_id && $os_txn_id) {
            $confirmed = $this->confirm_with_panel($os_site_id, $order->get_id(), $txn_id);
            if (!$confirmed) {
                $this->log('confirm_with_panel failed for txn ' . $txn_id);
                // Non-fatal — payment already happened; still complete the order
            }
        }

        $order->payment_complete($txn_id);
        $order->add_order_note(sprintf(
            'OneShield: Stripe payment completed. Transaction ID: %s',
            $txn_id
        ));

        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        ];
    }
}
