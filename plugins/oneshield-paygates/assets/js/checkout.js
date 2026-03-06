/**
 * OneShield Paygates — Checkout JS
 *
 * Flow (billing sent at Place Order time, not at page load):
 *  1. Iframe is rendered inside payment_fields() when checkout loads.
 *     PaymentIntent is created but without billing — just amount/currency.
 *  2. Customer fills in card details inside the iframe (iframe is ready).
 *  3. Customer clicks Place Order:
 *     a. JS intercepts the click.
 *     b. If send_billing is enabled: AJAX osp_send_billing → gateway panel
 *        stores final billing on the pending transaction.
 *     c. postMessage 'oneshield-confirm-payment' + txn_id → iframe.
 *     d. Iframe fetches billing from gateway panel (server-side),
 *        attaches to confirmPayment(), processes Stripe payment.
 *     e. Stripe responds → iframe postMessage 'success' + transaction_id.
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
    });

    // ── Listen for payment success from iframe ──────────────────────────────

    window.addEventListener('message', function (event) {
        if (!event.data || event.data.source !== 'oneshield-connect') return;
        var msg = event.data;

        if (msg.status !== 'success' || !msg.transaction_id) return;

        var gateway = msg.gateway; // 'stripe' or 'paypal'
        if (!gateway || !state[gateway]) return;

        state[gateway].confirmed = true;
        state[gateway].txnId     = msg.transaction_id;

        var prefix = 'osp_' + gateway;

        // Write transaction_id into hidden inputs
        var txnInput   = document.querySelector('[name="' + prefix + '_transaction_id"]');
        var osTxnInput = document.querySelector('[name="' + prefix + '_os_transaction_id"]');
        var siteInput  = document.querySelector('[name="' + prefix + '_os_site_id"]');

        if (txnInput)   txnInput.value   = msg.transaction_id;

        // Show success UI inside the iframe wrap
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
                '<input type="hidden" name="' + prefix + '_os_transaction_id" value="' + escAttr(osTxnInput ? osTxnInput.value : '') + '" />' +
                '<input type="hidden" name="' + prefix + '_os_site_id"        value="' + escAttr(siteInput ? siteInput.value : '') + '" />';
        }

        // Re-submit the WC checkout form to complete the order
        var form = document.getElementById('order_review') || document.querySelector('form.checkout');
        if (form) {
            $(form).submit();
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

        var iframe    = getIframe(gateway);
        var osTxnId   = parseInt($('[name="osp_' + gateway + '_os_transaction_id"]').val() || '0', 10);
        var osSiteId  = parseInt($('[name="osp_' + gateway + '_os_site_id"]').val() || '0', 10);

        if (!iframe || !osTxnId) {
            // Let WooCommerce continue its native submit/validation flow.
            return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();
        isConfirming = true;

        var $btn = $(this);
        $btn.prop('disabled', true).css('opacity', '0.6');

        function doConfirm() {
            // postMessage to iframe: trigger Stripe/PayPal confirmation
            iframe.contentWindow.postMessage({
                action:  'oneshield-confirm-payment',
                txn_id:  osTxnId,
                site_id: osSiteId,
            }, '*');
        }

        // If send_billing enabled for this gateway, push billing first
        if (sendBillingGws.indexOf(gateway) !== -1 && osTxnId) {
            $.post(ajaxUrl, {
                action:    'osp_send_billing',
                gateway:   gateway,
                os_txn_id: osTxnId,
                nonce:     ospData.nonce || '',
            }).always(function () {
                // Always proceed even if billing update fails (non-fatal)
                doConfirm();
            });
        } else {
            doConfirm();
        }

        // Re-enable button after timeout in case iframe never responds
        setTimeout(function () {
            $btn.prop('disabled', false).css('opacity', '');
            isConfirming = false;
        }, 30000);
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
