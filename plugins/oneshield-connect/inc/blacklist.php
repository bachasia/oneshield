<?php
/**
 * Blacklist integration for OneShield Connect.
 *
 * Fetches the buyer blacklist from the Gateway Panel API (cached 1h via WP transient),
 * checks if the current buyer matches, and exposes the result so gateway.php can
 * apply the configured action (hide gateways or set trap shield session).
 *
 * Fail-open policy: if the API is unreachable, buyers are NOT blocked.
 */

defined('ABSPATH') || exit;

/**
 * Fetch blacklist from Gateway Panel, cache for 1 hour.
 *
 * @return array{ emails: string[], addresses: string[] }
 */
function osc_get_blacklist(): array {
    $cached = get_transient('osc_blacklist');
    if ($cached !== false && is_array($cached)) {
        return $cached;
    }

    if (!osc_is_connected()) {
        return ['emails' => [], 'addresses' => []];
    }

    $response = wp_remote_get(osc_gateway_url() . '/api/blacklist', [
        'headers' => osc_build_headers([]),
        'timeout' => 5,
    ]);

    if (is_wp_error($response)) {
        osc_log('Blacklist fetch failed: ' . $response->get_error_message());
        return ['emails' => [], 'addresses' => []];
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        osc_log('Blacklist fetch returned HTTP ' . $code);
        return ['emails' => [], 'addresses' => []];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($data)) {
        return ['emails' => [], 'addresses' => []];
    }

    // Normalise structure
    $result = [
        'emails'    => array_map('strtolower', (array) ($data['emails']    ?? [])),
        'addresses' => (array) ($data['addresses'] ?? []),
    ];

    set_transient('osc_blacklist', $result, HOUR_IN_SECONDS);

    return $result;
}

/**
 * Normalize an address string for consistent matching.
 * Mirrors the server-side BlacklistService::normalizeAddress() logic.
 */
function osc_normalize_address(string $addr): string {
    $addr = strtolower(trim($addr));
    $addr = preg_replace('/[^\w\s]/', '', $addr);   // remove punctuation
    $addr = preg_replace('/\s+/', ' ', $addr);       // collapse whitespace

    $replacements = [
        'street'    => 'st',
        'avenue'    => 'ave',
        'boulevard' => 'blvd',
        'drive'     => 'dr',
        'lane'      => 'ln',
        'road'      => 'rd',
        'court'     => 'ct',
        'place'     => 'pl',
    ];

    foreach ($replacements as $full => $abbr) {
        $addr = preg_replace('/\b' . $full . '\b/', $abbr, $addr);
    }

    return trim($addr);
}

/**
 * Determine whether the current WooCommerce buyer is blacklisted.
 *
 * Checks billing email (exact, case-insensitive) and billing address
 * (normalized). Falls back to $_POST fields for guest checkouts where
 * WC()->customer may not be fully populated yet.
 *
 * @return bool  true if blacklisted, false otherwise (including on any error)
 */
function osc_is_buyer_blacklisted(): bool {
    try {
        // Prefer WC customer object; fall back to POST for early hooks
        $email = '';
        $addr1 = '';
        $city  = '';

        if (WC()->customer) {
            $email = (string) WC()->customer->get_billing_email();
            $addr1 = (string) WC()->customer->get_billing_address_1();
            $city  = (string) WC()->customer->get_billing_city();
        }

        // Guest/early-hook fallback
        if (empty($email) && !empty($_POST['billing_email'])) {
            $email = sanitize_email($_POST['billing_email']);
        }
        if (empty($addr1) && !empty($_POST['billing_address_1'])) {
            $addr1 = sanitize_text_field($_POST['billing_address_1']);
            $city  = sanitize_text_field($_POST['billing_city'] ?? '');
        }

        $email = strtolower(trim($email));
        $addr  = osc_normalize_address(trim($addr1 . ' ' . $city));

        $list = osc_get_blacklist();

        // Email: exact match
        if ($email && in_array($email, $list['emails'], true)) {
            return true;
        }

        // Address: normalized exact match
        if ($addr) {
            foreach ($list['addresses'] as $blocked) {
                if (osc_normalize_address($blocked) === $addr) {
                    return true;
                }
            }
        }
    } catch (\Throwable $e) {
        osc_log('Blacklist check error: ' . $e->getMessage());
        // Fail open — do not block buyer on error
    }

    return false;
}
