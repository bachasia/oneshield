<?php
/**
 * Render Stripe Elements checkout page (inside iframe on Shield Site).
 *
 * Designed to look native — no card wrapper, transparent background, compact.
 * The iframe is embedded on the money site checkout page.
 */

defined('ABSPATH') || exit;

function osc_render_stripe_checkout(string $order_id, string $token): void {
    $site_config = osc_get_stripe_config();
    $publishable_key = $site_config['publishable_key'] ?? '';

    if (empty($publishable_key)) {
        wp_die('Stripe is not configured on this Shield Site.', 'Payment Error', ['response' => 503]);
    }

    $amount       = (float) ($_GET['amount'] ?? 0);
    $currency     = strtolower(sanitize_text_field($_GET['currency'] ?? 'usd'));
    $amount_cents = (int) round($amount * 100);

    // Extra params from money site plugin settings (simple flags only)
    $capture_method       = sanitize_text_field($_GET['capture_method'] ?? 'automatic');
    $statement_descriptor = sanitize_text_field($_GET['statement_descriptor'] ?? '');
    $enable_wallets       = ($_GET['enable_wallets'] ?? '1') === '1';
    $send_billing         = ($_GET['send_billing'] ?? '') === 'yes';
    $mode_param           = sanitize_text_field($_GET['mode'] ?? 'live');
    $description_format   = sanitize_text_field($_GET['description_format'] ?? '');
    $txn_id               = (int) ($_GET['txn_id'] ?? 0);
    $os_site_id           = (int) ($_GET['site_id'] ?? 0);

    if ($amount_cents <= 0) {
        wp_die('Invalid payment amount.', 'Payment Error', ['response' => 400]);
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Payment</title>
        <script src="https://js.stripe.com/v3/"></script>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: transparent;
                padding: 0;
                min-height: 0;
            }
            #payment-element { min-height: 80px; }
            #error-message {
                color: #dc2626;
                font-size: 13px;
                margin-top: 8px;
                display: none;
            }
            #error-message:not(:empty) { display: block; }
            #loading {
                text-align: center;
                padding: 32px 0;
                color: #9ca3af;
                font-size: 13px;
            }
            #loading.hidden { display: none; }
            /* Hide the submit button — payment is confirmed automatically.
               Keep it in DOM for Stripe's confirmPayment() call. */
            #submit { display: none; }
        </style>
    </head>
    <body>
        <div id="loading">Initializing secure payment...</div>
        <form id="payment-form" style="display:none;">
            <div id="payment-element"></div>
            <button id="submit" type="submit">Pay</button>
            <div id="error-message"></div>
        </form>

        <script>
        (function() {
            const stripe = Stripe('<?php echo esc_js($publishable_key); ?>');
            const orderData = {
                order_id:             '<?php echo esc_js($order_id); ?>',
                token:                '<?php echo esc_js($token); ?>',
                amount:               <?php echo (int) $amount_cents; ?>,
                currency:             '<?php echo esc_js($currency); ?>',
                capture_method:       '<?php echo esc_js($capture_method); ?>',
                statement_descriptor: '<?php echo esc_js($statement_descriptor); ?>',
                description_format:   '<?php echo esc_js($description_format); ?>',
                send_billing:         <?php echo $send_billing ? 'true' : 'false'; ?>,
                txn_id:               <?php echo (int) $txn_id; ?>,
                os_site_id:           <?php echo (int) $os_site_id; ?>,
                checkout_id:          '<?php echo esc_js(sanitize_text_field($_GET['checkout_id'] ?? '')); ?>',
            };

            let elements, paymentElement;

            async function initStripe() {
                try {
                    const resp = await fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action:               'osc_create_payment_intent',
                            order_id:             orderData.order_id,
                            amount:               orderData.amount,
                            currency:             orderData.currency,
                            capture_method:       orderData.capture_method,
                            statement_descriptor: orderData.statement_descriptor,
                            description_format:   orderData.description_format,
                            send_billing:         orderData.send_billing ? 'yes' : 'no',
                            txn_id:               orderData.txn_id,
                            os_site_id:           orderData.os_site_id,
                            checkout_id:          orderData.checkout_id,
                        }),
                    });

                    const data = await resp.json();
                    if (!data.success) {
                        showError(data.data || 'Failed to initialize payment.');
                        return;
                    }

                    // Store billing_details returned by PHP (fetched server-side from gateway panel)
                    orderData.billing_details = data.data.billing_details || null;

                    elements = stripe.elements({
                        clientSecret: data.data.client_secret,
                        appearance: {
                            theme: 'stripe',
                            variables: {
                                fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                                borderRadius: '6px',
                            },
                        },
                    });

                    var paymentOpts = {
                        layout: {
                            type: 'tabs',
                            defaultCollapsed: false,
                        },
                        fields: {
                            billingDetails: 'never',
                        },
                        wallets: {
                            applePay:  <?php echo $enable_wallets ? "'auto'" : "'never'"; ?>,
                            googlePay: <?php echo $enable_wallets ? "'auto'" : "'never'"; ?>,
                        },
                        // Hide "Save my info" / Stripe Link prompt
                        terms: {
                            card:         'never',
                            applePay:     'never',
                            googlePay:    'never',
                            paypal:       'never',
                            auBecsDebit:  'never',
                            bancontact:   'never',
                            ideal:        'never',
                            sepaDebit:    'never',
                            sofort:       'never',
                            usBankAccount:'never',
                        },
                    };

                    paymentElement = elements.create('payment', paymentOpts);

                    paymentElement.on('ready', function() {
                        document.getElementById('loading').classList.add('hidden');
                        document.getElementById('payment-form').style.display = 'block';
                        notifyParentResize();
                    });

                    paymentElement.on('change', function() {
                        notifyParentResize();
                    });

                    paymentElement.mount('#payment-element');

                    // ResizeObserver: continuously report height changes as Stripe
                    // Elements renders its internal components (tabs, fields, etc.)
                    // This eliminates the blank space on first render.
                    if (typeof ResizeObserver !== 'undefined') {
                        var ro = new ResizeObserver(function() {
                            notifyParentResize();
                        });
                        ro.observe(document.body);
                    }
                } catch (err) {
                    showError('Network error. Please refresh and try again.');
                }
            }

            // Handle form submit (triggered by money site Place Order)
            document.getElementById('payment-form').addEventListener('submit', async function(e) {
                e.preventDefault();
                await confirmPayment();
            });

            // Also listen for postMessage from money site to trigger confirmation
            window.addEventListener('message', async function(event) {
                if (!event.data || event.data.action !== 'oneshield-confirm-payment') {
                    return;
                }

                // Money site can send latest txn/site IDs right before confirm.
                if (event.data.txn_id) {
                    orderData.txn_id = parseInt(event.data.txn_id, 10) || orderData.txn_id;
                }
                if (event.data.site_id) {
                    orderData.os_site_id = parseInt(event.data.site_id, 10) || orderData.os_site_id;
                }
                // checkout_id mode: store for billing fetch
                if (event.data.checkout_id) {
                    orderData.checkout_id = event.data.checkout_id;
                }

                // Billing may only be available now (sent at Place Order time).
                if (orderData.send_billing && (orderData.txn_id || orderData.checkout_id) && orderData.os_site_id) {
                    await refreshBillingDetails();
                }

                try {
                    await confirmPayment();
                } catch (err) {
                    var msg = (err && err.message) ? err.message : 'Payment failed. Please try again.';
                    showError(msg);
                    window.parent.postMessage({
                        source: 'oneshield-connect',
                        action: 'payment_error',
                        message: msg,
                    }, '*');
                }
            });

            async function refreshBillingDetails() {
                try {
                    const resp = await fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action:      'osc_get_billing_details',
                            txn_id:      orderData.txn_id,
                            os_site_id:  orderData.os_site_id,
                            checkout_id: orderData.checkout_id || '',
                        }),
                    });

                    const data = await resp.json();
                    if (data.success && data.data && data.data.billing_details) {
                        orderData.billing_details = data.data.billing_details;
                    }
                } catch (err) {
                    // Non-fatal: continue confirmPayment without billing_details.
                }
            }

            async function confirmPayment() {
                var btn = document.getElementById('submit');
                btn.disabled = true;

                // When fields.billingDetails is set to 'never', Stripe requires
                // billing_details to always be passed in confirmPayment() — even
                // if empty. At minimum, billing_details.name must be present.
                // Use fetched billing if available, otherwise fall back to a
                // placeholder so Stripe does not throw a validation error.
                var bd = orderData.billing_details || {};
                // Stripe rejects empty strings for some address fields (e.g. country)
                // and rejects undefined/missing for name. Strategy:
                //   - name: always a non-empty string (fallback 'Guest')
                //   - all other fields: omit entirely if falsy (Stripe accepts absence)
                var billingDetails = { name: bd.name || 'Guest' };
                if (bd.email) billingDetails.email = bd.email;
                if (bd.phone) billingDetails.phone = bd.phone;
                var addr = bd.address || {};
                var addrObj = {};
                if (addr.line1)       addrObj.line1       = addr.line1;
                if (addr.line2)       addrObj.line2       = addr.line2;
                if (addr.city)        addrObj.city        = addr.city;
                if (addr.state)       addrObj.state       = addr.state;
                if (addr.postal_code) addrObj.postal_code = addr.postal_code;
                if (addr.country)     addrObj.country     = addr.country;
                if (Object.keys(addrObj).length) billingDetails.address = addrObj;

                var confirmOpts = {
                    elements: elements,
                    redirect: 'if_required',
                    confirmParams: {
                        payment_method_data: {
                            billing_details: billingDetails,
                        },
                    },
                };

                var result;
                try {
                    result = await stripe.confirmPayment(confirmOpts);
                } catch (err) {
                    var thrownMsg = (err && err.message) ? err.message : 'Payment confirmation failed.';
                    showError(thrownMsg);
                    btn.disabled = false;
                    window.parent.postMessage({
                        source: 'oneshield-connect',
                        action: 'payment_error',
                        message: thrownMsg,
                    }, '*');
                    return;
                }

                var error = result.error;
                var paymentIntent = result.paymentIntent;

                if (error) {
                    showError(error.message);
                    btn.disabled = false;
                    // Notify parent to hide loading overlay
                    window.parent.postMessage({
                        source: 'oneshield-connect',
                        action: 'payment_error',
                        message: error.message,
                    }, '*');
                    return;
                }

                // Treat both "succeeded" and "requires_capture" as a successful
                // authorization from checkout perspective. "requires_capture" is
                // returned when capture_method=manual.
                if (paymentIntent && (paymentIntent.status === 'succeeded' || paymentIntent.status === 'requires_capture')) {
                    // Notify tracking (non-blocking)
                    fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action:         'osc_complete_tracking',
                            transaction_id: paymentIntent.id,
                            order_id:       orderData.order_id,
                        }),
                    }).catch(function() {});

                    // Notify parent (money site)
                    window.parent.postMessage({
                        source:         'oneshield-connect',
                        status:         'success',
                        gateway:        'stripe',
                        transaction_id: paymentIntent.id,
                        order_id:       orderData.order_id,
                    }, '*');
                } else {
                    // Explicitly notify parent on unexpected status so checkout
                    // overlay can be closed and customer can retry.
                    var statusMsg = paymentIntent && paymentIntent.status
                        ? ('Payment status: ' + paymentIntent.status)
                        : 'Payment not completed. Please try again.';
                    showError(statusMsg);
                    window.parent.postMessage({
                        source: 'oneshield-connect',
                        action: 'payment_error',
                        message: statusMsg,
                    }, '*');
                    btn.disabled = false;
                }
            }

            function showError(msg) {
                document.getElementById('loading').classList.add('hidden');
                document.getElementById('payment-form').style.display = 'block';
                var el = document.getElementById('error-message');
                el.textContent = msg;
                el.style.display = 'block';
            }

            function notifyParentResize() {
                // Use getBoundingClientRect for accurate rendered height,
                // avoiding scrollHeight over-estimation on first paint.
                var h = document.documentElement.getBoundingClientRect().height
                     || document.body.scrollHeight;
                window.parent.postMessage({
                    source: 'oneshield-connect',
                    action: 'resize',
                    height: Math.ceil(h),
                }, '*');
            }

            initStripe();
        })();
        </script>
    </body>
    </html>
    <?php
}

// AJAX: Create Stripe PaymentIntent
add_action('wp_ajax_nopriv_osc_create_payment_intent', 'osc_ajax_create_payment_intent');
add_action('wp_ajax_osc_create_payment_intent', 'osc_ajax_create_payment_intent');

// AJAX: Fetch latest billing_details for confirmPayment()
add_action('wp_ajax_nopriv_osc_get_billing_details', 'osc_ajax_get_billing_details');
add_action('wp_ajax_osc_get_billing_details', 'osc_ajax_get_billing_details');

function osc_ajax_create_payment_intent(): void {
    // Skip nonce: cross-origin iframe blocks cookies → nonce always fails.
    // Secured by HMAC checkout token at page level.

    $amount               = (int) ($_POST['amount'] ?? 0);
    $currency             = sanitize_text_field($_POST['currency'] ?? 'usd');
    $order_id             = sanitize_text_field($_POST['order_id'] ?? '');
    $capture_method       = sanitize_text_field($_POST['capture_method'] ?? 'automatic');
    $statement_descriptor = sanitize_text_field($_POST['statement_descriptor'] ?? '');
    $description_format   = sanitize_text_field($_POST['description_format'] ?? '');
    $send_billing         = ($_POST['send_billing'] ?? 'no') === 'yes';
    $txn_id               = (int) ($_POST['txn_id'] ?? 0);
    $os_site_id           = (int) ($_POST['os_site_id'] ?? 0);
    $checkout_id          = sanitize_text_field($_POST['checkout_id'] ?? '');

    if ($amount <= 0) {
        wp_send_json_error('Invalid amount');
    }

    $config = osc_get_stripe_config();
    $secret_key = $config['secret_key'] ?? '';

    if (empty($secret_key)) {
        wp_send_json_error('Stripe not configured');
    }

    // Build Stripe PaymentIntent params
    $pi_params = [
        'amount'   => $amount,
        'currency' => $currency,
        'metadata[order_id]' => $order_id,
        'automatic_payment_methods[enabled]' => 'true',
    ];

    // Capture method: automatic or manual
    if (in_array($capture_method, ['automatic', 'manual'], true)) {
        $pi_params['capture_method'] = $capture_method;
    }

    // Statement descriptor (max 22 chars)
    if (!empty($statement_descriptor)) {
        $pi_params['statement_descriptor_suffix'] = substr($statement_descriptor, 0, 22);
    }

    // Description
    if (!empty($description_format)) {
        $desc = str_replace('[order_id]', $order_id, $description_format);
        // [rand_str] must be deterministic per order_id so it matches when the
        // same Stripe Idempotency-Key is reused on retry (same key = same body).
        $rand_str = substr(hash('sha256', 'osp_rand|' . $order_id . '|' . $os_site_id), 0, 8);
        $desc = str_replace('[rand_str]', $rand_str, $desc);
        $pi_params['description'] = $desc;
    }

    // Fetch billing from gateway panel — returned to JS for confirmPayment() only.
    // Billing data must NOT be embedded in the PaymentIntent body because the
    // same Stripe Idempotency-Key is reused on retries (same order_id/amount).
    // Adding mutable fields like receipt_email or shipping to pi_params would
    // cause Stripe to reject the retry with an idempotency conflict error when
    // billing is available on the second call but not the first (e.g. when txn_id
    // is 0 on page load and non-zero after WC order creation).
    $billing_for_js = null;
    if ($send_billing && ($txn_id || $checkout_id) && $os_site_id) {
        $billing = osc_fetch_billing_from_gateway($txn_id, $os_site_id, $checkout_id);
        if (!empty($billing)) {
            $full_name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));

            // Only return billing_details to JS for confirmPayment()
            // so Stripe can attach it to the PaymentMethod for AVS/fraud checks.
            $billing_for_js = array_filter([
                'name'  => $full_name ?: null,
                'email' => $billing['email'] ?? null,
                'phone' => $billing['phone'] ?? null,
                'address' => array_filter([
                    'line1'       => $billing['address_1'] ?? null,
                    'line2'       => $billing['address_2'] ?? null,
                    'city'        => $billing['city'] ?? null,
                    'state'       => $billing['state'] ?? null,
                    'postal_code' => $billing['postcode'] ?? null,
                    'country'     => $billing['country'] ?? null,
                ]),
            ]);
        }
    }

    $stripe_idempotency_key = hash('sha256', implode('|', [
        'osp_pi',
        $order_id,
        (string) $amount,
        strtolower($currency),
        $capture_method,
        (string) $os_site_id,
    ]));

    $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Idempotency-Key' => $stripe_idempotency_key,
        ],
        'body' => http_build_query($pi_params),
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['error'])) {
        wp_send_json_error($body['error']['message']);
    }

    wp_send_json_success([
        'client_secret'   => $body['client_secret'],
        'billing_details' => $billing_for_js, // null when send_billing=no
    ]);
}

function osc_ajax_get_billing_details(): void {
    $txn_id      = (int) ($_POST['txn_id'] ?? 0);
    $os_site_id  = (int) ($_POST['os_site_id'] ?? 0);
    $checkout_id = sanitize_text_field($_POST['checkout_id'] ?? '');

    if (!$txn_id && !$checkout_id) {
        wp_send_json_success(['billing_details' => null]);
    }

    $billing = osc_fetch_billing_from_gateway($txn_id, $os_site_id, $checkout_id);
    if (empty($billing)) {
        wp_send_json_success(['billing_details' => null]);
    }

    $full_name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));

    $billing_for_js = array_filter([
        'name'  => $full_name ?: null,
        'email' => $billing['email'] ?? null,
        'phone' => $billing['phone'] ?? null,
        'address' => array_filter([
            'line1'       => $billing['address_1'] ?? null,
            'line2'       => $billing['address_2'] ?? null,
            'city'        => $billing['city'] ?? null,
            'state'       => $billing['state'] ?? null,
            'postal_code' => $billing['postcode'] ?? null,
            'country'     => $billing['country'] ?? null,
        ]),
    ]);

    wp_send_json_success(['billing_details' => $billing_for_js]);
}

function osc_get_stripe_config(): array {
    return [
        'publishable_key' => osc_get_option('stripe_public_key', ''),
        'secret_key'      => osc_get_option('stripe_secret_key', ''),
        'mode'            => osc_get_option('stripe_mode', 'test'),
    ];
}

/**
 * Fetch billing details from the gateway panel for a given transaction or checkout session.
 * Called server-side (PHP) — no PII in URLs or JS.
 *
 * @param int    $txn_id      Legacy transaction ID (0 when using checkout_id mode)
 * @param int    $site_id     Shield site ID
 * @param string $checkout_id Checkout session UUID (checkout_id mode)
 * @return array|null Billing data array, or null on failure.
 */
function osc_fetch_billing_from_gateway(int $txn_id, int $site_id, string $checkout_id = ''): ?array {
    if (!osc_is_connected()) {
        return null;
    }

    // checkout_id mode: use checkout_id instead of transaction_id
    // Fallback to $_GET checkout_id when called from page context (not AJAX)
    if (empty($checkout_id)) {
        $checkout_id = isset($_GET['checkout_id']) ? sanitize_text_field($_GET['checkout_id']) : '';
    }

    $payload = $checkout_id
        ? ['checkout_id' => $checkout_id, 'site_id' => $site_id]
        : ['transaction_id' => $txn_id, 'site_id' => $site_id];

    $response = wp_remote_post(osc_gateway_url() . '/api/connect/billing', [
        'timeout' => 8,
        'headers' => osc_build_headers($payload),
        'body'    => json_encode($payload),
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['billing'] ?? null;
}
