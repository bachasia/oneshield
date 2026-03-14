/**
 * OneShield Paygates — Checkout JS
 *
 * Flow (billing sent at Place Order time, not at page load):
 *  1. Iframe is rendered inside payment_fields() when checkout loads.
 *     PaymentIntent is created but without billing — just amount/currency.
 *  2. Customer fills in card details inside the iframe (iframe is ready).
 *  3. Customer clicks Place Order:
 *     a. JS intercepts the click.
 *     b. Shows full-page loading overlay.
 *     c. If send_billing is enabled: AJAX osp_send_billing → gateway panel
 *        stores final billing on the pending transaction.
 *     d. postMessage 'oneshield-confirm-payment' + txn_id → iframe.
 *     e. Iframe fetches billing from gateway panel (server-side),
 *        attaches to confirmPayment(), processes Stripe payment.
 *     f. Stripe responds → iframe postMessage 'success' + transaction_id.
 *  4. JS receives success → writes transaction_id into hidden input.
 *  5. JS re-submits WC checkout form.
 *  6. process_payment() reads hidden input, confirms with panel, completes order.
 */

(function ($) {
    'use strict';

    var ospData        = window.osp_data || {};
    var ajaxUrl        = ospData.ajax_url || '';
    var sendBillingGws = ospData.send_billing_gateways || [];

    // ── Inject PayPal fullscreen iframe CSS ──────────────────────────────────
    // When paypal_overlay_open is received, make the iframe position:fixed so
    // the PayPal SDK overlay inside it expands to cover the full money-site page.
    // PayPal buttons inside the iframe are hidden via onClick (paypal.php) BEFORE
    // the iframe goes fullscreen, preventing the flash-at-top-of-page bug.
    (function () {
        var s = document.createElement('style');
        s.id = 'osp-pp-fullscreen-style';
        s.textContent = [
            '.osp-pp-fullscreen{',
                'position:fixed!important;',
                'top:0!important;left:0!important;',
                'width:100vw!important;height:100vh!important;',
                'z-index:99999999!important;',
                'border:none!important;',
                'margin:0!important;padding:0!important;',
            '}',
            // Also hide the paypal-button-wrap completely while fullscreen
            // to prevent any bleed-through from the parent layout
            '.osp-paypal-overlay-active #osp-paypal-button-wrap{',
                'visibility:hidden!important;',
            '}',
        ].join('');
        document.head.appendChild(s);
    })();

    function cleanupLegacyPayPalOverlay() {
        var legacyOverlay = document.getElementById('osp-pp-fs');
        if (legacyOverlay) {
            legacyOverlay.remove();
        }
        var legacyStyle = document.getElementById('osp-pp-fs-style');
        if (legacyStyle) {
            legacyStyle.remove();
        }
        if (document.body) {
            document.body.style.overflow = '';
        }
    }

    cleanupLegacyPayPalOverlay();

    // Track state per gateway
    var state = {
        stripe: { confirmed: false, txnId: '' },
        paypal: { confirmed: false, txnId: '' },
    };
    var isConfirming = false;
    var confirmTimeoutId = null;

    // Tracks whether PayPal SDK overlay is active inside the iframe.
    // While true: iframe is position:fixed fullscreen, togglePayPalIframePosition is frozen.
    var _isPaypalOverlayOpen = false;

    // ── Loading overlay ─────────────────────────────────────────────────────

    var $overlay = null;

    function createOverlay() {
        if ($overlay) return;
        $overlay = $([
            '<div id="osp-payment-overlay" style="',
                'display:none;',
                'position:fixed;inset:0;z-index:999999;',
                'background:rgba(255,255,255,0.92);',
                'backdrop-filter:blur(3px);',
                '-webkit-backdrop-filter:blur(3px);',
                'align-items:center;justify-content:center;flex-direction:column;gap:16px;',
            '">',
                '<div id="osp-overlay-spinner" style="',
                    'width:48px;height:48px;',
                    'border:4px solid #e5e7eb;',
                    'border-top-color:#6366f1;',
                    'border-radius:50%;',
                    'animation:osp-spin 0.8s linear infinite;',
                '"></div>',
                '<p id="osp-overlay-text" style="',
                    'margin:0;font-size:16px;font-weight:600;color:#111827;',
                    'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif!important;',
                    'font-style:normal!important;letter-spacing:normal!important;',
                '">Processing your payment…</p>',
                '<p id="osp-overlay-sub" style="',
                    'margin:0;font-size:13px;color:#6b7280;',
                    'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif!important;',
                    'font-style:normal!important;letter-spacing:normal!important;',
                '">Please do not close or refresh this page.</p>',
            '</div>',
            '<style>',
                '@keyframes osp-spin{to{transform:rotate(360deg)}}',
                '#osp-payment-overlay{display:none}',
                '#osp-payment-overlay.osp-visible{display:flex!important}',
                '#osp-overlay-text,#osp-overlay-sub{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif!important;font-style:normal!important;}',
            '</style>',
        ].join(''));
        $('body').append($overlay);
    }

    function showOverlay(text, sub) {
        createOverlay();
        $('#osp-overlay-text').text(text || 'Processing your payment…');
        $('#osp-overlay-sub').text(sub || 'Please do not close or refresh this page.');
        // Reset spinner color
        $('#osp-overlay-spinner').css('border-top-color', '#6366f1');
        $overlay.filter('#osp-payment-overlay').addClass('osp-visible');
    }

    function hideOverlay() {
        if ($overlay) {
            $overlay.filter('#osp-payment-overlay').removeClass('osp-visible');
        }
    }

    function showOverlaySuccess() {
        createOverlay();
        $('#osp-overlay-spinner').replaceWith(
            '<div id="osp-overlay-spinner" style="width:48px;height:48px;">' +
            '<svg width="48" height="48" fill="none" viewBox="0 0 24 24">' +
            '<circle cx="12" cy="12" r="11" stroke="#16a34a" stroke-width="2"/>' +
            '<path stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M7 12l3.5 3.5L17 9"/>' +
            '</svg></div>'
        );
        $('#osp-overlay-text').text('Payment approved!');
        $('#osp-overlay-sub').text('Completing your order…');
        $overlay.filter('#osp-payment-overlay').addClass('osp-visible');
    }

    // ── Detect active OneShield gateway ────────────────────────────────────

    function getActiveOspGateway() {
        var checked = $('input[name="payment_method"]:checked').val() || '';
        if (checked === 'os_stripe') return 'stripe';
        if (checked === 'os_paypal') return 'paypal';
        return null;
    }

    function getIframe(gateway) {
        return document.getElementById('osp-iframe-' + gateway);
    }

    function collectCheckoutFields() {
        var $form = $('form.checkout, form#order_review');
        return {
            billing_first_name:   $form.find('[name="billing_first_name"]').val() || '',
            billing_last_name:    $form.find('[name="billing_last_name"]').val()  || '',
            billing_email:        $form.find('[name="billing_email"]').val()      || '',
            billing_phone:        $form.find('[name="billing_phone"]').val()      || '',
            billing_address_1:    $form.find('[name="billing_address_1"]').val()  || '',
            billing_address_2:    $form.find('[name="billing_address_2"]').val()  || '',
            billing_city:         $form.find('[name="billing_city"]').val()       || '',
            billing_state:        $form.find('[name="billing_state"]').val()      || '',
            billing_postcode:     $form.find('[name="billing_postcode"]').val()   || '',
            billing_country:      $form.find('[name="billing_country"]').val()    || '',
            shipping_first_name:  $form.find('[name="shipping_first_name"]').val() || $form.find('[name="billing_first_name"]').val() || '',
            shipping_last_name:   $form.find('[name="shipping_last_name"]').val()  || $form.find('[name="billing_last_name"]').val()  || '',
            shipping_address_1:   $form.find('[name="shipping_address_1"]').val()  || $form.find('[name="billing_address_1"]').val()  || '',
            shipping_address_2:   $form.find('[name="shipping_address_2"]').val()  || $form.find('[name="billing_address_2"]').val()  || '',
            shipping_city:        $form.find('[name="shipping_city"]').val()       || $form.find('[name="billing_city"]').val()       || '',
            shipping_state:       $form.find('[name="shipping_state"]').val()      || $form.find('[name="billing_state"]').val()      || '',
            shipping_postcode:    $form.find('[name="shipping_postcode"]').val()   || $form.find('[name="billing_postcode"]').val()   || '',
            shipping_country:     $form.find('[name="shipping_country"]').val()    || $form.find('[name="billing_country"]').val()    || '',
            order_comments:       $form.find('[name="order_comments"]').val()      || '',
        };
    }

    // ── Toggle Place Order button and PayPal iframe wrap ──────────────────
    // #osp-paypal-button-wrap is rendered outside the payment box by PHP
    // (woocommerce_review_order_after_submit hook) so WC updated_checkout
    // never destroys it. We simply show/hide it here.

    function togglePayPalIframePosition() {
        if (_isPaypalOverlayOpen) return;

        var gateway     = getActiveOspGateway();
        var $placeOrder = $('#place_order');
        var $ppWrap     = $('#osp-paypal-button-wrap');

        if (gateway === 'paypal') {
            if ($ppWrap.length) {
                // Hide Place Order, show the native PayPal button iframe instead.
                $placeOrder.hide();
                $ppWrap.css({ visibility: '', height: '', overflow: '', 'margin-top': '' }).show();
            } else {
                // Iframe not rendered (API error) — fall back to Place Order.
                $placeOrder.show();
            }
        } else {
            $placeOrder.show();
            $ppWrap.hide();
        }
    }

    // Run on page load
    togglePayPalIframePosition();

    // Run when payment method changes
    $(document.body).on('change', 'input[name="payment_method"]', function () {
        togglePayPalIframePosition();
    });

    // Some themes/plugins switch payment method without firing a native
    // change event consistently. Keep PayPal button visibility in sync.
    $(document.body).on('payment_method_selected updated_checkout', function () {
        togglePayPalIframePosition();
    });

    // Run after WooCommerce updates checkout (Place Order button may re-appear)
    // NOTE: a second updated_checkout listener at the bottom also calls this for
    // consistency — keeping them separate makes the toggle self-contained here.

    // ── Auto-resize iframe ──────────────────────────────────────────────────

    // Track last known PayPal iframe height so we can restore it after overlay closes.
    var _lastPaypalIframeHeight = 200;

    window.addEventListener('message', function (event) {
        if (!event.data || event.data.source !== 'oneshield-connect') return;
        var msg = event.data;

        if (msg.action === 'resize' && msg.height) {
            var gateway = getActiveOspGateway();
            if (!gateway) return;
            var f = getIframe(gateway);
            if (f) {
                var newH = Math.max(msg.height, 50);
                f.style.height = newH + 'px';
                if (gateway === 'paypal') {
                    _lastPaypalIframeHeight = newH;
                }
            }
        }

        // PayPal SDK overlay opened inside iframe → make iframe fullscreen.
        // Buttons in iframe are already hidden via onClick (paypal.php).
        // Also add class to body to hide wrap via CSS, preventing flash.
        if (msg.action === 'paypal_overlay_open') {
            cleanupLegacyPayPalOverlay();
            _isPaypalOverlayOpen = true;
            document.body.classList.add('osp-paypal-overlay-active');
            setPayPalIframeFullscreen(true);
            // Tell iframe to stretch its body to 100vh so the PayPal SDK dark
            // overlay (position:fixed inside iframe) fills the entire iframe viewport.
            var ppIframe = document.getElementById('osp-iframe-paypal');
            if (ppIframe && ppIframe.contentWindow) {
                ppIframe.contentWindow.postMessage({
                    source: 'oneshield-paygates',
                    action: 'osp-iframe-fullscreen',
                    open:   true,
                }, '*');
            }
        }

        // PayPal overlay closed → restore iframe, remove body class, re-show wrap.
        if (msg.action === 'paypal_overlay_close') {
            cleanupLegacyPayPalOverlay();
            _isPaypalOverlayOpen = false;
            document.body.classList.remove('osp-paypal-overlay-active');
            setPayPalIframeFullscreen(false);
            // Tell iframe to restore its body height
            var ppIframe = document.getElementById('osp-iframe-paypal');
            if (ppIframe && ppIframe.contentWindow) {
                ppIframe.contentWindow.postMessage({
                    source: 'oneshield-paygates',
                    action: 'osp-iframe-fullscreen',
                    open:   false,
                }, '*');
            }
            togglePayPalIframePosition();
        }

        // Iframe asks parent to validate WC checkout form before PayPal createOrder runs.
        // Replies with valid: true/false so createOrder can cancel if form is incomplete.
        if (msg.action === 'oneshield-validate-checkout' && msg.gateway === 'paypal') {
            var $vForm  = $('form.checkout, form#order_review');
            var vValid  = true;

            $vForm.find('.validate-required').each(function() {
                var $row   = $(this);
                var $input = $row.find('input:not([type=hidden]), select, textarea').first();
                if (!$input.length) return;
                var val = $.trim($input.val() || '');
                if (val === '' || val === 'undefined') {
                    vValid = false;
                    $row.addClass('woocommerce-invalid woocommerce-invalid-required-field');
                    $row.removeClass('woocommerce-validated');
                } else {
                    $row.addClass('woocommerce-validated');
                    $row.removeClass('woocommerce-invalid woocommerce-invalid-required-field');
                }
            });

            var vEmail = $.trim($vForm.find('[name="billing_email"]').val() || '');
            if (vEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(vEmail)) {
                vValid = false;
                $vForm.find('[name="billing_email"]').closest('.form-row')
                    .addClass('woocommerce-invalid woocommerce-invalid-required-field')
                    .removeClass('woocommerce-validated');
            }

            if (!vValid) {
                var $firstInvalid = $vForm.find('.woocommerce-invalid').first();
                if ($firstInvalid.length) {
                    $('html, body').animate({ scrollTop: $firstInvalid.offset().top - 120 }, 300);
                }
            }

            event.source.postMessage({
                source: 'oneshield-paygates',
                action: 'oneshield-validate-result',
                valid:  vValid,
            }, '*');
        }

        // Iframe asks parent (Money Site) to create pending WC order and return
        // wc_order_id + invoice_id before calling PayPal createOrder.
        if (msg.action === 'oneshield-request-pending-order' && msg.gateway === 'paypal') {
            var checkoutId = $('[name="osp_paypal_os_checkout_id"]').val() || '';
            console.log('[OSP] received oneshield-request-pending-order, checkoutId=', checkoutId, 'nonce=', ospData.nonce || '(empty)', 'ajaxUrl=', ajaxUrl);
            var payload = $.extend({
                action:              'osp_create_paypal_pending_order',
                nonce:               ospData.nonce || '',
                checkout_session_id: checkoutId,
            }, collectCheckoutFields());

            $.post(ajaxUrl, payload)
                .done(function(resp) {
                    console.log('[OSP] osp_create_paypal_pending_order response:', resp);
                    var reply = {
                        source:   'oneshield-paygates',
                        action:   'oneshield-pending-order-ready',
                        success:  !!(resp && resp.success),
                        wc_order_id: resp && resp.data ? resp.data.wc_order_id : '',
                        invoice_id:  resp && resp.data ? resp.data.invoice_id  : '',
                        message:  resp && !resp.success ? (resp.data || 'create pending order failed') : '',
                    };
                    console.log('[OSP] sending oneshield-pending-order-ready reply:', reply);
                    event.source.postMessage(reply, '*');
                })
                .fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('[OSP] osp_create_paypal_pending_order AJAX failed:', textStatus, errorThrown, jqXHR.responseText);
                    event.source.postMessage({
                        source: 'oneshield-paygates',
                        action: 'oneshield-pending-order-ready',
                        success: false,
                        message: 'network_error',
                    }, '*');
                });
        }

        // When iframe Stripe Elements is ready, push billing_country immediately
        // so the Payment Element can use it in confirmPayment billing_details.
        // This runs before the user clicks Place Order, giving the iframe the
        // country it needs without waiting for osp_send_billing AJAX.
        if (msg.action === 'stripe_ready') {
            var readyGateway = getActiveOspGateway();
            var readyIframe  = readyGateway ? getIframe(readyGateway) : null;
            if (readyIframe) {
                var $form   = $('form.checkout, form#order_review');
                var country = $form.find('[name="billing_country"]').val() || '';
                if (country) {
                    readyIframe.contentWindow.postMessage({
                        action:          'oneshield-prefill-billing',
                        billing_country: country,
                    }, '*');
                }
            }
        }

        // Hide overlay + reset state if iframe reports an error
        if (msg.action === 'payment_error') {
            hideOverlay();
            isConfirming = false;
            if (confirmTimeoutId) {
                clearTimeout(confirmTimeoutId);
                confirmTimeoutId = null;
            }
            // Re-enable Place Order button
            $('#place_order').prop('disabled', false).css('opacity', '');
            // Show error notice WC-style
            var errMsg = msg.message || 'Payment failed. Please try again.';
            var $notices = $('.woocommerce-notices-wrapper').first();
            if ($notices.length) {
                $notices.html(
                    '<ul class="woocommerce-error" role="alert">' +
                    '<li>' + $('<div>').text(errMsg).html() + '</li>' +
                    '</ul>'
                );
                $('html, body').animate({ scrollTop: $notices.offset().top - 100 }, 400);
            }
        }
    });

    // ── Listen for payment success from iframe ──────────────────────────────

    window.addEventListener('message', function (event) {
        if (!event.data || event.data.source !== 'oneshield-connect') return;
        var msg = event.data;

        // Debug messages from iframe — silently ignore in production
        if (msg.action === 'debug') {
            return;
        }

        if (msg.status !== 'success' || !msg.transaction_id) return;

        var gateway = msg.gateway; // 'stripe' or 'paypal'
        if (!gateway || !state[gateway]) {
            return;
        }

        state[gateway].confirmed     = true;
        state[gateway].txnId         = msg.transaction_id;
        state[gateway].paypalOrderId = msg.paypal_order_id || '';

        var prefix = 'osp_' + gateway;

        var osTxnInput      = document.querySelector('[name="' + prefix + '_os_transaction_id"]');
        var siteInput       = document.querySelector('[name="' + prefix + '_os_site_id"]');
        var checkoutIdInput = document.querySelector('[name="' + prefix + '_os_checkout_id"]');

        // Show success overlay
        showOverlaySuccess();

        // Replace iframe with success UI
        var iframe = getIframe(gateway);
        if (iframe) {
            var box = iframe.parentElement;
            box.innerHTML =
                '<div style="padding:24px;text-align:center;border:1px solid #bbf7d0;border-radius:8px;background:#f0fdf4;">' +
                '<svg width="40" height="40" fill="none" viewBox="0 0 24 24" style="margin:0 auto 12px;display:block;">' +
                '<circle cx="12" cy="12" r="11" stroke="#16a34a" stroke-width="2"/>' +
                '<path stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M7 12l3.5 3.5L17 9"/>' +
                '</svg>' +
                '<p style="margin:0;font-size:15px;font-weight:600;color:#15803d;">Payment Authorised</p>' +
                '<p style="margin:6px 0 0;font-size:13px;color:#4ade80;">Completing your order…</p>' +
                '</div>';
        }

        // ── PayPal: complete via AJAX (no WC form submit — avoids duplicate order) ──
        console.log('[OSP] payment success msg:', JSON.stringify(msg));
        if (gateway === 'paypal') {
            var billingData = collectCheckoutFields();
            var completePayload = $.extend({
                action:           'osp_complete_paypal_pending_order',
                nonce:            ospData.nonce || '',
                pending_order_id: msg.draft_order_id || '',
                txn_id:           msg.transaction_id,
                paypal_order_id:  msg.paypal_order_id || '',
                os_checkout_id:   checkoutIdInput ? checkoutIdInput.value : '',
                os_txn_id:        osTxnInput      ? osTxnInput.value      : '',
                os_site_id:       siteInput        ? siteInput.value        : '',
            }, billingData);
            console.log('[OSP] calling osp_complete_paypal_pending_order, pending_order_id=', msg.draft_order_id);

            $.post(ajaxUrl, completePayload)
                .done(function(resp) {
                    console.log('[OSP] osp_complete_paypal_pending_order resp:', resp);
                    if (resp && resp.success && resp.data && resp.data.redirect) {
                        window.location.href = resp.data.redirect;
                    } else {
                        window.location.reload();
                    }
                })
                .fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('[OSP] osp_complete_paypal_pending_order failed:', textStatus, errorThrown, jqXHR.responseText);
                    window.location.reload();
                });
            return;
        }

        // ── Stripe: submit WC form ──────────────────────────────────────────────
        var form = document.getElementById('order_review') || document.querySelector('form.checkout');
        if (form) {
            // Write transaction_id into hidden inputs
            document.querySelectorAll('[name="' + prefix + '_transaction_id"]').forEach(function(el) {
                el.value = msg.transaction_id;
            });
            var existing = form.querySelector('#osp_txn_injected_' + gateway);
            if (existing) existing.remove();
            var injected = document.createElement('input');
            injected.type  = 'hidden';
            injected.id    = 'osp_txn_injected_' + gateway;
            injected.name  = prefix + '_transaction_id';
            injected.value = msg.transaction_id;
            form.appendChild(injected);

            // Re-inject txn_id if WC re-renders form before submit
            $(document.body).on('updated_checkout.osp_protect', function () {
                if (!state[gateway] || !state[gateway].confirmed || !state[gateway].txnId) return;
                var f = document.getElementById('order_review') || document.querySelector('form.checkout');
                if (!f) return;
                var ex = f.querySelector('#osp_txn_injected_' + gateway);
                if (!ex) {
                    var inp = document.createElement('input');
                    inp.type  = 'hidden';
                    inp.id    = 'osp_txn_injected_' + gateway;
                    inp.name  = 'osp_' + gateway + '_transaction_id';
                    inp.value = state[gateway].txnId;
                    f.appendChild(inp);
                }
            });

            setTimeout(function () {
                $(form).submit();
            }, 100);
        }
    });

    // ── Intercept Place Order click ─────────────────────────────────────────

    $(document.body).on('click', '#place_order', function (e) {
        var gateway = getActiveOspGateway();
        if (!gateway) return; // Not an OneShield gateway — let WC handle normally

        // If already confirmed (re-submit after success), let it through
        if (state[gateway].confirmed) return;

        if (isConfirming) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
        }

        var iframe       = getIframe(gateway);
        var osTxnId      = parseInt($('[name="osp_' + gateway + '_os_transaction_id"]').val() || '0', 10);
        var osSiteId     = parseInt($('[name="osp_' + gateway + '_os_site_id"]').val() || '0', 10);
        var osCheckoutId = $('[name="osp_' + gateway + '_os_checkout_id"]').val() || '';

        if (!iframe || (!osTxnId && !osCheckoutId)) {
            // No iframe or no payment context at all — let WC handle normally.
            return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();
        isConfirming = true;

        var $btn = $(this);
        $btn.prop('disabled', true).css('opacity', '0.6');

        // Show loading overlay
        showOverlay();

        var wcOrderId   = 0;
        var invoiceId   = '';

        function doConfirm() {
            // postMessage to iframe: trigger Stripe/PayPal confirmation
            iframe.contentWindow.postMessage({
                action:       'oneshield-confirm-payment',
                txn_id:       osTxnId,
                site_id:      osSiteId,
                checkout_id:  osCheckoutId,
                wc_order_id:  wcOrderId,
                invoice_id:   invoiceId,
            }, '*');
        }

        // For PayPal: just open the PayPal popup (iframe) by sending the confirm postMessage.
        // The iframe itself will call requestPendingOrderFromParent (which triggers
        // osp_create_paypal_pending_order on the money site) inside its createOrder callback.
        // We must NOT call osp_create_paypal_pending_order here — doing so would race with
        // the iframe's call and create a duplicate WC order before the transient cache is set.
        function doCreatePendingOrderThenConfirm(billingData) {
            doConfirm();
        }

        // If send_billing enabled for this gateway, push billing first
        if (sendBillingGws.indexOf(gateway) !== -1 && (osTxnId || osCheckoutId)) {
            // Collect billing fields directly from the WC checkout form.
            // WC()->customer session is not yet updated at AJAX time, so we
            // must read from the DOM and pass the values explicitly.
            var $form = $('form.checkout, form#order_review');
            var billingData = {
                action:            'osp_send_billing',
                gateway:           gateway,
                os_txn_id:         osTxnId,
                os_checkout_id:    osCheckoutId,
                nonce:             ospData.nonce || '',
                billing_first_name: $form.find('[name="billing_first_name"]').val() || '',
                billing_last_name:  $form.find('[name="billing_last_name"]').val()  || '',
                billing_email:      $form.find('[name="billing_email"]').val()       || '',
                billing_phone:      $form.find('[name="billing_phone"]').val()       || '',
                billing_address_1:  $form.find('[name="billing_address_1"]').val()  || '',
                billing_address_2:  $form.find('[name="billing_address_2"]').val()  || '',
                billing_city:       $form.find('[name="billing_city"]').val()        || '',
                billing_state:      $form.find('[name="billing_state"]').val()       || '',
                billing_postcode:   $form.find('[name="billing_postcode"]').val()    || '',
                billing_country:    $form.find('[name="billing_country"]').val()     || '',
                // Shipping address — may differ from billing
                shipping_first_name: $form.find('[name="shipping_first_name"]').val() || $form.find('[name="billing_first_name"]').val() || '',
                shipping_last_name:  $form.find('[name="shipping_last_name"]').val()  || $form.find('[name="billing_last_name"]').val()  || '',
                shipping_phone:      $form.find('[name="shipping_phone"]').val()      || $form.find('[name="billing_phone"]').val()      || '',
                shipping_address_1:  $form.find('[name="shipping_address_1"]').val()  || $form.find('[name="billing_address_1"]').val()  || '',
                shipping_address_2:  $form.find('[name="shipping_address_2"]').val()  || $form.find('[name="billing_address_2"]').val()  || '',
                shipping_city:       $form.find('[name="shipping_city"]').val()       || $form.find('[name="billing_city"]').val()       || '',
                shipping_state:      $form.find('[name="shipping_state"]').val()      || $form.find('[name="billing_state"]').val()      || '',
                shipping_postcode:   $form.find('[name="shipping_postcode"]').val()   || $form.find('[name="billing_postcode"]').val()   || '',
                shipping_country:    $form.find('[name="shipping_country"]').val()    || $form.find('[name="billing_country"]').val()    || '',
            };
            $.post(ajaxUrl, billingData).always(function () {
                doCreatePendingOrderThenConfirm();
            });
        } else {
            doCreatePendingOrderThenConfirm();
        }

        // Re-enable button + hide overlay after timeout in case iframe never responds
        if (confirmTimeoutId) {
            clearTimeout(confirmTimeoutId);
        }
        confirmTimeoutId = setTimeout(function () {
            $btn.prop('disabled', false).css('opacity', '');
            isConfirming = false;
            hideOverlay();
        }, 30000);
    });

    // WooCommerce may submit checkout without a direct button click
    // (e.g. pressing Enter). Enforce OneShield confirm flow in that path too.
    $(document.body).on('checkout_place_order_os_stripe checkout_place_order_os_paypal', function () {
        var gateway = getActiveOspGateway();
        if (!gateway) return true;

        if (state[gateway] && state[gateway].confirmed) {
            return true;
        }

        if (!isConfirming) {
            $('#place_order').trigger('click');
        }

        return false;
    });

    // ── Hide loading overlay when iframe finishes loading ───────────────────

    function attachIframeLoadHandlers() {
        document.querySelectorAll('.osp-iframe-wrap iframe').forEach(function (iframe) {
            iframe.addEventListener('load', function () {
                var loadingId = 'osp-iframe-loading-' + iframe.id.replace('osp-iframe-', '');
                var el = document.getElementById(loadingId);
                if (el) el.style.display = 'none';
            });
        });
    }

    attachIframeLoadHandlers();

    $(document.body).on('updated_checkout', function () {
        attachIframeLoadHandlers();
        // Remove the protect listener if checkout rebuilds before submission completes
        $(document.body).off('updated_checkout.osp_protect');
        // Reset confirmed state when checkout rebuilds
        state.stripe.confirmed = false;
        state.stripe.txnId     = '';
        state.paypal.confirmed = false;
        state.paypal.txnId     = '';
        isConfirming = false;
        _isPaypalOverlayOpen = false;
        if (confirmTimeoutId) {
            clearTimeout(confirmTimeoutId);
            confirmTimeoutId = null;
        }
        hideOverlay();
        document.body.classList.remove('osp-paypal-overlay-active');
        setPayPalIframeFullscreen(false);
        cleanupLegacyPayPalOverlay();
        // Re-apply PayPal iframe toggle in case Place Order button re-appeared
        togglePayPalIframePosition();
    });

    // WooCommerce emits checkout_error on validation/server errors.
    $(document.body).on('checkout_error', function () {
        isConfirming = false;
        if (confirmTimeoutId) {
            clearTimeout(confirmTimeoutId);
            confirmTimeoutId = null;
        }
        hideOverlay();
        document.body.classList.remove('osp-paypal-overlay-active');
        setPayPalIframeFullscreen(false);
        $('#place_order').prop('disabled', false).css('opacity', '');
    });

    // ── Utilities ───────────────────────────────────────────────────────────

    function escAttr(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // ── setPayPalIframeFullscreen ─────────────────────────────────────────
    // Toggle osp-pp-fullscreen class on the iframe so the PayPal SDK overlay
    // inside it expands to cover the full money-site viewport (position:fixed).
    // Buttons are hidden by paypal.php onClick BEFORE this runs, so no flash.
    function setPayPalIframeFullscreen(open) {
        var iframe = document.getElementById('osp-iframe-paypal');
        if (!iframe) return;
        if (open) {
            iframe.classList.add('osp-pp-fullscreen');
            // Ensure iframe scrolls to top so PayPal overlay fills viewport correctly
            iframe.scrollIntoView({ block: 'start', behavior: 'instant' });
            window.scrollTo(0, 0);
        } else {
            iframe.classList.remove('osp-pp-fullscreen');
            if (_lastPaypalIframeHeight > 0) {
                iframe.style.height = _lastPaypalIframeHeight + 'px';
            }
        }
    }

})(jQuery);
