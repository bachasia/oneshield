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

    // ── Inject PayPal fullscreen CSS immediately ────────────────────────────
    (function () {
        var s = document.createElement('style');
        s.textContent = '.osp-pp-fullscreen{position:fixed!important;z-index:99999!important;top:0!important;left:0!important;width:100%!important;height:100vh!important;}#osp-pp-fs,#osp-pp-fs-style{display:none!important;}';
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

    // ── Toggle Place Order button and PayPal iframe wrap ──────────────────
    // #osp-paypal-button-wrap is rendered outside the payment box by PHP
    // (woocommerce_review_order_after_submit hook) so WC updated_checkout
    // never destroys it. We simply show/hide it here.

    function togglePayPalIframePosition() {
        var gateway     = getActiveOspGateway();
        var $placeOrder = $('#place_order');
        var $ppWrap     = $('#osp-paypal-button-wrap');

        if (gateway === 'paypal') {
            // Only hide Place Order if the PayPal iframe wrap actually exists in DOM.
            if ($ppWrap.length) {
                $placeOrder.hide();
                $ppWrap.show();
            } else {
                // Wrap not rendered (API error) — fall back to Place Order button.
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
    setInterval(togglePayPalIframePosition, 200);

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

        // PayPal overlay detected inside iframe — make iframe fullscreen.
        if (msg.action === 'paypal_overlay_open') {
            cleanupLegacyPayPalOverlay();
            setPayPalIframeFullscreen(true);
        }

        // PayPal overlay gone — restore iframe to normal.
        if (msg.action === 'paypal_overlay_close') {
            cleanupLegacyPayPalOverlay();
            setPayPalIframeFullscreen(false);
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

        // Write transaction_id into ALL matching hidden inputs
        document.querySelectorAll('[name="' + prefix + '_transaction_id"]').forEach(function(el) {
            el.value = msg.transaction_id;
        });

        // Inject a fresh input directly into the form so WC AJAX
        // serialize always picks it up, regardless of any re-renders.
        var form = document.getElementById('order_review') || document.querySelector('form.checkout');
        if (form) {
            var existing = form.querySelector('#osp_txn_injected_' + gateway);
            if (existing) existing.remove();
            var injected = document.createElement('input');
            injected.type  = 'hidden';
            injected.id    = 'osp_txn_injected_' + gateway;
            injected.name  = prefix + '_transaction_id';
            injected.value = msg.transaction_id;
            form.appendChild(injected);

            // Also inject paypal_order_id and draft_order_id for use in process_payment
            if (gateway === 'paypal') {
                if (msg.paypal_order_id) {
                    var existingPpOid = form.querySelector('#osp_paypal_order_id_injected');
                    if (existingPpOid) existingPpOid.remove();
                    var ppOid = document.createElement('input');
                    ppOid.type  = 'hidden';
                    ppOid.id    = 'osp_paypal_order_id_injected';
                    ppOid.name  = 'osp_paypal_paypal_order_id';
                    ppOid.value = msg.paypal_order_id;
                    form.appendChild(ppOid);
                }
                if (msg.draft_order_id) {
                    var existingDraft = form.querySelector('#osp_paypal_draft_order_injected');
                    if (existingDraft) existingDraft.remove();
                    var draftInp = document.createElement('input');
                    draftInp.type  = 'hidden';
                    draftInp.id    = 'osp_paypal_draft_order_injected';
                    draftInp.name  = 'osp_paypal_draft_order_id';
                    draftInp.value = msg.draft_order_id;
                    form.appendChild(draftInp);
                }
            }
        }

        var osTxnInput      = document.querySelector('[name="' + prefix + '_os_transaction_id"]');
        var siteInput       = document.querySelector('[name="' + prefix + '_os_site_id"]');
        var checkoutIdInput = document.querySelector('[name="' + prefix + '_os_checkout_id"]');

        // Show success overlay before re-submitting
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
                '</div>' +
                '<input type="hidden" name="' + prefix + '_transaction_id"    value="' + escAttr(msg.transaction_id) + '" />' +
                '<input type="hidden" name="' + prefix + '_os_transaction_id" value="' + escAttr(osTxnInput      ? osTxnInput.value      : '') + '" />' +
                '<input type="hidden" name="' + prefix + '_os_site_id"        value="' + escAttr(siteInput        ? siteInput.value        : '') + '" />' +
                '<input type="hidden" name="' + prefix + '_os_checkout_id"    value="' + escAttr(checkoutIdInput  ? checkoutIdInput.value  : '') + '" />';
        }

        // Re-submit the WC checkout form to complete the order.
        // Use a very short delay (100ms) just to let the success UI paint.
        var form = document.getElementById('order_review') || document.querySelector('form.checkout');
        if (form) {
            // If WC fires updated_checkout after we confirmed (e.g. coupon recalc),
            // re-inject the transaction_id so it survives the DOM refresh.
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
                // Submit via WC's own AJAX checkout by triggering the form.
                // state[gateway].confirmed = true ensures our click-intercept
                // handler lets this through without blocking it again.
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

        // For PayPal: create the real WC pending order first, then open PayPal popup
        function doCreatePendingOrderThenConfirm(billingData) {
            if (gateway !== 'paypal' || !osCheckoutId) {
                doConfirm();
                return;
            }
            var $form = $('form.checkout, form#order_review');
            var pendingData = $.extend({
                action:               'osp_create_paypal_pending_order',
                nonce:                ospData.nonce || '',
                checkout_session_id:  osCheckoutId,
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
            }, billingData || {});

            $.post(ajaxUrl, pendingData, function(resp) {
                if (resp && resp.success) {
                    wcOrderId = resp.data.wc_order_id || 0;
                    invoiceId = resp.data.invoice_id  || '';
                    // Inject wc_order_id as hidden input so process_payment receives it
                    var f = document.getElementById('order_review') || document.querySelector('form.checkout');
                    if (f) {
                        var ex = f.querySelector('#osp_paypal_wc_order_injected');
                        if (ex) ex.remove();
                        var inp = document.createElement('input');
                        inp.type  = 'hidden';
                        inp.id    = 'osp_paypal_wc_order_injected';
                        inp.name  = 'osp_paypal_pending_wc_order_id';
                        inp.value = wcOrderId;
                        f.appendChild(inp);
                    }
                }
                doConfirm();
            }).fail(function() {
                doConfirm(); // Fall back — invoice_id will be checkout-uuid
            });
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
        if (confirmTimeoutId) {
            clearTimeout(confirmTimeoutId);
            confirmTimeoutId = null;
        }
        hideOverlay();
        cleanupLegacyPayPalOverlay();
        // Always restore PayPal iframe to normal size
        setPayPalIframeFullscreen(false);
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

    // ── PayPal fullscreen iframe (competitor-style) ─────────────────────────

    var _ppAncestorRestore = [];

    function relaxPayPalIframeAncestors(iframe) {
        if (_ppAncestorRestore.length) return;

        var node = iframe.parentElement;
        while (node && node !== document.documentElement) {
            _ppAncestorRestore.push({
                el: node,
                overflow: node.style.getPropertyValue('overflow'),
                overflowPriority: node.style.getPropertyPriority('overflow'),
                overflowX: node.style.getPropertyValue('overflow-x'),
                overflowXPriority: node.style.getPropertyPriority('overflow-x'),
                overflowY: node.style.getPropertyValue('overflow-y'),
                overflowYPriority: node.style.getPropertyPriority('overflow-y'),
                transform: node.style.getPropertyValue('transform'),
                transformPriority: node.style.getPropertyPriority('transform'),
                contain: node.style.getPropertyValue('contain'),
                containPriority: node.style.getPropertyPriority('contain')
            });

            node.style.setProperty('overflow', 'visible', 'important');
            node.style.setProperty('overflow-x', 'visible', 'important');
            node.style.setProperty('overflow-y', 'visible', 'important');
            node.style.setProperty('transform', 'none', 'important');
            node.style.setProperty('contain', 'none', 'important');

            node = node.parentElement;
        }
    }

    function restorePayPalIframeAncestors() {
        if (!_ppAncestorRestore.length) return;

        _ppAncestorRestore.forEach(function (item) {
            if (!item.el) return;

            if (item.overflow) item.el.style.setProperty('overflow', item.overflow, item.overflowPriority || '');
            else item.el.style.removeProperty('overflow');

            if (item.overflowX) item.el.style.setProperty('overflow-x', item.overflowX, item.overflowXPriority || '');
            else item.el.style.removeProperty('overflow-x');

            if (item.overflowY) item.el.style.setProperty('overflow-y', item.overflowY, item.overflowYPriority || '');
            else item.el.style.removeProperty('overflow-y');

            if (item.transform) item.el.style.setProperty('transform', item.transform, item.transformPriority || '');
            else item.el.style.removeProperty('transform');

            if (item.contain) item.el.style.setProperty('contain', item.contain, item.containPriority || '');
            else item.el.style.removeProperty('contain');
        });

        _ppAncestorRestore = [];
    }

    function setPayPalIframeFullscreen(open) {
        var iframe = document.getElementById('osp-iframe-paypal');
        if (!iframe) return;
        if (open) {
            relaxPayPalIframeAncestors(iframe);
            iframe.classList.add('osp-pp-fullscreen');
        } else {
            iframe.classList.remove('osp-pp-fullscreen');
            restorePayPalIframeAncestors();
        }
    }

})(jQuery);
