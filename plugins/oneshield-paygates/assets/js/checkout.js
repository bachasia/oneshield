/**
 * OneShield Paygates — Checkout JS
 *
 * New flow (iframe rendered at page load, not on Place Order):
 *  1. Iframe is already rendered inside payment_fields() when checkout loads
 *  2. Customer completes payment inside the iframe
 *  3. Shield Site sends postMessage: { source, status:'success', gateway, transaction_id }
 *  4. JS writes transaction_id into hidden input + shows success state
 *  5. Customer clicks Place Order → WC submits form normally
 *  6. process_payment() reads hidden input, confirms with gateway, completes order
 */

(function ($) {
    'use strict';

    // Hide the loading overlay once the iframe finishes loading
    $(document).on('load', '.osp-iframe-wrap iframe', function () {
        var gateway = $(this).attr('id').replace('osp-iframe-', '');
        $('#osp-iframe-loading-' + gateway).fadeOut(200);
    });

    // Also hide via iframe load event (jQuery 'on load' for iframes is unreliable)
    document.querySelectorAll('.osp-iframe-wrap iframe').forEach(function (iframe) {
        iframe.addEventListener('load', function () {
            var loadingId = 'osp-iframe-loading-' + iframe.id.replace('osp-iframe-', '');
            var el = document.getElementById(loadingId);
            if (el) el.style.display = 'none';
        });
    });

    // Re-attach after WC rebuilds the checkout (e.g. shipping method change)
    $(document.body).on('updated_checkout', function () {
        document.querySelectorAll('.osp-iframe-wrap iframe').forEach(function (iframe) {
            iframe.addEventListener('load', function () {
                var loadingId = 'osp-iframe-loading-' + iframe.id.replace('osp-iframe-', '');
                var el = document.getElementById(loadingId);
                if (el) el.style.display = 'none';
            });
        });
    });

    // ── Listen for postMessage from Shield Site iframe ──────────────────────

    window.addEventListener('message', function (event) {
        if (!event.data || event.data.source !== 'oneshield-connect') return;

        var msg = event.data;

        if (msg.status !== 'success' || !msg.transaction_id) return;

        var gateway = msg.gateway; // 'stripe' or 'paypal'
        var prefix  = 'osp_' + gateway;

        // Write transaction_id into the hidden input so process_payment() can read it
        var txnInput = document.getElementById(prefix + '_transaction_id');
        if (txnInput) {
            txnInput.value = msg.transaction_id;
        }

        // Show visual confirmation inside the iframe area
        var wrap = document.getElementById('osp-iframe-' + gateway);
        if (wrap) {
            var box = wrap.parentElement;
            // Replace iframe with a success message
            box.innerHTML =
                '<div style="padding:24px;text-align:center;border:1px solid #bbf7d0;border-radius:8px;background:#f0fdf4;">' +
                '<svg width="40" height="40" fill="none" viewBox="0 0 24 24" style="margin:0 auto 12px;display:block;">' +
                '<circle cx="12" cy="12" r="11" stroke="#16a34a" stroke-width="2"/>' +
                '<path stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M7 12l3.5 3.5L17 9"/>' +
                '</svg>' +
                '<p style="margin:0;font-size:15px;font-weight:600;color:#15803d;">Payment Authorised</p>' +
                '<p style="margin:6px 0 0;font-size:13px;color:#4ade80;">Click <strong>Place Order</strong> below to complete your purchase.</p>' +
                '</div>' +
                // Keep hidden inputs
                '<input type="hidden" name="' + prefix + '_transaction_id"    value="' + escAttr(msg.transaction_id) + '" />' +
                '<input type="hidden" name="' + prefix + '_os_transaction_id" value="' + escAttr(txnInput ? txnInput.form ? txnInput.form.querySelector('[name=' + prefix + '_os_transaction_id]')?.value || '' : '' : '') + '" />' +
                '<input type="hidden" name="' + prefix + '_os_site_id"        value="' + escAttr(document.querySelector('[name=' + prefix + '_os_site_id]')?.value || '') + '" />';
        }

        // Scroll to Place Order button
        var btn = document.getElementById('place_order');
        if (btn) {
            btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Briefly highlight the button
            btn.style.transition = 'box-shadow 0.3s';
            btn.style.boxShadow = '0 0 0 3px rgba(22,163,74,0.4)';
            setTimeout(function () { btn.style.boxShadow = ''; }, 2000);
        }
    });

    function escAttr(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

})(jQuery);
