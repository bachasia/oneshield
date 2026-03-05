<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShieldSite;
use App\Models\Transaction;
use App\Services\HmacService;
use App\Services\SiteRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaygatesController extends Controller
{
    public function __construct(
        private SiteRouterService $siteRouter,
        private HmacService $hmacService,
    ) {}

    /**
     * Select an appropriate shield site and return iframe URL.
     * POST /api/paygates/get-site
     */
    public function getSite(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'gateway'   => 'required|in:paypal,stripe,airwallex',
            'order_id'  => 'required|string|max:255',
            'amount'    => 'required|numeric|min:0.01',
            'currency'  => 'required|string|size:3',
            'group_id'  => 'nullable|integer|exists:site_groups,id',
        ]);

        $site = $this->siteRouter->selectSite(
            $user->id,
            $validated['gateway'],
            $validated['group_id'] ?? null,
            (float) $validated['amount']
        );

        if (!$site) {
            return response()->json([
                'error' => 'No active shield site available for the requested gateway',
            ], 503);
        }

        // Create a pending transaction record
        $transaction = Transaction::create([
            'site_id'            => $site->id,
            'order_id'           => $validated['order_id'],
            'amount'             => $validated['amount'],
            'currency'           => strtoupper($validated['currency']),
            'gateway'            => $validated['gateway'],
            'status'             => 'pending',
            'money_site_domain'  => parse_url($request->header('Origin', ''), PHP_URL_HOST) ?? 'unknown',
        ]);

        // One-time token for this checkout session (signed with site_key)
        $checkoutToken = $this->hmacService->sign(
            ['transaction_id' => $transaction->id, 'order_id' => $validated['order_id']],
            $site->site_key
        );

        $iframeUrl = $this->siteRouter->buildIframeUrl(
            $site,
            $validated['gateway'],
            $validated['order_id'],
            $checkoutToken
        );

        return response()->json([
            'site_id'        => $site->id,
            'transaction_id' => $transaction->id,
            'iframe_url'     => $iframeUrl,
            'token'          => $checkoutToken,
        ]);
    }

    /**
     * Confirm a completed payment.
     * POST /api/paygates/confirm
     */
    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'site_id'               => 'required|integer',
            'order_id'              => 'required|string',
            'gateway_transaction_id' => 'required|string',
            'status'                => 'required|in:completed,failed',
        ]);

        $transaction = Transaction::whereHas('site', fn ($q) => $q->where('user_id', $user->id))
            ->where('order_id', $validated['order_id'])
            ->where('status', 'pending')
            ->firstOrFail();

        $transaction->update([
            'status'                 => $validated['status'],
            'gateway_transaction_id' => $validated['gateway_transaction_id'],
        ]);

        return response()->json([
            'success'        => true,
            'transaction_id' => $transaction->id,
            'status'         => $transaction->status,
        ]);
    }

    /**
     * Connection status check for the Paygates plugin settings page.
     * GET /api/paygates/status
     *
     * Returns account info + per-gateway active site counts so the plugin
     * admin can confirm the Token Secret is valid and sites are ready.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        $sites = ShieldSite::where('user_id', $user->id)->where('is_active', true)->get();

        $now = now();
        $connected = fn ($site) => $site->last_heartbeat_at && $site->last_heartbeat_at->diffInMinutes($now) <= 10;

        return response()->json([
            'ok'      => true,
            'account' => [
                'name'      => $user->name,
                'tenant_id' => $user->tenant_id,
            ],
            'sites' => [
                'total'   => $sites->count(),
                'stripe'  => $sites->filter(fn ($s) => $s->stripe_enabled && $s->hasGatewayCredentials('stripe'))->count(),
                'paypal'  => $sites->filter(fn ($s) => $s->paypal_enabled && $s->hasGatewayCredentials('paypal'))->count(),
                'online'  => $sites->filter($connected)->count(),
            ],
        ]);
    }

    /**
     * Get iframe URL directly (GET variant).
     * GET /api/paygates/iframe-url
     */
    public function iframeUrl(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'gateway'  => 'required|in:paypal,stripe,airwallex',
            'order_id' => 'required|string',
            'group_id' => 'nullable|integer|exists:site_groups,id',
        ]);

        $site = $this->siteRouter->selectSite(
            $user->id,
            $validated['gateway'],
            $validated['group_id'] ?? null
        );

        if (!$site) {
            return response()->json(['error' => 'No active shield site available'], 503);
        }

        $checkoutToken = $this->hmacService->sign(
            ['order_id' => $validated['order_id']],
            $site->site_key
        );

        return response()->json([
            'iframe_url' => $this->siteRouter->buildIframeUrl(
                $site,
                $validated['gateway'],
                $validated['order_id'],
                $checkoutToken
            ),
        ]);
    }
}
