<?php
/**
 * Blacklist integration for OneShield Paygates (money site).
 *
 * Fetches merged blacklist from Gateway Panel (customer + system entries).
 * Primary: wp_options pushed by heartbeat (Option C, zero latency).
 * Fallback: WP transient cache → live HTTP fetch (fail-open).
 *
 * Hooks: woocommerce_available_payment_gateways (hide/trap display),
 *        woocommerce_after_checkout_validation (hard block on submit).
 */

defined('ABSPATH') || exit;

// ── API helpers ───────────────────────────────────────────────────────────

/**
 * Get gateway_url and token_secret from WC Stripe gateway settings.
 * Both gateways share the same credentials.
 */
function osp_get_gateway_config(): array {
    $settings = get_option('woocommerce_oneshield_stripe_settings', []);
    return [
        'gateway_url'  => rtrim($settings['gateway_url']  ?? '', '/'),
        'token_secret' => $settings['token_secret'] ?? '',
    ];
}

/**
 * Build HMAC-signed headers for Gateway Panel API requests.
 */
function osp_build_headers(array $payload): array {
    $config     = osp_get_gateway_config();
    $timestamp  = time();
    $message    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $timestamp;
    $signature  = hash_hmac('sha256', $message, $config['token_secret']);

    return [
        'Content-Type'          => 'application/json',
        'X-OneShield-Signature' => $signature,
        'X-OneShield-Timestamp' => (string) $timestamp,
        'X-OneShield-Token'     => $config['token_secret'],
    ];
}

// ── Blacklist fetch ───────────────────────────────────────────────────────

/**
 * Fetch blacklist. Priority: wp_options (heartbeat push) → transient → HTTP.
 *
 * @return array{ emails: string[], cities: string[], states: string[], zipcodes: string[] }
 */
function osp_get_blacklist(): array {
    // Primary: wp_options pushed by heartbeat (Option C) — zero HTTP at checkout
    $stored = get_option('osp_blacklist_data', null);
    if ($stored !== null && is_array($stored)) {
        $pushedAt = $stored['_pushed_at'] ?? 0;
        if (time() - $pushedAt < DAY_IN_SECONDS) {
            unset($stored['_pushed_at']);
            return $stored;
        }
    }

    // Fallback: WP transient (fresh install before first heartbeat)
    $cached = get_transient('osp_blacklist');
    if ($cached !== false && is_array($cached)) {
        return $cached;
    }

    $empty = ['emails' => [], 'cities' => [], 'states' => [], 'zipcodes' => []];

    $config = osp_get_gateway_config();
    if (empty($config['gateway_url']) || empty($config['token_secret'])) {
        return $empty;
    }

    // Last resort: live HTTP fetch
    $response = wp_remote_get($config['gateway_url'] . '/api/blacklist', [
        'headers' => osp_build_headers([]),
        'timeout' => 2,
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return $empty;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) return $empty;

    $result = [
        'emails'   => array_map('strtolower', (array) ($data['emails']   ?? [])),
        'cities'   => array_map('strtolower', (array) ($data['cities']   ?? [])),
        'states'   => array_map('strtolower', (array) ($data['states']   ?? [])),
        'zipcodes' => array_map('strtolower', (array) ($data['zipcodes'] ?? [])),
    ];

    set_transient('osp_blacklist', $result, HOUR_IN_SECONDS);
    return $result;
}

function osp_normalize_field(string $v): string {
    return strtolower(trim($v));
}

/**
 * Check if the current WooCommerce buyer is blacklisted.
 * Reads from WC()->customer + multiple POST fallbacks for AJAX context.
 */
function osp_is_buyer_blacklisted(): bool {
    try {
        $email = $city = $state = $zipcode = '';

        if (function_exists('WC') && WC()->customer) {
            $email   = (string) WC()->customer->get_billing_email();
            $city    = (string) WC()->customer->get_billing_city();
            $state   = (string) WC()->customer->get_billing_state();
            $zipcode = (string) WC()->customer->get_billing_postcode();
        }

        // Fallback 1: direct POST (guest checkout)
        if (empty($email) && !empty($_POST['billing_email']))   $email   = sanitize_email($_POST['billing_email']);
        if (empty($city)  && !empty($_POST['billing_city']))    $city    = sanitize_text_field($_POST['billing_city']);
        if (empty($state) && !empty($_POST['billing_state']))   $state   = sanitize_text_field($_POST['billing_state']);
        if (empty($zipcode) && !empty($_POST['billing_postcode'])) $zipcode = sanitize_text_field($_POST['billing_postcode']);

        // Fallback 2: update_order_review AJAX short-form keys
        if (empty($zipcode) && !empty($_POST['postcode'])) $zipcode = sanitize_text_field($_POST['postcode']);
        if (empty($city)    && !empty($_POST['city']))     $city    = sanitize_text_field($_POST['city']);
        if (empty($state)   && !empty($_POST['state']))    $state   = sanitize_text_field($_POST['state']);

        // Fallback 3: serialized post_data from WC checkout JS
        if ((empty($zipcode) || empty($email)) && !empty($_POST['post_data'])) {
            $pd = [];
            parse_str(wp_unslash($_POST['post_data']), $pd);
            if (empty($email)   && !empty($pd['billing_email']))    $email   = sanitize_email($pd['billing_email']);
            if (empty($zipcode) && !empty($pd['billing_postcode'])) $zipcode = sanitize_text_field($pd['billing_postcode']);
            if (empty($city)    && !empty($pd['billing_city']))     $city    = sanitize_text_field($pd['billing_city']);
            if (empty($state)   && !empty($pd['billing_state']))    $state   = sanitize_text_field($pd['billing_state']);
        }

        $email   = osp_normalize_field($email);
        $city    = osp_normalize_field($city);
        $state   = osp_normalize_field($state);
        $zipcode = osp_normalize_field($zipcode);

        $list = osp_get_blacklist();

        return ($email   && in_array($email,   $list['emails'],   true))
            || ($city    && in_array($city,    $list['cities'],   true))
            || ($state   && in_array($state,   $list['states'],   true))
            || ($zipcode && in_array($zipcode, $list['zipcodes'], true));

    } catch (\Throwable $e) {
        return false; // fail open
    }
}

// ── Heartbeat sync (Option C push) ───────────────────────────────────────

/**
 * Run heartbeat to gateway panel and store blacklist in wp_options.
 * Called by WP cron and by the Force Sync admin action.
 */
function osp_sync_blacklist(): bool {
    $config = osp_get_gateway_config();
    if (empty($config['gateway_url']) || empty($config['token_secret'])) {
        return false;
    }

    $settings   = get_option('woocommerce_oneshield_stripe_settings', []);
    $site_id    = (int) ($settings['site_id'] ?? 0);

    if (!$site_id) return false;

    $payload  = ['site_id' => $site_id];
    $response = wp_remote_post($config['gateway_url'] . '/api/connect/heartbeat', [
        'headers' => osp_build_headers($payload),
        'body'    => json_encode($payload),
        'timeout' => 10,
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (($body['status'] ?? '') !== 'ok' || empty($body['blacklist'])) {
        return false;
    }

    $list = [
        'emails'     => array_map('strtolower', (array) ($body['blacklist']['emails']   ?? [])),
        'cities'     => array_map('strtolower', (array) ($body['blacklist']['cities']   ?? [])),
        'states'     => array_map('strtolower', (array) ($body['blacklist']['states']   ?? [])),
        'zipcodes'   => array_map('strtolower', (array) ($body['blacklist']['zipcodes'] ?? [])),
        '_pushed_at' => time(),
    ];
    update_option('osp_blacklist_data', $list, false); // autoload=no
    delete_transient('osp_blacklist');

    // Sync blacklist config (action + trap shield)
    $config = $body['config'] ?? [];
    if (array_key_exists('blacklist_action', $config)) {
        update_option('osp_blacklist_action', sanitize_text_field($config['blacklist_action'] ?? 'hide'));
    }
    if (array_key_exists('trap_shield_id', $config)) {
        $trap_id = $config['trap_shield_id'] ? absint($config['trap_shield_id']) : null;
        update_option('osp_trap_shield_id', $trap_id);
    }

    return true;
}

// WP cron hook
add_action('osp_blacklist_sync_cron', 'osp_sync_blacklist');

// ── Gateway filter (display layer) ───────────────────────────────────────

add_filter('woocommerce_available_payment_gateways', 'osp_blacklist_gateway_filter');

function osp_blacklist_gateway_filter(array $gateways): array {
    if (is_admin()) return $gateways;

    $in_checkout = (function_exists('is_checkout') && is_checkout())
                || defined('WOOCOMMERCE_CHECKOUT');
    if (!$in_checkout) return $gateways;

    $action = get_option('osp_blacklist_action', 'hide');

    if (!osp_is_buyer_blacklisted()) return $gateways;

    if ($action === 'hide') {
        return array_filter($gateways, fn($gw) => strpos($gw->id, 'oneshield') === false);
    }

    if ($action === 'trap') {
        $trap_id = get_option('osp_trap_shield_id');
        if ($trap_id) WC()->session->set('osp_trap_shield_id', (int) $trap_id);
    }

    return $gateways;
}

// ── Hard block at order submission ───────────────────────────────────────

add_action('woocommerce_after_checkout_validation', 'osp_blacklist_checkout_validation', 10, 2);

function osp_blacklist_checkout_validation(array $data, \WP_Error $errors): void {
    $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');
    if (strpos($payment_method, 'oneshield') === false) return;
    if (get_option('osp_blacklist_action', 'hide') !== 'hide') return;

    $list    = osp_get_blacklist();
    $email   = strtolower(trim(sanitize_email($data['billing_email']             ?? '')));
    $city    = strtolower(trim(sanitize_text_field($data['billing_city']         ?? '')));
    $state   = strtolower(trim(sanitize_text_field($data['billing_state']        ?? '')));
    $zipcode = strtolower(trim(sanitize_text_field($data['billing_postcode']     ?? '')));

    $blocked = ($email   && in_array($email,   $list['emails'],   true))
            || ($city    && in_array($city,    $list['cities'],   true))
            || ($state   && in_array($state,   $list['states'],   true))
            || ($zipcode && in_array($zipcode, $list['zipcodes'], true));

    if ($blocked) {
        $errors->add('osp_blacklisted', __('This payment method is not available for your billing address.', 'oneshield-paygates'));
    }
}

// ── Admin AJAX: Force Sync ────────────────────────────────────────────────

add_action('wp_ajax_osp_sync_blacklist', 'osp_ajax_sync_blacklist');
function osp_ajax_sync_blacklist(): void {
    check_ajax_referer('osp_sync_blacklist', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');

    delete_transient('osp_blacklist');
    delete_option('osp_blacklist_data');

    $ok = osp_sync_blacklist();
    wp_send_json_success([
        'synced'  => $ok,
        'message' => $ok ? 'Blacklist synced successfully.' : 'Could not reach Gateway Panel. Cache cleared — will sync on next heartbeat.',
    ]);
}
