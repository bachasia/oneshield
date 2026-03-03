/**
 * OneShield Paygates - Checkout JavaScript
 * 
 * Handles:
 * 1. Rendering iframe after process_payment() returns success
 * 2. Listening for postMessage from Shield Site
 * 3. Confirming payment with Gateway Panel via AJAX
 * 4. Redirecting to thank-you page
 */

(function ($) {
    'use strict';

    let osIframeModal = null;
    let osCurrentGateway = null;
    let osTransactionId = null;
    let osOrderId = null;
    let osConfirmNonce = null;

    // Intercept WooCommerce checkout response
    $(document).on('checkout_place_order_success', function (e, response) {
        if (response && response.iframe_url) {
            e.preventDefault();
            showIframeModal(response);
        }
    });

    // Override default redirect for OS gateways
    $(document.body).on('checkout_place_order_success', function (e, response) {
        if (!response.iframe_url) return;
        showIframeModal(response);
        return false;
    });

    // Hook into WC AJAX checkout
    $(document).ajaxSuccess(function (event, xhr, settings) {
        if (settings.url && settings.url.indexOf('wc-ajax=checkout') === -1) return;
        try {
            const data = JSON.parse(xhr.responseText);
            if (data.result === 'success' && data.iframe_url) {
                showIframeModal(data);
            }
        } catch (e) {}
    });

    function showIframeModal(data) {
        // Gateway and order ID come directly from process_payment() response
        osCurrentGateway  = data.gateway;
        osTransactionId   = data.os_transaction_id;
        osOrderId         = data.wc_order_id;
        osConfirmNonce    = data.nonce;

        // Create modal overlay
        const modal = $('<div>', {
            id: 'os-payment-modal',
            css: {
                position: 'fixed',
                top: 0, left: 0, right: 0, bottom: 0,
                background: 'rgba(0,0,0,0.7)',
                zIndex: 99999,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
            },
        });

        const container = $('<div>', {
            css: {
                background: '#fff',
                borderRadius: '12px',
                padding: '0',
                width: '100%',
                maxWidth: '520px',
                maxHeight: '90vh',
                overflow: 'hidden',
                position: 'relative',
            },
        });

        const closeBtn = $('<button>', {
            text: '✕',
            css: {
                position: 'absolute',
                top: '10px', right: '12px',
                background: 'none',
                border: 'none',
                fontSize: '18px',
                cursor: 'pointer',
                color: '#666',
                zIndex: 1,
            },
        }).on('click', closeModal);

        const iframe = $('<iframe>', {
            src: data.iframe_url,
            css: {
                width: '100%',
                height: '550px',
                border: 'none',
            },
            allow: 'payment',
        });

        container.append(closeBtn, iframe);
        modal.append(container);
        $('body').append(modal);

        osIframeModal = modal;

        // Prevent background scroll
        $('body').css('overflow', 'hidden');
    }

    function closeModal() {
        if (osIframeModal) {
            osIframeModal.remove();
            osIframeModal = null;
            $('body').css('overflow', '');
        }
    }

    // Listen for postMessage from Shield Site iframe
    window.addEventListener('message', function (event) {
        if (!event.data || event.data.source !== 'oneshield-connect') {
            return;
        }

        const msg = event.data;

        if (msg.status === 'success') {
            closeModal();
            confirmPayment(msg);
        } else if (msg.status === 'failed') {
            closeModal();
            showError('Payment failed. Please try again.');
        }
    });

    function confirmPayment(msg) {
        $.ajax({
            url: osp_data.ajax_url,
            method: 'POST',
            data: {
                action: 'osp_confirm_payment',
                nonce: osConfirmNonce,
                wc_order_id: osOrderId,
                transaction_id: msg.transaction_id,
                gateway: osCurrentGateway,
                os_transaction_id: osTransactionId,
            },
            success: function (response) {
                if (response.success && response.data.redirect) {
                    window.location.href = response.data.redirect;
                } else {
                    showError(response.data || 'Confirmation failed.');
                }
            },
            error: function () {
                showError('Network error. Please contact support.');
            },
        });
    }

    function showError(message) {
        if ($('.woocommerce-notices-wrapper').length) {
            $('.woocommerce-notices-wrapper').html(
                '<ul class="woocommerce-error"><li>' + message + '</li></ul>'
            );
            $('html, body').animate({ scrollTop: $('.woocommerce-notices-wrapper').offset().top - 100 }, 400);
        } else {
            alert(message);
        }
    }


})(jQuery);
