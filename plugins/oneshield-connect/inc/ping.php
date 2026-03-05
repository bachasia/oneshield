<?php
/**
 * REST API: ping endpoint for Gateway Panel connectivity check.
 *
 * GET /wp-json/oneshield/v1/ping
 *
 * Returns a signed proof-of-connection so the Gateway Panel can verify:
 *  - the plugin is installed and active
 *  - the site_key on this site matches what the Panel has on record
 *
 * No authentication required on this endpoint — the panel verifies
 * authenticity via the site_key_hash it already knows.
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', 'osc_register_ping_route');

function osc_register_ping_route(): void {
    register_rest_route('oneshield/v1', '/ping', [
        'methods'             => \WP_REST_Server::READABLE,
        'callback'            => 'osc_ping_handler',
        'permission_callback' => '__return_true',
    ]);
}

function osc_ping_handler(\WP_REST_Request $request): \WP_REST_Response {
    $site_key = osc_site_key();   // stored after successful register
    $site_id  = osc_site_id();

    if (empty($site_key) || empty($site_id)) {
        return new \WP_REST_Response([
            'connected'  => false,
            'error'      => 'Plugin not connected to Gateway Panel.',
        ], 200);
    }

    // Send a SHA-256 HMAC of site_id using site_key as the signing secret.
    // The Gateway Panel can verify this without exposing the raw site_key.
    $proof = hash_hmac('sha256', (string) $site_id, $site_key);

    return new \WP_REST_Response([
        'connected'  => true,
        'site_id'    => (int) $site_id,
        'proof'      => $proof,
        'plugin_ver' => defined('OSC_VERSION') ? OSC_VERSION : 'unknown',
        'wp_url'     => get_site_url(),
    ], 200);
}
