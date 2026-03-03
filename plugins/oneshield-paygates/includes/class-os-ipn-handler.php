<?php
/**
 * IPN/Webhook handler for OneShield Paygates.
 * Processes payment status updates from Gateway Panel.
 */

defined('ABSPATH') || exit;

class OS_IPN_Handler {

    public function __construct() {
        // Handle webhook callback from Gateway Panel (future direct webhook support)
        add_action('woocommerce_api_oneshield_webhook', [$this, 'handle_webhook']);
    }

    public function handle_webhook(): void {
        $payload = file_get_contents('php://input');
        $data    = json_decode($payload, true);

        if (empty($data['order_id']) || empty($data['status'])) {
            wp_die('Invalid webhook payload', 'OneShield', ['response' => 400]);
        }

        $order = wc_get_order($data['order_id']);
        if (!$order) {
            wp_die('Order not found', 'OneShield', ['response' => 404]);
        }

        switch ($data['status']) {
            case 'completed':
                if ($order->get_status() === 'pending') {
                    $order->payment_complete($data['gateway_transaction_id'] ?? '');
                }
                break;
            case 'refunded':
                $order->update_status('refunded', 'OneShield: Payment refunded.');
                break;
            case 'failed':
                $order->update_status('failed', 'OneShield: Payment failed.');
                break;
        }

        status_header(200);
        exit;
    }
}

new OS_IPN_Handler();
