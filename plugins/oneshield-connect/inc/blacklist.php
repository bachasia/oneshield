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
    // ── Primary: wp_options pushed by heartbeat (Option C) ────────────────
    // Heartbeat stores the merged blacklist (customer + system entries) here.
    // Advantage: zero HTTP overhead at checkout time.
    // Staleness guard: fall through if data hasn't been refreshed in 24h
    // (e.g., plugin disconnected or heartbeat failing).
    $stored = get_option('osc_blacklist_data', null);
    if ($stored !== null && is_array($stored)) {
        $pushedAt = $stored['_pushed_at'] ?? 0;
        if (time() - $pushedAt < DAY_IN_SECONDS) {
            unset($stored['_pushed_at']); // remove internal key before returning
            return $stored;
        }
    }

    // ── Fallback: WP transient (legacy / fresh install before first heartbeat) ──
    $cached = get_transient('osc_blacklist');
    if ($cached !== false && is_array($cached)) {
        return $cached;
    }

    $empty = ['emails' => [], 'cities' => [], 'states' => [], 'zipcodes' => []];

    if (!osc_is_connected()) {
        return $empty;
    }

    // ── Last resort: live HTTP fetch from /api/blacklist ──────────────────
    $response = wp_remote_get(osc_gateway_url() . '/api/blacklist', [
        'headers' => osc_build_headers([]),
        'timeout' => 2, // reduced from 5s — fail fast, fail open
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

        // Fallback 1: direct POST fields (guest checkout form submit)
        if (empty($email) && !empty($_POST['billing_email'])) {
            $email = sanitize_email($_POST['billing_email']);
        }
        if (empty($city) && !empty($_POST['billing_city'])) {
            $city    = sanitize_text_field($_POST['billing_city']);
            $state   = sanitize_text_field($_POST['billing_state']   ?? '');
            $zipcode = sanitize_text_field($_POST['billing_postcode'] ?? '');
        }

        // Fallback 2: update_order_review AJAX sends short-form keys (country/state/postcode/city)
        // when WC()->customer hasn't been fully populated yet
        if (empty($zipcode) && !empty($_POST['postcode'])) {
            $zipcode = sanitize_text_field($_POST['postcode']);
        }
        if (empty($city) && !empty($_POST['city'])) {
            $city = sanitize_text_field($_POST['city']);
        }
        if (empty($state) && !empty($_POST['state'])) {
            $state = sanitize_text_field($_POST['state']);
        }

        // Fallback 3: parse serialized post_data sent by WC checkout JS
        if ((empty($zipcode) || empty($email)) && !empty($_POST['post_data'])) {
            $post_data = [];
            parse_str(wp_unslash($_POST['post_data']), $post_data);
            if (empty($email) && !empty($post_data['billing_email'])) {
                $email = sanitize_email($post_data['billing_email']);
            }
            if (empty($zipcode) && !empty($post_data['billing_postcode'])) {
                $zipcode = sanitize_text_field($post_data['billing_postcode']);
            }
            if (empty($city) && !empty($post_data['billing_city'])) {
                $city = sanitize_text_field($post_data['billing_city']);
            }
            if (empty($state) && !empty($post_data['billing_state'])) {
                $state = sanitize_text_field($post_data['billing_state']);
            }
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
