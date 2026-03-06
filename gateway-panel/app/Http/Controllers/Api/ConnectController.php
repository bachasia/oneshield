<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckoutSession;
use App\Models\ShieldSite;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectController extends Controller
{
    /**
     * Register a new shield site.
     * POST /api/connect/register
     *
     * Called by the oneshield-connect plugin on first setup.
     */
    public function register(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'site_id'       => 'required|integer',
            'authorize_key' => 'required|string|size:64',
            'site_url'      => 'required|url|max:500',
            'site_name'     => 'required|string|max:255',
        ]);

        $site = ShieldSite::where('id', $validated['site_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        if (!hash_equals($site->site_key, $validated['authorize_key'])) {
            return response()->json(['error' => 'Invalid authorize key'], 403);
        }

        $site->update([
            'name'              => $validated['site_name'],
            'url'               => rtrim($validated['site_url'], '/'),
            'is_active'         => true,
            'last_heartbeat_at' => now(),
        ]);

        return response()->json([
            'site_id'  => $site->id,
            'site_key' => $site->site_key,
            'status'   => 'connected',
        ]);
    }

    /**
     * Heartbeat ping from shield site.
     * POST /api/connect/heartbeat
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'site_id' => 'required|integer',
        ]);

        $site = ShieldSite::where('id', $validated['site_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        $site->update(['last_heartbeat_at' => now()]);

        // Build credentials payload — only include keys when gateway is enabled & configured
        $credentials = [];

        if ($site->stripe_enabled && $site->hasGatewayCredentials('stripe')) {
            $credentials['stripe'] = [
                'public_key'  => $site->stripe_public_key,
                'secret_key'  => $site->stripe_secret_key,
                'mode'        => $site->stripe_mode,
            ];
        }

        if ($site->paypal_enabled && $site->hasGatewayCredentials('paypal')) {
            $credentials['paypal'] = [
                'client_id'     => $site->paypal_client_id,
                'client_secret' => $site->paypal_secret,
                'mode'          => $site->paypal_mode,
            ];
        }

        return response()->json([
            'status' => 'ok',
            'config' => [
                'paypal_mode'  => $site->paypal_mode,
                'stripe_mode'  => $site->stripe_mode,
                'is_active'    => $site->is_active,
            ],
            'credentials' => $credentials,
        ]);
    }

    /**
     * Get billing details for a transaction or checkout session.
     * POST /api/connect/billing
     *
     * Called by shield site AJAX when creating PaymentIntent.
     * Supports both legacy (transaction_id) and checkout_id mode.
     */
    public function billing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transaction_id' => 'nullable|integer',
            'site_id'        => 'required|integer',
            'checkout_id'    => 'nullable|string',
        ]);

        // checkout_id mode: fetch billing from checkout_session
        if (!empty($validated['checkout_id'])) {
            $session = CheckoutSession::where('id', $validated['checkout_id'])
                ->where('site_id', $validated['site_id'])
                ->first();

            if (!$session) {
                return response()->json(['billing' => null]);
            }

            return response()->json(['billing' => $session->billing_snapshot]);
        }

        // Legacy mode: fetch billing from transaction
        $transaction = Transaction::where('id', $validated['transaction_id'])
            ->where('site_id', $validated['site_id'])
            ->first();

        if (!$transaction) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        if (empty($transaction->billing_data)) {
            return response()->json(['billing' => null]);
        }

        return response()->json(['billing' => $transaction->billing_data]);
    }

    /**
     * Get site configuration.
     * GET /api/connect/status/{site_id}
     */
    public function status(Request $request, int $siteId): JsonResponse
    {
        $user = $request->user();

        $site = ShieldSite::where('id', $siteId)
            ->where('user_id', $user->id)
            ->with('group')
            ->firstOrFail();

        return response()->json([
            'site' => [
                'id'        => $site->id,
                'name'      => $site->name,
                'url'       => $site->url,
                'is_active' => $site->is_active,
                'group'     => $site->group ? ['id' => $site->group->id, 'name' => $site->group->name] : null,
            ],
            'gateways_configured' => [
                'paypal'  => $site->supportsGateway('paypal'),
                'stripe'  => $site->supportsGateway('stripe'),
                'airwallex' => $site->supportsGateway('airwallex'),
            ],
            'modes' => [
                'paypal' => $site->paypal_mode,
                'stripe' => $site->stripe_mode,
            ],
        ]);
    }
}
