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

    // ── Auto-resize iframe ──────────────────────────────────────────────────

    window.addEventListener('message', function (event) {
        if (!event.data || event.data.source !== 'oneshield-connect') return;
        var msg = event.data;

        if (msg.action === 'resize' && msg.height) {
            var gateway = getActiveOspGateway();
            if (!gateway) return;
            var f = getIframe(gateway);
            if (f) f.style.height = Math.max(msg.height, 50) + 'px';
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

        state[gateway].confirmed = true;
        state[gateway].txnId     = msg.transaction_id;

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
        // IMPORTANT: block WC's updated_checkout event so it cannot re-render
        // payment fields and wipe the hidden inputs we just wrote.
        var form = document.getElementById('order_review') || document.querySelector('form.checkout');
        if (form) {
            setTimeout(function () {
                // Detach WC's checkout update handler temporarily so a stray
                // updated_checkout event cannot replace the payment field HTML
                // (and wipe osp_stripe_transaction_id) while we are submitting.
                $(document.body).off('updated_checkout');

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

        function doConfirm() {
            // postMessage to iframe: trigger Stripe/PayPal confirmation
            iframe.contentWindow.postMessage({
                action:      'oneshield-confirm-payment',
                txn_id:      osTxnId,
                site_id:     osSiteId,
                checkout_id: osCheckoutId,
            }, '*');
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
                // Always proceed even if billing update fails (non-fatal)
                doConfirm();
            });
        } else {
            doConfirm();
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

})(jQuery);
