<?php

use App\Http\Controllers\Api\ConnectController;
use App\Http\Controllers\Api\PaygatesController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\HmacAuthentication;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health check (no auth required)
Route::get('/health', [WebhookController::class, 'health']);

// Plugin version check (no auth required)
Route::get('/plugins/version', function () {
    return response()->json([
        'connect'  => config('oneshield.plugin_versions.connect', '1.0.0'),
        'paygates' => config('oneshield.plugin_versions.paygates', '1.0.0'),
    ]);
});

/*
|--------------------------------------------------------------------------
| Connect Plugin API (mesh site → gateway)
| Authentication: HMAC-SHA256
|--------------------------------------------------------------------------
*/
Route::prefix('connect')->middleware(HmacAuthentication::class)->group(function () {
    Route::post('register', [ConnectController::class, 'register']);
    Route::post('heartbeat', [ConnectController::class, 'heartbeat']);
    Route::get('status/{site_id}', [ConnectController::class, 'status']);
});

/*
|--------------------------------------------------------------------------
| Paygates Plugin API (money site → gateway)
| Authentication: HMAC-SHA256
|--------------------------------------------------------------------------
*/
Route::prefix('paygates')->middleware(HmacAuthentication::class)->group(function () {
    Route::post('get-site', [PaygatesController::class, 'getSite']);
    Route::post('confirm', [PaygatesController::class, 'confirm']);
    Route::get('iframe-url', [PaygatesController::class, 'iframeUrl']);
});

/*
|--------------------------------------------------------------------------
| Webhook / IPN Handlers
| No HMAC auth (PayPal/Stripe call these directly)
| Rate limiting applied
|--------------------------------------------------------------------------
*/
Route::prefix('webhook')->middleware('throttle:200,1')->group(function () {
    Route::post('paypal/{site_id}', [WebhookController::class, 'paypal']);
    Route::post('stripe/{site_id}', [WebhookController::class, 'stripe']);
});
