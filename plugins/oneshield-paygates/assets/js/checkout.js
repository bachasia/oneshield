/**
 * OneShield Paygates - Checkout JavaScript
 *
 * Flow:
 *  1. Customer clicks "Place Order" → WC sends AJAX checkout request
 *  2. process_payment() returns { result:'success', redirect:'#osp-iframe', iframe_url, ... }
 *  3. We intercept BEFORE WC follows the redirect
 *  4. Show iframe modal with the Shield Site checkout page
 *  5. Shield Site posts back { source:'oneshield-connect', status:'success', transaction_id }
 *  6. We AJAX-confirm the order → redirect to thank-you page
 */

(function ($) {
    'use strict';

    let osIframeModal    = null;
    let osCurrentGateway = null;
    let osTransactionId  = null;
    let osWcOrderId      = null;
    let osConfirmNonce   = null;

    // ── Intercept WC checkout AJAX response ──────────────────────────────────
    //
    // WC classic checkout uses jQuery $.ajax internally and fires the
    // `checkout_place_order_success` event AND does window.location on redirect.
    // We need to catch the raw XHR before WC acts on it.

    // Method A: hook ajaxSuccess on the checkout endpoint
    $(document).ajaxSuccess(function (event, xhr, settings) {
        // Only care about WC checkout AJAX calls
        if (!settings.url) return;
        if (settings.url.indexOf('wc-ajax=checkout') === -1 &&
            settings.url.indexOf('action=woocommerce_checkout') === -1) return;

        let data;
        try { data = JSON.parse(xhr.responseText); } catch (e) { return; }

        if (data && data.result === 'success' && data.iframe_url) {
            // Stop WC from following redirect
            xhr.onreadystatechange = null;
            event.stopImmediatePropagation();
            showIframeModal(data);
        }
    });

    // Method B: override window.location assignment for '#osp-iframe' sentinel
    // (catches cases where WC directly sets location.href)
    (function () {
        const origAssign   = window.location.assign.bind(window.location);
        const origReplace  = window.location.replace.bind(window.location);

        // WC uses location.href = ... so we override via history API trick
        // by watching for hashchange to '#osp-iframe'
        window.addEventListener('hashchange', function () {
            if (window.location.hash === '#osp-iframe') {
                // Prevent actual navigation — we already showed the modal
                history.replaceState(null, '', window.location.pathname + window.location.search);
            }
        });
    })();

    // Method C: hook WC's own event (belt-and-suspenders)
    $(document.body).on('checkout_place_order_success', function (e, response) {
        if (response && response.iframe_url) {
            showIframeModal(response);
            return false;
        }
    });

    // ── Iframe modal ──────────────────────────────────────────────────────────

    function showIframeModal(data) {
        // Guard: don't open twice
        if ($('#os-payment-modal').length) return;

        osCurrentGateway = data.gateway;
        osTransactionId  = data.os_transaction_id;
        osWcOrderId      = data.wc_order_id;
        osConfirmNonce   = data.nonce;

        const modal = $('<div>', {
            id: 'os-payment-modal',
            css: {
                position: 'fixed',
                top: 0, left: 0, right: 0, bottom: 0,
                background: 'rgba(0,0,0,0.75)',
                zIndex: 999999,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
            },
        });

        const box = $('<div>', {
            css: {
                background: '#fff',
                borderRadius: '12px',
                width: '100%',
                maxWidth: '520px',
                maxHeight: '92vh',
                overflow: 'hidden',
                position: 'relative',
                boxShadow: '0 20px 60px rgba(0,0,0,0.3)',
            },
        });

        const closeBtn = $('<button>', {
            html: '&times;',
            title: 'Close',
            css: {
                position: 'absolute',
                top: '8px', right: '10px',
                background: 'rgba(0,0,0,0.15)',
                border: 'none',
                borderRadius: '50%',
                width: '28px', height: '28px',
                fontSize: '16px', lineHeight: '28px',
                cursor: 'pointer',
                color: '#333',
                zIndex: 1,
                padding: 0,
            },
        }).on('click', closeModal);

        const iframe = $('<iframe>', {
            src: data.iframe_url,
            css: { width: '100%', height: '560px', border: 'none', display: 'block' },
            attr: { allow: 'payment', scrolling: 'no' },
        });

        box.append(closeBtn, iframe);
        modal.append(box);
        $('body').append(modal);
        $('body').css('overflow', 'hidden');

        osIframeModal = modal;
    }

    function closeModal() {
        if (osIframeModal) {
            osIframeModal.remove();
            osIframeModal = null;
        }
        $('body').css('overflow', '');
    }

    // ── Listen for postMessage from Shield Site ───────────────────────────────

    window.addEventListener('message', function (event) {
        if (!event.data || event.data.source !== 'oneshield-connect') return;

        const msg = event.data;

        if (msg.status === 'success') {
            closeModal();
            confirmPayment(msg);
        } else if (msg.status === 'failed') {
            closeModal();
            showError('Payment failed. Please try again or choose a different payment method.');
        }
    });

    // ── Confirm order via AJAX ────────────────────────────────────────────────

    function confirmPayment(msg) {
        $.ajax({
            url: osp_data.ajax_url,
            method: 'POST',
            data: {
                action:           'osp_confirm_payment',
                nonce:            osConfirmNonce,
                wc_order_id:      osWcOrderId,
                transaction_id:   msg.transaction_id,
                gateway:          osCurrentGateway,
                os_transaction_id: osTransactionId,
            },
            success: function (response) {
                if (response.success && response.data && response.data.redirect) {
                    window.location.href = response.data.redirect;
                } else {
                    showError((response.data && response.data.message) || 'Confirmation failed. Please contact support.');
                }
            },
            error: function () {
                showError('Network error during confirmation. Please contact support.');
            },
        });
    }

    // ── Error display ─────────────────────────────────────────────────────────

    function showError(message) {
        const $notices = $('.woocommerce-notices-wrapper, .wc-block-components-notices, form.checkout');
        if ($notices.length) {
            $('<div class="woocommerce-error" style="margin-bottom:1em;">' + message + '</div>')
                .prependTo($notices.first());
            $('html, body').animate({ scrollTop: $notices.first().offset().top - 100 }, 300);
        } else {
            alert(message);
        }
    }

})(jQuery);
