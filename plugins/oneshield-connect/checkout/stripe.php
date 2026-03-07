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
    // merchant_site: this shield site's URL — sent as metadata to Stripe so the
    // dashboard shows which shield site processed the payment.
    $merchant_site        = get_site_url();

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
        <script src="https://js.stripe.com/v3/?advancedFraudSignals=false"></script>
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
            // Spoof document.referrer to this shield site's URL so Stripe Radar
            // sees the proxy site (no trademark) instead of the money site.
            Object.defineProperty(document, 'referrer', {
                get: function() { return '<?php echo esc_js(get_site_url()); ?>'; }
            });

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
                payment_intent_id:    '', // populated after createPaymentIntent
                // merchant_site: the money site domain — passed server-side from Gateway Panel
                money_site:           '<?php echo esc_js($merchant_site); ?>',
            };

            let elements, paymentElement;

            async function initStripe() {
                try {
                    // paymentMethodCreation:'manual' — Elements only collects card details.
                    // No PaymentIntent is created at load time. PI is created server-side
                    // after billing is available (at Place Order), then confirmPayment is
                    // called with the PI client_secret returned by osc_update_payment_intent.
                    // This mirrors mecom's approach and ensures billing is attached to the
                    // PaymentMethod from the very first Stripe API call.
                    elements = stripe.elements({
                        mode:                   'payment',
                        amount:                 orderData.amount,
                        currency:               orderData.currency,
                        paymentMethodCreation:  'manual',
                        appearance: {
                            theme: 'stripe',
                            variables: {
                                fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                                borderRadius: '6px',
                            },
                        },
                        loader: 'auto',
                    });

                    var paymentOpts = {
                        layout: {
                            type: 'tabs',
                            defaultCollapsed: false,
                        },
                        // Only hide country — collected from WC billing form.
                        // All other fields (name, email, phone, address) are
                        // collected by Stripe Elements itself. Hiding everything
                        // requires passing all fields to createPaymentMethod which
                        // is error-prone; country is the only field we can reliably
                        // pre-supply from WooCommerce.
                        fields: {
                            billingDetails: {
                                address: {
                                    country: 'never',
                                },
                            },
                        },
                        wallets: {
                            applePay:  <?php echo $enable_wallets ? "'auto'" : "'never'"; ?>,
                            googlePay: <?php echo $enable_wallets ? "'auto'" : "'never'"; ?>,
                        },
                        // Disable all "Save info" prompts including Stripe Link
                        terms: {
                            card:         'never',
                            applePay:     'never',
                            googlePay:    'never',
                            paypal:       'never',
                            link:         'never',
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
                        // Ask parent to send billing_country immediately
                        window.parent.postMessage({
                            source: 'oneshield-connect',
                            action: 'stripe_ready',
                        }, '*');
                    });

                    paymentElement.on('change', function() {
                        notifyParentResize();
                    });

                    paymentElement.mount('#payment-element');

                    // ResizeObserver: continuously report height changes as Stripe
                    // Elements renders its internal components (tabs, fields, etc.)
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
                    // Handle early billing_country prefill sent by money site after stripe_ready
                    if (event.data && event.data.action === 'oneshield-prefill-billing') {
                        if (event.data.billing_country) {
                            // Store prefilled country; used in confirmPayment billing_details
                            orderData.prefill_country = event.data.billing_country;
                        }
                    }
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
                    await refreshBillingAndUpdatePI();
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

            /**
             * Fetch billing from gateway panel into orderData.billing_details.
             * In the manual flow, billing is passed to osc_create_payment_intent
             * directly (not via a separate update call), so this just pre-loads
             * the data into JS memory for use in confirmPayment's billing_details.
             */
            async function refreshBillingAndUpdatePI() {
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
                    // Non-fatal
                }
            }

            async function confirmPayment() {
                var btn = document.getElementById('submit');
                btn.disabled = true;

                // ── Step 1: validate card fields ──────────────────────────────
                var submitResult = await elements.submit();
                if (submitResult.error) {
                    var code = submitResult.error.code || '';
                    var cardErrors = ['incomplete_number','invalid_number','incomplete_expiry',
                                      'invalid_expiry','incomplete_cvc','invalid_cvc'];
                    if (!cardErrors.includes(code)) {
                        showError(submitResult.error.message);
                    }
                    btn.disabled = false;
                    window.parent.postMessage({ source: 'oneshield-connect', action: 'payment_error', message: submitResult.error.message }, '*');
                    return;
                }

                // ── Step 2: build billing_details for createPaymentMethod ─────
                // Only address.country needs to be supplied manually — all other
                // billing fields (name, email, phone, line1, city, state, zip)
                // are collected by Stripe Elements and passed automatically.
                var country = (orderData.billing_details && orderData.billing_details.address && orderData.billing_details.address.country)
                    ? orderData.billing_details.address.country
                    : (orderData.prefill_country || '');

                // ── Step 3: create PaymentMethod client-side ──────────────────
                var pmParams = {};
                if (country) {
                    pmParams = { billing_details: { address: { country: country } } };
                }
                var pmResult = await stripe.createPaymentMethod({
                    elements: elements,
                    params: pmParams,
                });
                if (pmResult.error) {
                    showError(pmResult.error.message);
                    btn.disabled = false;
                    window.parent.postMessage({ source: 'oneshield-connect', action: 'payment_error', message: pmResult.error.message }, '*');
                    return;
                }
                var pmId = pmResult.paymentMethod.id;

                // ── Step 4: create PI + confirm server-side, get client_secret ─
                var piResp, piData;
                try {
                    piResp = await fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
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
                            checkout_id:          orderData.checkout_id || '',
                            money_site:           orderData.money_site  || '',
                            pm_id:                pmId,
                        }),
                    });
                    piData = await piResp.json();
                } catch (err) {
                    showError('Network error creating payment. Please try again.');
                    btn.disabled = false;
                    return;
                }

                if (!piData.success) {
                    showError(piData.data || 'Payment initialization failed.');
                    btn.disabled = false;
                    return;
                }

                var clientSecret = piData.data.client_secret;

                // ── Step 5: confirm payment with client_secret ─────────────────
                var result;
                try {
                    result = await stripe.confirmPayment({
                        clientSecret: clientSecret,
                        confirmParams: {
                            payment_method: pmId,
                            return_url: window.location.href,
                        },
                        redirect: 'if_required',
                    });
                } catch (err) {
                    var thrownMsg = (err && err.message) ? err.message : 'Payment confirmation failed.';
                    showError(thrownMsg);
                    btn.disabled = false;
                    window.parent.postMessage({ source: 'oneshield-connect', action: 'payment_error', message: thrownMsg }, '*');
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

// AJAX: Update existing PaymentIntent with billing + description (server-side, secret key never in browser)
add_action('wp_ajax_nopriv_osc_update_payment_intent', 'osc_ajax_update_payment_intent');
add_action('wp_ajax_osc_update_payment_intent', 'osc_ajax_update_payment_intent');

function osc_ajax_create_payment_intent(): void {
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
    $money_site           = sanitize_text_field($_POST['money_site'] ?? '');
    $pm_id                = sanitize_text_field($_POST['pm_id'] ?? ''); // PaymentMethod ID (manual flow)

    if ($amount <= 0) {
        wp_send_json_error('Invalid amount');
    }

    $config     = osc_get_stripe_config();
    $secret_key = $config['secret_key'] ?? '';
    if (empty($secret_key)) {
        wp_send_json_error('Stripe not configured');
    }

    // Fetch billing — available at this point (osp_send_billing already called)
    $billing = null;
    if ($send_billing && ($txn_id || $checkout_id) && $os_site_id) {
        $billing = osc_fetch_billing_from_gateway($txn_id, $os_site_id, $checkout_id);
    }

    $first     = trim($billing['first_name'] ?? '');
    $last      = trim($billing['last_name']  ?? '');
    $full_name = trim("$first $last") ?: ($billing['name'] ?? '');
    $email     = trim($billing['email']      ?? '');
    $phone     = trim($billing['phone']      ?? '');
    $address1  = trim($billing['address_1']  ?? '');
    $address2  = trim($billing['address_2']  ?? '');
    $city      = trim($billing['city']       ?? '');
    $state     = trim($billing['state']      ?? '');
    $postcode  = trim($billing['postcode']   ?? '');
    $country   = strtoupper(trim($billing['country'] ?? ''));

    // ── Create or retrieve Stripe Customer ───────────────────────────────────
    $customer_id = '';
    if (!empty($email)) {
        $cache_key   = 'osc_stripe_cus_' . md5($email . $secret_key);
        $customer_id = get_transient($cache_key) ?: '';

        if (empty($customer_id)) {
            $search = wp_remote_get(
                'https://api.stripe.com/v1/customers?email=' . rawurlencode($email) . '&limit=1',
                ['timeout' => 8, 'headers' => ['Authorization' => 'Bearer ' . $secret_key]]
            );
            if (!is_wp_error($search)) {
                $s = json_decode(wp_remote_retrieve_body($search), true);
                $customer_id = $s['data'][0]['id'] ?? '';
            }
        }

        if (empty($customer_id)) {
            $cus_params = ['email' => $email];
            if ($full_name) $cus_params['name']  = $full_name;
            if ($phone)     $cus_params['phone'] = $phone;
            if ($address1) {
                $cus_params['address[line1]']       = $address1;
                if ($address2) $cus_params['address[line2]']       = $address2;
                if ($city)     $cus_params['address[city]']        = $city;
                if ($state)    $cus_params['address[state]']       = $state;
                if ($postcode) $cus_params['address[postal_code]'] = $postcode;
                if ($country)  $cus_params['address[country]']     = $country;
            }
            $cus_resp = wp_remote_post('https://api.stripe.com/v1/customers', [
                'timeout' => 10,
                'headers' => ['Authorization' => 'Bearer ' . $secret_key, 'Content-Type' => 'application/x-www-form-urlencoded'],
                'body'    => http_build_query($cus_params),
            ]);
            if (!is_wp_error($cus_resp)) {
                $cus_body    = json_decode(wp_remote_retrieve_body($cus_resp), true);
                $customer_id = $cus_body['id'] ?? '';
                if ($customer_id) {
                    set_transient($cache_key, $customer_id, 30 * DAY_IN_SECONDS);
                }
            }
        }
    }

    // ── Build PaymentIntent params ───────────────────────────────────────────
    $rand_str = substr(hash('sha256', 'osp_rand|' . $order_id . '|' . $os_site_id), 0, 8);
    $pi_params = [
        'amount'                                     => $amount,
        'currency'                                   => $currency,
        'automatic_payment_methods[enabled]'         => 'true',
        'automatic_payment_methods[allow_redirects]' => 'never',
        'metadata[order_id]'                         => $order_id,
    ];

    if ($customer_id)      $pi_params['customer']      = $customer_id;
    if ($email)            $pi_params['receipt_email'] = $email;

    if (in_array($capture_method, ['automatic', 'manual'], true)) {
        $pi_params['capture_method'] = $capture_method;
    }
    if (!empty($statement_descriptor)) {
        $pi_params['statement_descriptor_suffix'] = substr($statement_descriptor, 0, 22);
    }

    // description — resolve all shortcodes
    if (!empty($description_format)) {
        $desc = $description_format;
        $desc = str_replace('[order_id]',      $order_id,   $desc);
        $desc = str_replace('[first_name]',    $first,      $desc);
        $desc = str_replace('[last_name]',     $last,       $desc);
        $desc = str_replace('[rand_str]',      $rand_str,   $desc);
        $desc = str_replace('[merchant_site]', $money_site, $desc);
        if (!empty(trim($desc))) $pi_params['description'] = trim($desc);
    }

    // shipping
    if ($full_name && $address1) {
        $pi_params['shipping[name]']                      = $full_name;
        $pi_params['shipping[address][line1]']            = $address1;
        if ($address2) $pi_params['shipping[address][line2]']        = $address2;
        if ($city)     $pi_params['shipping[address][city]']         = $city;
        if ($state)    $pi_params['shipping[address][state]']        = $state;
        if ($postcode) $pi_params['shipping[address][postal_code]']  = $postcode;
        if ($country)  $pi_params['shipping[address][country]']      = $country;
        if ($phone)    $pi_params['shipping[phone]']                 = $phone;
    }

    // metadata
    if ($full_name)   $pi_params['metadata[customer_name]']    = $full_name;
    if ($email)       $pi_params['metadata[customer_email]']   = $email;
    if ($phone)       $pi_params['metadata[customer_phone]']   = $phone;
    if ($country)     $pi_params['metadata[customer_country]'] = $country;
    if ($money_site)  $pi_params['metadata[merchant_site]']    = $money_site;

    // Attach PaymentMethod if provided (manual flow — already created client-side)
    if (!empty($pm_id)) {
        $pi_params['payment_method'] = $pm_id;
        $pi_params['confirm']        = 'true';
        // return_url required by Stripe when confirm=true, even for non-redirect methods
        $pi_params['return_url']     = get_site_url() . '/';
    }

    $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type'  => 'application/x-www-form-urlencoded',
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

    $billing_for_js = $billing ? osc_build_billing_for_js($billing) : null;

    wp_send_json_success([
        'client_secret'   => $body['client_secret'],
        'billing_details' => $billing_for_js,
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

    $billing_for_js = osc_build_billing_for_js($billing);

    wp_send_json_success(['billing_details' => $billing_for_js]);
}

/**
 * AJAX: Update an existing PaymentIntent with full customer data.
 *
 * Runs server-side so the Stripe secret key never reaches the browser.
 * Called by the iframe JS in refreshBillingAndUpdatePI() after osp_send_billing
 * has pushed the final billing address to the Gateway Panel.
 *
 * What this does:
 *  1. Fetch billing from Gateway Panel (already stored by osp_send_billing AJAX).
 *  2. Create (or reuse) a Stripe Customer with name/email/phone/address.
 *     → The Customer ID is cached in WP options keyed by email so repeat orders
 *       reuse the same Customer (Stripe deduplication, better risk profile).
 *  3. PATCH PaymentIntent with:
 *       customer          — Stripe Customer ID (shows "Charged to Name" in dashboard)
 *       receipt_email     — sends receipt to customer
 *       description       — description_format with all shortcodes resolved
 *       shipping          — name, phone, full address (boosts AVS/CVC risk score)
 *       metadata          — customer_name, customer_email, customer_phone,
 *                           customer_country, merchant_site, order_id
 *  4. Return billing_details to JS for confirmPayment() so Stripe attaches
 *     billing to the PaymentMethod (Street check, Zip check, AVS).
 */
function osc_ajax_update_payment_intent(): void {
    $pi_id              = sanitize_text_field($_POST['pi_id']              ?? '');
    $order_id           = sanitize_text_field($_POST['order_id']           ?? '');
    $description_format = sanitize_text_field($_POST['description_format'] ?? '');
    $txn_id             = (int) ($_POST['txn_id']     ?? 0);
    $os_site_id         = (int) ($_POST['os_site_id'] ?? 0);
    $checkout_id        = sanitize_text_field($_POST['checkout_id']        ?? '');
    $money_site         = sanitize_text_field($_POST['money_site']         ?? '');

    if (empty($pi_id)) {
        wp_send_json_error('Missing pi_id');
    }

    $config     = osc_get_stripe_config();
    $secret_key = $config['secret_key'] ?? '';
    if (empty($secret_key)) {
        wp_send_json_error('Stripe not configured');
    }

    // ── 1. Fetch billing from Gateway Panel ───────────────────────────────
    $billing = null;
    if ($txn_id || $checkout_id) {
        $billing = osc_fetch_billing_from_gateway($txn_id, $os_site_id, $checkout_id);
    }

    if (empty($billing)) {
        wp_send_json_success(['billing_details' => null, 'updated' => false]);
    }

    $first     = trim($billing['first_name'] ?? '');
    $last      = trim($billing['last_name']  ?? '');
    $full_name = trim("$first $last") ?: ($billing['name'] ?? 'Guest');
    $email     = trim($billing['email']   ?? '');
    $phone     = trim($billing['phone']   ?? '');
    $address1  = trim($billing['address_1'] ?? '');
    $address2  = trim($billing['address_2'] ?? '');
    $city      = trim($billing['city']      ?? '');
    $state     = trim($billing['state']     ?? '');
    $postcode  = trim($billing['postcode']  ?? '');
    $country   = strtoupper(trim($billing['country'] ?? ''));

    // ── 2. Create or retrieve Stripe Customer ─────────────────────────────
    // Cache Customer ID per email to reuse across repeat orders.
    $customer_id = '';
    if (!empty($email)) {
        $cache_key   = 'osc_stripe_cus_' . md5($email . $secret_key);
        $customer_id = get_transient($cache_key) ?: '';

        if (empty($customer_id)) {
            // Search existing customer by email first
            $search = wp_remote_get(
                'https://api.stripe.com/v1/customers?email=' . rawurlencode($email) . '&limit=1',
                [
                    'timeout' => 8,
                    'headers' => ['Authorization' => 'Bearer ' . $secret_key],
                ]
            );
            if (!is_wp_error($search)) {
                $s = json_decode(wp_remote_retrieve_body($search), true);
                $customer_id = $s['data'][0]['id'] ?? '';
            }
        }

        if (empty($customer_id)) {
            // Create new Stripe Customer
            $cus_params = ['email' => $email];
            if ($full_name) $cus_params['name']  = $full_name;
            if ($phone)     $cus_params['phone'] = $phone;
            if ($address1) {
                $cus_params['address[line1]']       = $address1;
                if ($address2)  $cus_params['address[line2]']       = $address2;
                if ($city)      $cus_params['address[city]']        = $city;
                if ($state)     $cus_params['address[state]']       = $state;
                if ($postcode)  $cus_params['address[postal_code]'] = $postcode;
                if ($country)   $cus_params['address[country]']     = $country;
            }

            $cus_resp = wp_remote_post('https://api.stripe.com/v1/customers', [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $secret_key,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ],
                'body' => http_build_query($cus_params),
            ]);

            if (!is_wp_error($cus_resp)) {
                $cus_body = json_decode(wp_remote_retrieve_body($cus_resp), true);
                $customer_id = $cus_body['id'] ?? '';
                if ($customer_id) {
                    // Cache for 30 days
                    set_transient($cache_key, $customer_id, 30 * DAY_IN_SECONDS);
                }
            }
        }
    }

    // ── 3. Build PaymentIntent update params ──────────────────────────────
    $update_params = [];

    // Attach Customer → shows "Charged to [Name]" in Stripe dashboard
    if ($customer_id) {
        $update_params['customer'] = $customer_id;
    }

    // receipt_email — Stripe sends receipt to customer
    if ($email) {
        $update_params['receipt_email'] = $email;
    }

    // description — fully resolved with all shortcodes
    $desc_src = $description_format;
    if (!empty($desc_src)) {
        $rand_str = substr(hash('sha256', 'osp_rand|' . $order_id . '|' . $os_site_id), 0, 8);
        $desc_src = str_replace('[order_id]',     $order_id,     $desc_src);
        $desc_src = str_replace('[first_name]',   $first,        $desc_src);
        $desc_src = str_replace('[last_name]',    $last,         $desc_src);
        $desc_src = str_replace('[rand_str]',     $rand_str,     $desc_src);
        $desc_src = str_replace('[merchant_site]', $money_site,  $desc_src);
        $desc_src = trim($desc_src);
    }
    if (!empty($desc_src)) {
        $update_params['description'] = $desc_src;
    }

    // shipping — full address mirrors billing (WC stores same data for both)
    // Presence of shipping boosts Stripe risk score significantly.
    if ($full_name && $address1) {
        $update_params['shipping[name]']                  = $full_name;
        $update_params['shipping[address][line1]']        = $address1;
        if ($address2) $update_params['shipping[address][line2]']  = $address2;
        if ($city)     $update_params['shipping[address][city]']   = $city;
        if ($state)    $update_params['shipping[address][state]']  = $state;
        if ($postcode) $update_params['shipping[address][postal_code]'] = $postcode;
        if ($country)  $update_params['shipping[address][country]']    = $country;
        if ($phone)    $update_params['shipping[phone]'] = $phone;
    }

    // metadata — visible in Stripe dashboard Risk Insights + Metadata panel
    $meta = [
        'order_id'       => $order_id,
        'customer_name'  => $full_name,
        'customer_email' => $email,
    ];
    if ($phone)       $meta['customer_phone']   = $phone;
    if ($country)     $meta['customer_country'] = $country;
    if ($money_site)  $meta['merchant_site']    = $money_site;

    foreach ($meta as $k => $v) {
        if ($v !== '') {
            $update_params['metadata[' . $k . ']'] = $v;
        }
    }

    if (empty($update_params)) {
        $billing_for_js = osc_build_billing_for_js($billing);
        wp_send_json_success(['billing_details' => $billing_for_js, 'updated' => false]);
    }

    // ── 4. PATCH PaymentIntent ─────────────────────────────────────────────
    $response = wp_remote_post('https://api.stripe.com/v1/payment_intents/' . rawurlencode($pi_id), [
        'timeout' => 10,
        'headers' => [
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'body' => http_build_query($update_params),
    ]);

    $billing_for_js = osc_build_billing_for_js($billing);

    if (is_wp_error($response)) {
        wp_send_json_success(['billing_details' => $billing_for_js, 'updated' => false, 'warning' => $response->get_error_message()]);
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code >= 400) {
        wp_send_json_success(['billing_details' => $billing_for_js, 'updated' => false, 'warning' => $body['error']['message'] ?? 'PI update failed (HTTP ' . $code . ')']);
    }

    wp_send_json_success(['billing_details' => $billing_for_js, 'updated' => true]);
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

/**
 * Build a billing_details array safe to send to Stripe via JS confirmPayment().
 *
 * Rules:
 *  - name:    always present, fallback 'Guest' (Stripe requires it when billingDetails='never')
 *  - others:  omit entirely if empty — Stripe rejects empty string for country and
 *             other non-nullable fields, so we must not include them at all.
 */
function osc_build_billing_for_js(array $billing): array {
    $first = trim($billing['first_name'] ?? '');
    $last  = trim($billing['last_name']  ?? '');
    $name  = trim("$first $last") ?: ($billing['name'] ?? '');

    $result = [
        'name' => $name ?: 'Guest',
    ];

    if (!empty($billing['email'])) {
        $result['email'] = $billing['email'];
    }
    if (!empty($billing['phone'])) {
        $result['phone'] = $billing['phone'];
    }

    $addr = [];
    $addr_map = [
        'line1'       => 'address_1',
        'line2'       => 'address_2',
        'city'        => 'city',
        'state'       => 'state',
        'postal_code' => 'postcode',
        'country'     => 'country',
    ];
    foreach ($addr_map as $stripe_key => $billing_key) {
        $val = trim($billing[$billing_key] ?? '');
        if ($val !== '') {
            $addr[$stripe_key] = $val;
        }
    }
    if (!empty($addr)) {
        $result['address'] = $addr;
    }

    return $result;
}
