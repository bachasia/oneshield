<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plugin Versions
    |--------------------------------------------------------------------------
    | Used by GET /api/plugins/version for auto-update checks.
    */
    'plugin_versions' => [
        'connect'  => env('ONESHIELD_CONNECT_VERSION',  '1.0.0'),
        'paygates' => env('ONESHIELD_PAYGATES_VERSION', '1.0.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plugin Download URLs
    |--------------------------------------------------------------------------
    | Served via GET /api/plugins/download/{plugin}
    */
    'plugin_downloads' => [
        'connect'  => env('ONESHIELD_CONNECT_DOWNLOAD_URL',  null),
        'paygates' => env('ONESHIELD_PAYGATES_DOWNLOAD_URL', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'api_per_token'   => env('ONESHIELD_RATE_LIMIT_API',     100),  // req/min per token
        'webhook_per_ip'  => env('ONESHIELD_RATE_LIMIT_WEBHOOK', 200),  // req/min per IP
    ],

    /*
    |--------------------------------------------------------------------------
    | HMAC Settings
    |--------------------------------------------------------------------------
    */
    'hmac' => [
        'max_age_seconds' => env('ONESHIELD_HMAC_MAX_AGE', 300), // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    */
    'circuit_breaker' => [
        'failure_threshold' => env('ONESHIELD_CB_THRESHOLD',    5),   // failures before disable
        'reset_after_min'   => env('ONESHIELD_CB_RESET_AFTER',  30),  // minutes before auto-reset
    ],

    /*
    |--------------------------------------------------------------------------
    | CORS — Allowed Origins for API routes
    |--------------------------------------------------------------------------
    | '*' = allow all (development only).
    | In production, list money site domains explicitly via ONESHIELD_CORS_ORIGINS
    | e.g. ONESHIELD_CORS_ORIGINS=https://store1.com,https://store2.com
    */
    'cors_origins' => array_filter(
        explode(',', env('ONESHIELD_CORS_ORIGINS', '*'))
    ),

];
