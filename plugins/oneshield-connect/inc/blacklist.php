<?php
/**
 * Blacklist integration for OneShield Connect.
 *
 * Fetches the buyer blacklist from the Gateway Panel API (cached 1h via WP transient),
 * checks if the current buyer matches any blacklisted email, city, state, or zipcode.
 *
 * Fail-open policy: if the API is unreachable, buyers are NOT blocked.
 */

defined('ABSPATH') || exit;

/**
 * Fetch blacklist from Gateway Panel, cache for 1 hour.
 *
 * @return array{ emails: string[], cities: string[], states: string[], zipcodes: string[] }
 */
function osc_get_blacklist(): array {
    $cached = get_transient('osc_blacklist');
    if ($cached !== false && is_array($cached)) {
        return $cached;
    }

    $empty = ['emails' => [], 'cities' => [], 'states' => [], 'zipcodes' => []];

    if (!osc_is_connected()) {
        return $empty;
    }

    $response = wp_remote_get(osc_gateway_url() . '/api/blacklist', [
        'headers' => osc_build_headers([]),
        'timeout' => 5,
    ]);

    if (is_wp_error($response)) {
        osc_log('Blacklist fetch failed: ' . $response->get_error_message());
        return $empty;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        osc_log('Blacklist fetch returned HTTP ' . $code);
        return $empty;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($data)) {
        return $empty;
    }

    $result = [
        'emails'   => array_map('strtolower', (array) ($data['emails']   ?? [])),
        'cities'   => array_map('strtolower', (array) ($data['cities']   ?? [])),
        'states'   => array_map('strtolower', (array) ($data['states']   ?? [])),
        'zipcodes' => array_map('strtolower', (array) ($data['zipcodes'] ?? [])),
    ];

    set_transient('osc_blacklist', $result, HOUR_IN_SECONDS);

    return $result;
}

/**
 * Normalize a single field value: lowercase + trim.
 */
function osc_normalize_field(string $v): string {
    return strtolower(trim($v));
}

/**
 * Determine whether the current WooCommerce buyer is blacklisted.
 *
 * Checks billing email, city, state, and zipcode independently.
 * Falls back to $_POST fields for guest checkouts where WC()->customer
 * may not be fully populated yet.
 *
 * @return bool  true if blacklisted, false otherwise (including on any error)
 */
function osc_is_buyer_blacklisted(): bool {
    try {
        $email   = '';
        $city    = '';
        $state   = '';
        $zipcode = '';

        if (WC()->customer) {
            $email   = (string) WC()->customer->get_billing_email();
            $city    = (string) WC()->customer->get_billing_city();
            $state   = (string) WC()->customer->get_billing_state();
            $zipcode = (string) WC()->customer->get_billing_postcode();
        }

        // Guest/early-hook fallback
        if (empty($email) && !empty($_POST['billing_email'])) {
            $email = sanitize_email($_POST['billing_email']);
        }
        if (empty($city) && !empty($_POST['billing_city'])) {
            $city    = sanitize_text_field($_POST['billing_city']);
            $state   = sanitize_text_field($_POST['billing_state']   ?? '');
            $zipcode = sanitize_text_field($_POST['billing_postcode'] ?? '');
        }

        $email   = osc_normalize_field($email);
        $city    = osc_normalize_field($city);
        $state   = osc_normalize_field($state);
        $zipcode = osc_normalize_field($zipcode);

        $list = osc_get_blacklist();

        if ($email && in_array($email, $list['emails'], true)) {
            return true;
        }
        if ($city && in_array($city, $list['cities'], true)) {
            return true;
        }
        if ($state && in_array($state, $list['states'], true)) {
            return true;
        }
        if ($zipcode && in_array($zipcode, $list['zipcodes'], true)) {
            return true;
        }

    } catch (\Throwable $e) {
        osc_log('Blacklist check error: ' . $e->getMessage());
        // Fail open — do not block buyer on error
    }

    return false;
}
