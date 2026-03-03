<?php
/**
 * Core helper functions for OneShield Connect plugin.
 */

defined('ABSPATH') || exit;

/**
 * Get plugin option with default.
 */
function osc_get_option(string $key, $default = '') {
    return get_option('oneshield_connect_' . $key, $default);
}

/**
 * Update plugin option.
 */
function osc_update_option(string $key, $value): bool {
    return update_option('oneshield_connect_' . $key, $value);
}

/**
 * Get the Gateway Panel URL.
 */
function osc_gateway_url(): string {
    return rtrim(osc_get_option('gateway_url', ''), '/');
}

/**
 * Get this site's ID in the Gateway Panel.
 */
function osc_site_id(): string {
    return osc_get_option('site_id', '');
}

/**
 * Get this site's token secret (received from Gateway Panel).
 */
function osc_site_key(): string {
    return osc_get_option('site_key', '');
}

/**
 * Check if this site is connected to the Gateway Panel.
 */
function osc_is_connected(): bool {
    return !empty(osc_gateway_url()) && !empty(osc_site_id()) && !empty(osc_site_key());
}

/**
 * Sign a payload with HMAC-SHA256 (matching Gateway Panel signature).
 */
function osc_sign_request(array $payload, int $timestamp = 0): string {
    if ($timestamp === 0) {
        $timestamp = time();
    }
    $message = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $timestamp;
    return hash_hmac('sha256', $message, osc_site_key());
}

/**
 * Build signed request headers for Gateway Panel API calls.
 */
function osc_build_headers(array $payload): array {
    $timestamp = time();
    $signature = osc_sign_request($payload, $timestamp);

    return [
        'Content-Type'             => 'application/json',
        'X-OneShield-Signature'    => $signature,
        'X-OneShield-Timestamp'    => (string) $timestamp,
        'X-OneShield-Token'        => osc_site_key(),
    ];
}

/**
 * Log debug message (only when WP_DEBUG is on).
 */
function osc_log(string $message, array $context = []): void {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[OneShield Connect] ' . $message . (!empty($context) ? ' ' . json_encode($context) : ''));
    }
}
