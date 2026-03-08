<?php

use App\Http\Controllers\Api\CheckoutSessionController;
use App\Http\Controllers\Api\ConnectController;
use App\Http\Controllers\Api\PaygatesController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\ApiCors;
use App\Http\Middleware\HmacAuthentication;
use App\Http\Middleware\ThrottlePerToken;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
|--------------------------------------------------------------------------
| API Routes — all routes get CORS headers
|--------------------------------------------------------------------------
*/

// OPTIONS preflight for all API routes
Route::options('{any}', fn () => response('', 204))->where('any', '.*')->middleware(ApiCors::class);

/*
|--------------------------------------------------------------------------
| Public endpoints (no auth)
|--------------------------------------------------------------------------
*/
Route::middleware(ApiCors::class)->group(function () {

    // Health check
    Route::get('/health', [WebhookController::class, 'health']);

    // Plugin version check
    Route::get('/plugins/version', function () {
        return response()->json([
            'connect'  => config('oneshield.plugin_versions.connect',  '1.0.0'),
            'paygates' => config('oneshield.plugin_versions.paygates', '1.0.0'),
        ]);
    });

    // Plugin download (optional — serve zip files from storage)
    Route::get('/plugins/download/{plugin}', function (string $plugin) {
        $allowed = ['connect', 'paygates'];
        if (!in_array($plugin, $allowed, true)) {
            abort(404);
        }

        $url = config("oneshield.plugin_downloads.{$plugin}");
        if (!$url) {
            return response()->json(['error' => 'Download not available'], 404);
        }

        // If it's a local storage path, stream the file
        if (str_starts_with($url, 'storage/')) {
            $path = str_replace('storage/', '', $url);
            if (!Storage::exists($path)) {
                return response()->json(['error' => 'File not found'], 404);
            }
            return Storage::download($path, "oneshield-{$plugin}.zip");
        }

        // Otherwise redirect to external URL
        return redirect($url);
    });

});

/*
|--------------------------------------------------------------------------
| Connect Plugin API (shield site -> gateway panel)
| Authentication: HMAC-SHA256 + per-token rate limiting
|--------------------------------------------------------------------------
*/
Route::prefix('connect')
    ->middleware([ApiCors::class, HmacAuthentication::class, ThrottlePerToken::class])
    ->group(function () {
        Route::post('register',          [ConnectController::class, 'register']);
        Route::post('heartbeat',         [ConnectController::class, 'heartbeat']);
        Route::get('status/{site_id}',   [ConnectController::class, 'status']);
        Route::post('billing',           [ConnectController::class, 'billing']);
    });

/*
|--------------------------------------------------------------------------
| Paygates Plugin API (money site → gateway panel)
| Authentication: HMAC-SHA256 + per-token rate limiting
|--------------------------------------------------------------------------
*/
Route::prefix('paygates')
    ->middleware([ApiCors::class, HmacAuthentication::class, ThrottlePerToken::class])
    ->group(function () {
        Route::get('status',           [PaygatesController::class, 'status']);
        Route::post('get-site',        [PaygatesController::class, 'getSite']);
        Route::post('confirm',         [PaygatesController::class, 'confirm']);
        Route::post('update-billing',  [PaygatesController::class, 'updateBilling']);
        Route::get('iframe-url',       [PaygatesController::class, 'iframeUrl']);
        Route::post('patch-order-id',  [PaygatesController::class, 'patchOrderId']);
        Route::post('refund',          [PaygatesController::class, 'refund']);
    });

/*
|--------------------------------------------------------------------------
| Checkout Sessions API (money site → gateway panel)
| Authentication: HMAC-SHA256 + per-token rate limiting
|--------------------------------------------------------------------------
*/
Route::prefix('checkout-sessions')
    ->middleware([ApiCors::class, HmacAuthentication::class, ThrottlePerToken::class])
    ->group(function () {
        // Create a new session (called by Paygates plugin / money site)
        Route::post('/',             [CheckoutSessionController::class, 'create']);

        // Resolve session context (called by Shield Site WP plugin)
        Route::get('/{id}',          [CheckoutSessionController::class, 'resolve']);

        // Refresh amount/currency before payment
        Route::post('/{id}/refresh', [CheckoutSessionController::class, 'refresh']);

        // Mark session completed (internal / webhook flow)
        Route::post('/{id}/complete', [CheckoutSessionController::class, 'complete']);
    });

/*
|--------------------------------------------------------------------------
| Webhook / IPN Handlers
| No HMAC (PayPal/Stripe call these directly)
| Rate limited by IP
|--------------------------------------------------------------------------
*/
Route::prefix('webhook')
    ->middleware(['throttle:200,1', ApiCors::class])
    ->group(function () {
        Route::post('paypal/{site_id}', [WebhookController::class, 'paypal']);
        Route::post('stripe/{site_id}', [WebhookController::class, 'stripe']);
    });
