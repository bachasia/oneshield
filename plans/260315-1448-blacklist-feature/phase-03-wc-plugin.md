# Phase 3: WooCommerce Plugin — Checkout Hook + Blacklist Check

## Overview
- Priority: High
- Status: complete
- Depends on: Phase 1 complete (API endpoint live)
- Goal: WC plugin checks buyer info against blacklist at checkout, applies hide/trap action

## Related Code Files

**Create:**
- `plugins/oneshield-connect/inc/blacklist.php` — blacklist fetch, cache, check logic

**Modify:**
- `plugins/oneshield-connect/inc/gateway.php` — hook into WC payment gateway filter
- `plugins/oneshield-connect/inc/settings.php` — store blacklist_action + trap_shield_id from shield config
- `plugins/oneshield-connect/oneshield-connect.php` — require blacklist.php

## Implementation Steps

### 1. `inc/blacklist.php` — Core blacklist logic

```php
// Fetch blacklist from OneShieldX API, cache 1 hour via WP transient
function osc_get_blacklist(): array {
    $cached = get_transient('osc_blacklist');
    if ($cached !== false) return $cached;

    $response = wp_remote_get(OSC_API_URL . '/api/blacklist', [
        'headers' => osc_hmac_headers(), // existing HMAC auth helper
        'timeout' => 5,
    ]);

    if (is_wp_error($response)) return ['emails' => [], 'addresses' => []];

    $data = json_decode(wp_remote_retrieve_body($response), true);
    set_transient('osc_blacklist', $data, HOUR_IN_SECONDS);
    return $data;
}

// Check if current buyer is blacklisted
// Returns bool
function osc_is_buyer_blacklisted(): bool {
    $email   = WC()->customer->get_billing_email();
    $address = osc_normalize_address(
        WC()->customer->get_billing_address_1() . ' ' .
        WC()->customer->get_billing_city()
    );

    $list = osc_get_blacklist();

    // Email: exact match
    if (in_array(strtolower(trim($email)), array_map('strtolower', $list['emails'] ?? []))) {
        return true;
    }

    // Address: normalized exact match
    foreach ($list['addresses'] ?? [] as $blocked) {
        if (osc_normalize_address($blocked) === $address) return true;
    }

    return false;
}

// Normalize address for comparison
function osc_normalize_address(string $addr): string {
    $addr = strtolower(trim($addr));
    $addr = preg_replace('/[^\w\s]/', '', $addr);   // remove punctuation
    $addr = preg_replace('/\s+/', ' ', $addr);       // collapse spaces
    // common abbreviations
    $addr = str_replace(['street', 'avenue', 'boulevard', 'drive', 'lane', 'road'],
                        ['st', 'ave', 'blvd', 'dr', 'ln', 'rd'], $addr);
    return $addr;
}
```

### 2. Hook: `woocommerce_available_payment_gateways`

In `inc/gateway.php`, add filter:

```php
add_filter('woocommerce_available_payment_gateways', 'osc_blacklist_gateway_filter');

function osc_blacklist_gateway_filter(array $gateways): array {
    if (is_admin() || !is_checkout()) return $gateways;

    $action = get_option('osc_blacklist_action', 'hide'); // 'hide' | 'trap'

    if (!osc_is_buyer_blacklisted()) return $gateways;

    if ($action === 'hide') {
        // Remove all OneShield gateways
        return array_filter($gateways, function($gw) {
            return strpos($gw->id, 'oneshield') === false;
        });
    }

    if ($action === 'trap') {
        // Set trap shield in session — picked up when sending to backend
        WC()->session->set('osc_trap_shield_id', get_option('osc_trap_shield_id'));
    }

    return $gateways;
}
```

### 3. Trap shield: pass to backend

In `checkout/stripe.php` and `checkout/paypal.php`, when building the payload to OneShieldX:

```php
$trap_shield_id = WC()->session->get('osc_trap_shield_id');
if ($trap_shield_id) {
    $payload['shield_id'] = $trap_shield_id; // override normal shield selection
    WC()->session->__unset('osc_trap_shield_id');
}
```

### 4. Settings sync

When WC plugin fetches shield config from backend (existing heartbeat/settings sync), also store:
```php
update_option('osc_blacklist_action', $config['blacklist_action'] ?? 'hide');
update_option('osc_trap_shield_id', $config['trap_shield_id'] ?? null);
```

This happens in `inc/settings.php` or `inc/heartbeat.php` (wherever shield config is cached locally).

## Todo
- [x] Create `inc/blacklist.php` with `osc_get_blacklist()`, `osc_is_buyer_blacklisted()`, `osc_normalize_address()`
- [x] Add `require_once` for `blacklist.php` in main plugin file
- [x] Add `woocommerce_available_payment_gateways` filter in `gateway.php`
- [x] Update `checkout/stripe.php` to inject `trap_shield_id` if session set
- [x] Update `checkout/paypal.php` to inject `trap_shield_id` if session set
- [x] Update settings sync to store `blacklist_action` + `trap_shield_id` as WP options
- [x] Test: blacklisted email → gateways hidden
- [x] Test: blacklisted email + trap → routes to trap shield
- [x] Test: non-blacklisted buyer → no change

## Implementation Notes
- plugins/oneshield-connect/inc/blacklist.php created with full implementation
- require_once added to main plugin file (oneshield-connect.php)
- woocommerce_available_payment_gateways filter added in gateway.php
- checkout/paypal.php and checkout/stripe.php inject trap_shield_id via class-os-payment-base.php in oneshield-paygates
- Settings sync in remote.php: heartbeat stores blacklist_action + trap_shield_id from user-level config

## Edge Cases
- Buyer not logged in / guest: use billing form fields from `$_POST` if `WC()->customer` not populated yet — hook may fire before customer object is set. Use `woocommerce_after_checkout_validation` as backup if needed.
- Empty blacklist response (API down): fail open — do not block buyer. Log warning.
- Trap shield same as active shield: backend should prevent this via validation (Phase 2)

## Success Criteria
- Blacklisted email → payment section hidden (action=hide)
- Blacklisted email → order routes to trap shield (action=trap)
- Non-blacklisted buyer → normal checkout flow unchanged
- API timeout → buyer not blocked (fail open)
