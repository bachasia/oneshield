<?php
/**
 * Render PayPal checkout page (inside iframe on Shield Site).
 *
 * Designed to look native — no card wrapper, transparent background, compact.
 */

defined('ABSPATH') || exit;

function osc_render_paypal_checkout(string $order_id, string $token): void {
    $config    = osc_get_paypal_config();
    $client_id = $config['client_id'] ?? '';
    $mode      = $config['mode'] ?? 'sandbox';

    if (empty($client_id)) {
        wp_die('PayPal is not configured on this Shield Site.', 'Payment Error', ['response' => 503]);
    }

    $amount   = (float) ($_GET['amount'] ?? 0);
    $currency = strtoupper(sanitize_text_field($_GET['currency'] ?? 'USD'));
    $sdk_url  = 'https://www.paypal.com/sdk/js?client-id=' . urlencode($client_id) . '&currency=' . urlencode($currency);

    if ($amount <= 0) {
        wp_die('Invalid payment amount.', 'Payment Error', ['response' => 400]);
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Payment</title>
        <script src="<?php echo esc_url($sdk_url); ?>"></script>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: transparent;
                padding: 0;
                min-height: 0;
            }
            #paypal-button-container { min-height: 45px; }
            #error-message {
                color: #dc2626;
                font-size: 13px;
                margin-top: 8px;
                display: none;
            }
            #error-message:not(:empty) { display: block; }
            #loading {
                text-align: center;
                padding: 24px 0;
                color: #9ca3af;
                font-size: 13px;
            }
            #loading.hidden { display: none; }
        </style>
    </head>
    <body>
        <div id="loading">Initializing PayPal...</div>
        <div id="paypal-button-container" style="display:none;"></div>
        <div id="error-message"></div>

        <script>
        (function() {
            const orderData = {
                order_id: '<?php echo esc_js($order_id); ?>',
                token:    '<?php echo esc_js($token); ?>',
                amount:   '<?php echo esc_js(number_format($amount, 2, '.', '')); ?>',
                currency: '<?php echo esc_js($currency); ?>',
                ajax_url: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
            };

            paypal.Buttons({
                style: {
                    layout:  'vertical',
                    color:   'gold',
                    shape:   'rect',
                    label:   'paypal',
                    height:  45,
                },

                createOrder: async function() {
                    const resp = await fetch(orderData.ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action:   'osc_create_paypal_order',
                            order_id: orderData.order_id,
                            amount:   orderData.amount,
                            currency: orderData.currency,
                        }),
                    });
                    const data = await resp.json();
                    if (!data.success) {
                        showError(data.data || 'Failed to create order.');
                        return null;
                    }
                    return data.data.paypal_order_id;
                },

                onApprove: async function(data) {
                    const resp = await fetch(orderData.ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action:          'osc_capture_paypal_order',
                            paypal_order_id: data.orderID,
                            order_id:        orderData.order_id,
                        }),
                    });
                    const result = await resp.json();

                    if (result.success) {
                        // Notify tracking (non-blocking)
                        fetch(orderData.ajax_url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action:         'osc_complete_tracking',
                                transaction_id: result.data.capture_id,
                                order_id:       orderData.order_id,
                            }),
                        }).catch(function() {});

                        window.parent.postMessage({
                            source:         'oneshield-connect',
                            status:         'success',
                            gateway:        'paypal',
                            transaction_id: result.data.capture_id,
                            order_id:       orderData.order_id,
                        }, '*');
                    } else {
                        showError(result.data || 'Payment capture failed.');
                    }
                },

                onError: function(err) {
                    showError('Payment failed. Please try again.');
                    console.error('PayPal error:', err);
                },

                onInit: function() {
                    document.getElementById('loading').classList.add('hidden');
                    document.getElementById('paypal-button-container').style.display = 'block';
                    notifyParentResize();
                },
            }).render('#paypal-button-container');

            function showError(msg) {
                document.getElementById('loading').classList.add('hidden');
                document.getElementById('paypal-button-container').style.display = 'block';
                var el = document.getElementById('error-message');
                el.textContent = msg;
                el.style.display = 'block';
            }

            function notifyParentResize() {
                var h = document.body.scrollHeight;
                window.parent.postMessage({
                    source: 'oneshield-connect',
                    action: 'resize',
                    height: h,
                }, '*');
            }

            // PayPal SDK injects its credit card form inside a nested iframe,
            // so ResizeObserver on document.body doesn't fire when it expands.
            // Use a MutationObserver to detect when PayPal injects/removes iframes
            // and poll height briefly afterwards to catch the full expansion.
            (function() {
                var lastHeight = 0;
                var pollTimer  = null;

                function pollHeight(duration) {
                    if (pollTimer) clearInterval(pollTimer);
                    var elapsed = 0;
                    pollTimer = setInterval(function() {
                        var h = document.body.scrollHeight;
                        if (h !== lastHeight) {
                            lastHeight = h;
                            notifyParentResize();
                        }
                        elapsed += 100;
                        if (elapsed >= duration) {
                            clearInterval(pollTimer);
                            pollTimer = null;
                        }
                    }, 100);
                }

                // Observe DOM mutations — PayPal injects iframes when card form opens
                var mo = new MutationObserver(function(mutations) {
                    var relevant = mutations.some(function(m) {
                        return m.addedNodes.length > 0 || m.removedNodes.length > 0;
                    });
                    if (relevant) {
                        // Poll for up to 3 seconds after any DOM change
                        pollHeight(3000);
                    }
                });
                mo.observe(document.body, { childList: true, subtree: true });
            })();
        })();
        </script>
    </body>
    </html>
    <?php
}

// AJAX: Create PayPal order
add_action('wp_ajax_nopriv_osc_create_paypal_order', 'osc_ajax_create_paypal_order');
add_action('wp_ajax_osc_create_paypal_order', 'osc_ajax_create_paypal_order');

function osc_ajax_create_paypal_order(): void {
    // Skip nonce: cross-origin iframe blocks cookies → nonce always fails.

    $amount   = sanitize_text_field($_POST['amount'] ?? '0');
    $currency = sanitize_text_field($_POST['currency'] ?? 'USD');
    $order_id = sanitize_text_field($_POST['order_id'] ?? '');

    $access_token = osc_get_paypal_access_token();
    if (is_wp_error($access_token)) {
        wp_send_json_error($access_token->get_error_message());
    }

    $config   = osc_get_paypal_config();
    $api_base = ($config['mode'] === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

    $response = wp_remote_post($api_base . '/v2/checkout/orders', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode([
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $order_id,
                'amount'       => ['currency_code' => $currency, 'value' => $amount],
            ]],
        ]),
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    wp_send_json_success(['paypal_order_id' => $body['id']]);
}

// AJAX: Capture PayPal order
add_action('wp_ajax_nopriv_osc_capture_paypal_order', 'osc_ajax_capture_paypal_order');
add_action('wp_ajax_osc_capture_paypal_order', 'osc_ajax_capture_paypal_order');

function osc_ajax_capture_paypal_order(): void {
    // Skip nonce: cross-origin iframe blocks cookies → nonce always fails.

    $paypal_order_id = sanitize_text_field($_POST['paypal_order_id'] ?? '');

    $access_token = osc_get_paypal_access_token();
    if (is_wp_error($access_token)) {
        wp_send_json_error($access_token->get_error_message());
    }

    $config   = osc_get_paypal_config();
    $api_base = ($config['mode'] === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

    $response = wp_remote_post($api_base . '/v2/checkout/orders/' . $paypal_order_id . '/capture', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
        ],
        'body' => '{}',
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }

    $body       = json_decode(wp_remote_retrieve_body($response), true);
    $capture_id = $body['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;

    if ($capture_id) {
        wp_send_json_success(['capture_id' => $capture_id]);
    } else {
        wp_send_json_error('Capture failed');
    }
}

function osc_get_paypal_config(): array {
    return [
        'client_id'     => osc_get_option('paypal_client_id', ''),
        'client_secret' => osc_get_option('paypal_secret', ''),
        'mode'          => osc_get_option('paypal_mode', 'sandbox'),
    ];
}

function osc_get_paypal_access_token(): string|\WP_Error {
    $config = osc_get_paypal_config();
    $api_base = ($config['mode'] === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

    $response = wp_remote_post($api_base . '/v1/oauth2/token', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($config['client_id'] . ':' . $config['client_secret']),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'body' => 'grant_type=client_credentials',
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['access_token'] ?? new \WP_Error('paypal_auth', 'Failed to get PayPal access token');
}
