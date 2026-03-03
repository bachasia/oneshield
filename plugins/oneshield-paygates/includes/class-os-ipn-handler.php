<?php
/**
 * IPN/Webhook handler for OneShield Paygates.
 * Processes payment status updates pushed directly from the Gateway Panel.
 *
 * Endpoint: GET/POST ?wc-api=oneshield_webhook
 * Security: HMAC-SHA256 signature verified against the Paygates token_secret.
 */

defined('ABSPATH') || exit;

class OS_IPN_Handler {

    public function __construct() {
        add_action('woocommerce_api_oneshield_webhook', [$this, 'handle_webhook']);
    }

    public function handle_webhook(): void {
        $raw_body  = file_get_contents('php://input');
        $data      = json_decode($raw_body, true);
        $signature = $_SERVER['HTTP_X_ONESHIELD_SIGNATURE'] ?? '';
        $timestamp = (int) ($_SERVER['HTTP_X_ONESHIELD_TIMESTAMP'] ?? 0);

        if (empty($data['order_id']) || empty($data['status'])) {
            wp_die('Invalid webhook payload', 'OneShield', ['response' => 400]);
        }

        // Verify HMAC signature from Gateway Panel
        if (!$this->verify_signature($data, $signature, $timestamp)) {
            wp_die('Unauthorized: invalid signature', 'OneShield', ['response' => 401]);
        }

        $order = wc_get_order($data['order_id']);
        if (!$order) {
            wp_die('Order not found', 'OneShield', ['response' => 404]);
        }

        switch ($data['status']) {
            case 'completed':
                if ($order->get_status() === 'pending') {
                    $order->payment_complete($data['gateway_transaction_id'] ?? '');
                    $order->add_order_note('OneShield: Payment confirmed via direct webhook.');
                }
                break;
            case 'refunded':
                $order->update_status('refunded', 'OneShield: Payment refunded via webhook.');
                break;
            case 'failed':
                $order->update_status('failed', 'OneShield: Payment failed via webhook.');
                break;
        }

        status_header(200);
        exit;
    }

    /**
     * Verify HMAC-SHA256 signature sent by the Gateway Panel.
     * Uses the token_secret configured in the Stripe or PayPal gateway settings.
     */
    private function verify_signature(array $payload, string $signature, int $timestamp): bool {
        if (empty($signature) || $timestamp === 0) {
            return false;
        }

        // Reject requests older than 5 minutes (replay attack prevention)
        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        // Retrieve token_secret — try stripe gateway first, then paypal
        $token_secret = '';
        $gateways = WC()->payment_gateways()->payment_gateways();
        foreach (['os_stripe', 'os_paypal'] as $gw_id) {
            if (!empty($gateways[$gw_id])) {
                $secret = $gateways[$gw_id]->get_option('token_secret', '');
                if (!empty($secret)) {
                    $token_secret = $secret;
                    break;
                }
            }
        }

        if (empty($token_secret)) {
            return false;
        }

        $message  = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $timestamp;
        $expected = hash_hmac('sha256', $message, $token_secret);

        return hash_equals($expected, $signature);
    }
}

new OS_IPN_Handler();
