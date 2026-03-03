<?php
defined('ABSPATH') || exit;

// Enqueue frontend JavaScript for iframe + postMessage handling
add_action('wp_enqueue_scripts', 'osp_enqueue_scripts');
function osp_enqueue_scripts(): void {
    if (!is_checkout()) {
        return;
    }

    wp_enqueue_script(
        'oneshield-paygates-checkout',
        OSP_PLUGIN_URL . 'assets/js/checkout.js',
        ['jquery'],
        OSP_VERSION,
        true
    );

    wp_localize_script('oneshield-paygates-checkout', 'osp_data', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('osp_confirm_nonce'),
    ]);
}
