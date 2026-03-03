<?php
/**
 * Render Stripe Elements checkout page (inside iframe on Shield Site).
 */

defined('ABSPATH') || exit;

function osc_render_stripe_checkout(string $order_id, string $token): void {
    $site_config = osc_get_stripe_config();
    $publishable_key = $site_config['publishable_key'] ?? '';
    $mode = $site_config['mode'] ?? 'test';

    if (empty($publishable_key)) {
        wp_die('Stripe is not configured on this Shield Site.', 'Payment Error', ['response' => 503]);
    }

    $amount   = (float) ($_GET['amount'] ?? 0);
    $currency = strtolower(sanitize_text_field($_GET['currency'] ?? 'usd'));
    $amount_cents = (int) round($amount * 100);

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Secure Payment</title>
        <script src="https://js.stripe.com/v3/"></script>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8fafc; padding: 24px; }
            .card { background: #fff; border-radius: 12px; padding: 24px; max-width: 480px; margin: 0 auto; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
            h2 { font-size: 18px; color: #1e293b; margin-bottom: 20px; }
            #payment-element { margin-bottom: 20px; }
            #submit { background: #6366f1; color: #fff; border: none; padding: 14px 24px; border-radius: 8px; width: 100%; font-size: 15px; cursor: pointer; }
            #submit:disabled { opacity: 0.6; cursor: not-allowed; }
            #error-message { color: #dc2626; font-size: 14px; margin-top: 12px; }
            .amount { font-size: 22px; font-weight: 700; color: #6366f1; margin-bottom: 4px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="amount"><?php echo esc_html(strtoupper($currency) . ' ' . number_format($amount, 2)); ?></div>
            <p style="color:#64748b;font-size:13px;margin-bottom:20px;">Order #<?php echo esc_html($order_id); ?></p>
            <h2>Payment Details</h2>
            <form id="payment-form">
                <div id="payment-element"></div>
                <button id="submit" type="submit">Pay Now</button>
                <div id="error-message"></div>
            </form>
        </div>

        <script>
        const stripe = Stripe('<?php echo esc_js($publishable_key); ?>');
        const orderData = {
            order_id: '<?php echo esc_js($order_id); ?>',
            token: '<?php echo esc_js($token); ?>',
            amount: <?php echo (int) $amount_cents; ?>,
            currency: '<?php echo esc_js($currency); ?>',
        };

        let elements;

        // Create PaymentIntent via WordPress AJAX
        async function initStripe() {
            const resp = await fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'osc_create_payment_intent',
                    order_id: orderData.order_id,
                    amount: orderData.amount,
                    currency: orderData.currency,
                    nonce: '<?php echo esc_js(wp_create_nonce('osc_stripe_nonce')); ?>',
                }),
            });

            const data = await resp.json();
            if (!data.success) {
                document.getElementById('error-message').textContent = data.data || 'Failed to initialize payment.';
                return;
            }

            elements = stripe.elements({ clientSecret: data.data.client_secret });
            const paymentElement = elements.create('payment');
            paymentElement.mount('#payment-element');
        }

        document.getElementById('payment-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('submit');
            btn.disabled = true;
            btn.textContent = 'Processing...';

            const { error, paymentIntent } = await stripe.confirmPayment({
                elements,
                redirect: 'if_required',
            });

            if (error) {
                document.getElementById('error-message').textContent = error.message;
                btn.disabled = false;
                btn.textContent = 'Pay Now';
                return;
            }

            if (paymentIntent && paymentIntent.status === 'succeeded') {
                window.parent.postMessage({
                    source: 'oneshield-connect',
                    status: 'success',
                    gateway: 'stripe',
                    transaction_id: paymentIntent.id,
                    order_id: orderData.order_id,
                }, '*');
            }
        });

        initStripe();
        </script>
    </body>
    </html>
    <?php
}

// AJAX: Create Stripe PaymentIntent
add_action('wp_ajax_nopriv_osc_create_payment_intent', 'osc_ajax_create_payment_intent');
add_action('wp_ajax_osc_create_payment_intent', 'osc_ajax_create_payment_intent');

function osc_ajax_create_payment_intent(): void {
    check_ajax_referer('osc_stripe_nonce', 'nonce');

    $amount   = (int) ($_POST['amount'] ?? 0);
    $currency = sanitize_text_field($_POST['currency'] ?? 'usd');
    $order_id = sanitize_text_field($_POST['order_id'] ?? '');

    if ($amount <= 0) {
        wp_send_json_error('Invalid amount');
    }

    $config = osc_get_stripe_config();
    $secret_key = $config['secret_key'] ?? '';

    if (empty($secret_key)) {
        wp_send_json_error('Stripe not configured');
    }

    // Call Stripe API directly
    $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'body' => http_build_query([
            'amount'   => $amount,
            'currency' => $currency,
            'metadata[order_id]' => $order_id,
            'automatic_payment_methods[enabled]' => 'true',
        ]),
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['error'])) {
        wp_send_json_error($body['error']['message']);
    }

    wp_send_json_success(['client_secret' => $body['client_secret']]);
}

function osc_get_stripe_config(): array {
    return [
        'publishable_key' => osc_get_option('stripe_public_key', ''),
        'secret_key'      => osc_get_option('stripe_secret_key', ''),
        'mode'            => osc_get_option('stripe_mode', 'test'),
    ];
}
