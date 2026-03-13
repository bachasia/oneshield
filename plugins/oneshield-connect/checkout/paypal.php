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

    // PayPal order info settings (injected from session meta by osc_handle_checkout_by_id)
    $invoice_prefix          = sanitize_text_field($_GET['invoice_prefix'] ?? '');
    $overwrite_product_title = sanitize_text_field($_GET['overwrite_product_title'] ?? 'keep_original');
    $user_define_title       = sanitize_text_field($_GET['user_define_title'] ?? '');
    $random_title_list       = sanitize_text_field($_GET['random_title_list'] ?? '');
    $product_name            = sanitize_text_field($_GET['product_name'] ?? '');
    $money_site_url          = sanitize_text_field($_GET['money_site_url'] ?? '');
    $checkout_id             = sanitize_text_field($_GET['checkout_id'] ?? '');

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
            let _draftOrderId = '';
            let _wcOrderId    = '';
            let _invoiceId    = '';

            function requestPendingOrderFromParent() {
                return new Promise(function(resolve) {
                    var done = false;
                    function finish(payload) {
                        if (done) return;
                        done = true;
                        window.removeEventListener('message', onMessage);
                        resolve(payload || null);
                    }
                    function onMessage(event) {
                        var msg = event.data;
                        if (!msg || msg.source !== 'oneshield-paygates' || msg.action !== 'oneshield-pending-order-ready') {
                            return;
                        }
                        finish(msg);
                    }
                    window.addEventListener('message', onMessage);
                    window.parent.postMessage({
                        source: 'oneshield-connect',
                        action: 'oneshield-request-pending-order',
                        gateway: 'paypal'
                    }, '*');
                    setTimeout(function() { finish(null); }, 10000);
                });
            }

            // Receive wc_order_id + invoice_id from parent Money Site before PayPal button click
            window.addEventListener('message', function(event) {
                var msg = event.data;
                if (!msg || msg.action !== 'oneshield-confirm-payment') return;
                if (msg.wc_order_id) _wcOrderId = String(msg.wc_order_id);
                if (msg.invoice_id)  _invoiceId  = String(msg.invoice_id);
            });
            const orderData = {
                order_id:                '<?php echo esc_js($order_id); ?>',
                token:                   '<?php echo esc_js($token); ?>',
                amount:                  '<?php echo esc_js(number_format($amount, 2, '.', '')); ?>',
                currency:                '<?php echo esc_js($currency); ?>',
                ajax_url:                '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                invoice_prefix:          '<?php echo esc_js($invoice_prefix); ?>',
                overwrite_product_title: '<?php echo esc_js($overwrite_product_title); ?>',
                user_define_title:       '<?php echo esc_js($user_define_title); ?>',
                random_title_list:       '<?php echo esc_js($random_title_list); ?>',
                product_name:            '<?php echo esc_js($product_name); ?>',
                money_site_url:          '<?php echo esc_js(rtrim($money_site_url, '/')); ?>',
                checkout_id:             '<?php echo esc_js($checkout_id); ?>',
            };

            function setPaypalFullscreen(open) {
                window.parent.postMessage({
                    source: 'oneshield-connect',
                    action: open ? 'paypal_overlay_open' : 'paypal_overlay_close',
                }, '*');
            }

            paypal.Buttons({
                style: {
                    layout:  'vertical',
                    color:   'gold',
                    shape:   'rect',
                    label:   'paypal',
                    height:  45,
                },

                onClick: function() {
                    // Keep parent iframe fullscreen for the whole popup flow.
                    setPaypalFullscreen(true);
                },

                createOrder: async function() {
                    // Use invoice_id from Money Site (sent via postMessage before button click)
                    // Falls back to cross-origin fetch, then to checkout-uuid
                    let invoiceId = _invoiceId || (orderData.invoice_prefix
                        ? orderData.invoice_prefix + '-' + orderData.order_id
                        : orderData.order_id);
                    let draftOrderId = _wcOrderId || '';

                    // Preferred path: ask parent (Money Site) to create pending WC order
                    // using same-origin cart/session, then return wc_order_id + invoice_id.
                    if (!_invoiceId) {
                        console.log('OSC: calling requestPendingOrderFromParent...');
                        const pending = await requestPendingOrderFromParent();
                        console.log('OSC: requestPendingOrderFromParent resolved with', pending);
                        if (pending && pending.success && pending.invoice_id) {
                            invoiceId    = String(pending.invoice_id);
                            draftOrderId = String(pending.wc_order_id || '');
                            _invoiceId   = invoiceId;
                            _wcOrderId   = draftOrderId;
                            console.log('OSC: using parent-provided invoice_id', invoiceId);
                        } else {
                            console.warn('OSC: postMessage path failed or timed out, pending=', pending);
                        }
                    }

                    // Only try cross-origin fetch if Money Site didn't already send invoice_id
                    if (!_invoiceId && orderData.money_site_url && orderData.checkout_id) {
                        try {
                            const draftUrl = orderData.money_site_url + '/wp-admin/admin-ajax.php';
                            console.log('OSC: fetching draft order from', draftUrl);
                            const draftResp = await fetch(draftUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action:               'osp_get_paypal_invoice_id',
                                checkout_session_id:  orderData.checkout_id,
                                amount:               orderData.amount,
                                currency:             orderData.currency,
                            }),
                            });
                            console.log('OSC: draft response status', draftResp.status);
                            const draftText = await draftResp.text();
                            console.log('OSC: draft response body', draftText);
                            let draftData;
                            try { draftData = JSON.parse(draftText); } catch(pe) { draftData = null; }
                            if (draftData && draftData.success) {
                                invoiceId     = draftData.data.invoice_id;
                                _draftOrderId = '';
                                console.log('OSC: using invoice_id', invoiceId);
                            } else {
                                console.warn('OSC: invoice_id fetch failed', draftData);
                            }
                        } catch (e) {
                            console.error('OSC: could not fetch draft order id', e);
                        }
                    } else {
                        console.warn('OSC: missing money_site_url or checkout_id', orderData.money_site_url, orderData.checkout_id);
                    }

                    // Step 2: create PayPal order with correct invoice_id
                    const resp = await fetch(orderData.ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action:                  'osc_create_paypal_order',
                            order_id:                orderData.order_id,
                            amount:                  orderData.amount,
                            currency:                orderData.currency,
                            invoice_id_override:     invoiceId,
                            overwrite_product_title: orderData.overwrite_product_title,
                            user_define_title:       orderData.user_define_title,
                            random_title_list:       orderData.random_title_list,
                            product_name:            orderData.product_name,
                            draft_order_id:          draftOrderId,
                        }),
                    });
                    const data = await resp.json();
                    if (!data.success) {
                        showError(data.data || 'Failed to create order.');
                        setPaypalFullscreen(false);
                        return null;
                    }
                    return data.data.paypal_order_id;
                },

                onCancel: function() {
                    setPaypalFullscreen(false);
                },

                onApprove: async function(data) {
                    const resp = await fetch(orderData.ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action:          'osc_capture_paypal_order',
                            paypal_order_id: data.orderID,
                            order_id:        orderData.order_id,
                            draft_order_id:  _draftOrderId,
                        }),
                    });
                    const result = await resp.json();

                    if (result.success) {
                        setPaypalFullscreen(false);
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
                            source:          'oneshield-connect',
                            status:          'success',
                            gateway:         'paypal',
                            transaction_id:  result.data.capture_id,
                            paypal_order_id: data.orderID,
                            order_id:        orderData.order_id,
                            draft_order_id:  result.data.draft_order_id || '',
                        }, '*');
                    } else {
                        setPaypalFullscreen(false);
                        const errMsg = (result.data && result.data.message)
                            ? result.data.message
                            : (result.data || 'Payment capture failed.');
                        console.error('OSC capture error:', result.data);
                        showError(errMsg);
                    }
                },

                onError: function(err) {
                    setPaypalFullscreen(false);
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

            // Poll #paypal-button-container offsetHeight every 50ms.
            var _lastPaypalHeight = 0;
            setInterval(function() {
                var container = document.getElementById('paypal-button-container');
                if (!container) return;
                var h = container.offsetHeight;
                if (h !== _lastPaypalHeight && h > 0) {
                    _lastPaypalHeight = h;
                    window.parent.postMessage({
                        source: 'oneshield-connect',
                        action: 'resize',
                        height: document.body.scrollHeight,
                    }, '*');
                }
            }, 50);

            // PayPal SDK injects paypal-overlay-uid-* into this iframe during
            // popup/card flow. Force fullscreen while detected, but do not send
            // auto-close here to avoid flicker; close is handled by callbacks.
            setInterval(function() {
                var overlayOpen = !!document.querySelector('[id*="paypal-overlay-uid"]');
                if (overlayOpen) {
                    setPaypalFullscreen(true);
                }
            }, 50);
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

    // Order info settings passed from money site via session meta → $_GET
    $invoice_id_override     = sanitize_text_field($_POST['invoice_id_override'] ?? '');
    $overwrite_product_title = sanitize_text_field($_POST['overwrite_product_title'] ?? 'keep_original');
    $user_define_title       = sanitize_text_field($_POST['user_define_title'] ?? '');
    $random_title_list       = sanitize_text_field($_POST['random_title_list'] ?? '');
    $product_name            = sanitize_text_field($_POST['product_name'] ?? '');

    // Resolve item name based on overwrite_product_title setting
    $item_name = osc_resolve_paypal_item_name(
        $overwrite_product_title,
        $product_name,
        $user_define_title,
        $random_title_list,
        $order_id
    );

    // Use invoice_id from JS (which fetched draft order id from money site), fallback to order_id
    $invoice_id = !empty($invoice_id_override) ? $invoice_id_override : $order_id;

    $access_token = osc_get_paypal_access_token();
    if (is_wp_error($access_token)) {
        wp_send_json_error($access_token->get_error_message());
    }

    $config   = osc_get_paypal_config();
    $api_base = ($config['mode'] === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

    $purchase_unit = [
        'reference_id' => $order_id,
        'invoice_id'   => $invoice_id,
        'amount'       => [
            'currency_code' => $currency,
            'value'         => $amount,
            'breakdown'     => [
                'item_total' => ['currency_code' => $currency, 'value' => $amount],
            ],
        ],
        'items' => [[
            'name'       => $item_name,
            'unit_amount'=> ['currency_code' => $currency, 'value' => $amount],
            'quantity'   => '1',
        ]],
    ];

    $response = wp_remote_post($api_base . '/v2/checkout/orders', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode([
            'intent'         => 'CAPTURE',
            'purchase_units' => [$purchase_unit],
        ]),
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    wp_send_json_success(['paypal_order_id' => $body['id']]);
}

/**
 * Resolve the PayPal item name based on the overwrite_product_title setting.
 *
 * Supported shortcodes in user_define_title:
 *   [order_id]            – the order reference
 *   [last_word]           – last word of the original product name
 *   [rand_title_from_list]– random item from the comma-separated random_title_list
 *   [rand_N]              – random alphanumeric string of length N (N > 1)
 */
function osc_resolve_paypal_item_name(
    string $mode,
    string $original_name,
    string $user_define_title,
    string $random_title_list,
    string $order_id
): string {
    switch ($mode) {
        case 'use_last_word':
            $words = preg_split('/\s+/', trim($original_name), -1, PREG_SPLIT_NO_EMPTY);
            return !empty($words) ? end($words) : $original_name;

        case 'user_define':
            return osc_process_title_shortcodes($user_define_title, $original_name, $random_title_list, $order_id);

        case 'keep_original':
        default:
            return $original_name ?: $order_id;
    }
}

/**
 * Process shortcodes in user-defined title template.
 */
function osc_process_title_shortcodes(
    string $template,
    string $original_name,
    string $random_title_list,
    string $order_id
): string {
    if (empty($template)) {
        return $original_name ?: $order_id;
    }

    // [order_id] → order reference
    $result = str_replace('[order_id]', $order_id, $template);

    // [last_word] → last word of original product name
    $words     = preg_split('/\s+/', trim($original_name), -1, PREG_SPLIT_NO_EMPTY);
    $last_word = !empty($words) ? end($words) : $original_name;
    $result    = str_replace('[last_word]', $last_word, $result);

    // [rand_title_from_list] → random item from comma-separated list
    if (strpos($result, '[rand_title_from_list]') !== false) {
        $titles = array_filter(array_map('trim', explode(',', $random_title_list)));
        $random_title = !empty($titles) ? $titles[array_rand($titles)] : '';
        $result = str_replace('[rand_title_from_list]', $random_title, $result);
    }

    // [rand_N] → random alphanumeric string of length N
    $result = preg_replace_callback('/\[rand_(\d+)\]/', function ($matches) {
        $length = max(2, (int) $matches[1]);
        $chars  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str    = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $str;
    }, $result);

    return trim($result) ?: ($original_name ?: $order_id);
}

// AJAX: Capture PayPal order
add_action('wp_ajax_nopriv_osc_capture_paypal_order', 'osc_ajax_capture_paypal_order');
add_action('wp_ajax_osc_capture_paypal_order', 'osc_ajax_capture_paypal_order');

function osc_ajax_capture_paypal_order(): void {
    // Skip nonce: cross-origin iframe blocks cookies → nonce always fails.

    $paypal_order_id = sanitize_text_field($_POST['paypal_order_id'] ?? '');
    $draft_order_id  = sanitize_text_field($_POST['draft_order_id']  ?? '');

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

    $http_code  = wp_remote_retrieve_response_code($response);
    $raw_body   = wp_remote_retrieve_body($response);
    $body       = json_decode($raw_body, true);
    $capture_id = $body['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;

    // Also check for INSTRUMENT_DECLINED or other soft declines
    $capture_status = $body['purchase_units'][0]['payments']['captures'][0]['status'] ?? null;

    if ($capture_id && $capture_status !== 'DECLINED') {
        wp_send_json_success([
            'capture_id'     => $capture_id,
            'draft_order_id' => $draft_order_id,
        ]);
    } else {
        // Return PayPal error details for debugging
        $pp_error = $body['message'] ?? ($body['details'][0]['description'] ?? $raw_body);
        $pp_issue = $body['details'][0]['issue'] ?? '';
        wp_send_json_error([
            'message'   => 'Capture failed (HTTP ' . $http_code . '): ' . $pp_error,
            'issue'     => $pp_issue,
            'http_code' => $http_code,
            'raw'       => $raw_body,
        ]);
    }
}

// AJAX: Patch PayPal order invoice_id with real WC order ID (called by money site after process_payment)
add_action('wp_ajax_nopriv_osc_patch_paypal_invoice', 'osc_ajax_patch_paypal_invoice');
add_action('wp_ajax_osc_patch_paypal_invoice', 'osc_ajax_patch_paypal_invoice');

function osc_ajax_patch_paypal_invoice(): void {
    $paypal_order_id = sanitize_text_field($_POST['paypal_order_id'] ?? '');
    $invoice_id      = sanitize_text_field($_POST['invoice_id'] ?? '');

    if (empty($paypal_order_id) || empty($invoice_id)) {
        wp_send_json_error('Missing paypal_order_id or invoice_id');
    }

    $access_token = osc_get_paypal_access_token();
    if (is_wp_error($access_token)) {
        wp_send_json_error($access_token->get_error_message());
    }

    $config   = osc_get_paypal_config();
    $api_base = ($config['mode'] === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

    // PayPal Orders v2 PATCH: update invoice_id on purchase_units[0]
    $response = wp_remote_request($api_base . '/v2/checkout/orders/' . $paypal_order_id, [
        'method'  => 'PATCH',
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode([[
            'op'    => 'replace',
            'path'  => '/purchase_units/@reference_id==\'default\'/invoice_id',
            'value' => $invoice_id,
        ]]),
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    // PayPal PATCH returns 204 No Content on success
    if ($code === 204 || $code === 200) {
        wp_send_json_success(['invoice_id' => $invoice_id]);
    } else {
        $body = wp_remote_retrieve_body($response);
        wp_send_json_error('PayPal PATCH failed (' . $code . '): ' . $body);
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
