<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckoutSession;
use App\Models\ShieldSite;
use App\Services\CheckoutSessionService;
use App\Services\HmacService;
use App\Services\SiteRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutSessionController extends Controller
{
    public function __construct(
        private CheckoutSessionService $sessionService,
        private SiteRouterService $siteRouter,
        private HmacService $hmacService,
    ) {}

    // ── POST /api/checkout-sessions ───────────────────────────────────────

    /**
     * Create a new checkout session (called by Paygates / money site plugin).
     *
     * Returns: { checkout_id, iframe_url, expires_at }
     */
    public function create(Request $request): JsonResponse
    {
        $user = $request->user();

        \Illuminate\Support\Facades\Log::error('[OneShield] create_session', [
            'meta'            => $request->input('meta'),
            'idempotency_key' => $request->input('idempotency_key'),
        ]);

        $validated = $request->validate([
            'gateway'            => 'required|in:paypal,stripe,airwallex',
            'order_ref'          => 'required|string|max:255',
            'amount'             => 'required|numeric|min:0.01',
            'currency'           => 'required|string|size:3',
            'group_id'           => 'nullable|string|max:255',
            'mode'               => 'nullable|in:live,test',
            'capture_method'     => 'nullable|in:automatic,manual',
            'enable_wallets'     => 'nullable|boolean',
            'descriptor'         => 'nullable|string|max:22',
            'description_format' => 'nullable|string|max:255',
            'idempotency_key'    => 'nullable|string|max:100',
            'meta'               => 'nullable|array',
            // Billing details (stored encrypted, never in URL)
            'billing'                   => 'nullable|array',
            'billing.first_name'        => 'nullable|string|max:100',
            'billing.last_name'         => 'nullable|string|max:100',
            'billing.email'             => 'nullable|email|max:255',
            'billing.phone'             => 'nullable|string|max:30',
            'billing.address_1'         => 'nullable|string|max:255',
            'billing.address_2'         => 'nullable|string|max:255',
            'billing.city'              => 'nullable|string|max:100',
            'billing.state'             => 'nullable|string|max:100',
            'billing.postcode'          => 'nullable|string|max:20',
            'billing.country'           => 'nullable|string|size:2',
        ]);

        // Resolve group_id (int or name string)
        $groupId = $this->resolveGroupId($user->id, $validated['group_id'] ?? null);

        // Select shield site
        $site = $this->siteRouter->selectSite(
            $user->id,
            $validated['gateway'],
            $groupId,
            (float) $validated['amount']
        );

        if (!$site) {
            return response()->json([
                'error' => 'No active shield site available for the requested gateway',
            ], 503);
        }

        // Convert amount to minor units (cents)
        $amountMinor   = (int) round((float) $validated['amount'] * 100);
        $amountDisplay = number_format((float) $validated['amount'], 2, '.', '');

        $session = $this->sessionService->create($user, $site, [
            'gateway'            => $validated['gateway'],
            'order_ref'          => $validated['order_ref'],
            'amount_minor'       => $amountMinor,
            'currency'           => $validated['currency'],
            'amount_display'     => $amountDisplay,
            'mode'               => $validated['mode'] ?? 'live',
            'capture_method'     => $validated['capture_method'] ?? 'automatic',
            'enable_wallets'     => $validated['enable_wallets'] ?? true,
            'descriptor'         => $validated['descriptor'] ?? null,
            'description_format' => $validated['description_format'] ?? null,
            'billing'            => $validated['billing'] ?? null,
            'idempotency_key'    => $validated['idempotency_key'] ?? null,
            'meta'               => $validated['meta'] ?? null,
        ]);

        return response()->json([
            'checkout_id' => $session->id,
            'iframe_url'  => $this->sessionService->buildIframeUrl($site, $session->id),
            'expires_at'  => $session->expires_at->toIso8601String(),
            'site_id'     => $site->id,
        ], 201);
    }

    // ── GET /api/checkout-sessions/{id} ───────────────────────────────────

    /**
     * Resolve a checkout session (called by Shield Site WP plugin).
     *
     * Authenticated via HMAC using site_key. The HmacAuthentication middleware
     * sets 'site_id' in request attributes when authenticated with a site_key.
     *
     * Returns the full payment context so the plugin can render Stripe/PayPal.
     */
    public function resolve(Request $request, string $id): JsonResponse
    {
        // site_id is set by HmacAuthentication when a shield site authenticates
        // using its site_key (as opposed to a user/panel token).
        $siteId = (int) $request->attributes->get('site_id', 0);

        // Fallback: if authenticated as a user (not site), allow access to
        // sessions belonging to that user (useful for debugging / admin tools).
        if (!$siteId) {
            $user = $request->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
        }

        try {
            // If site_id is known (site_key auth), validate site ownership
            if ($siteId) {
                $session = $this->sessionService->resolve($id, $siteId);
            } else {
                // User auth: only validate session belongs to this user
                $session = CheckoutSession::where('id', $id)
                    ->where('user_id', $request->user()->id)
                    ->firstOrFail();
                if (!$session->isUsable()) {
                    return response()->json(['error' => 'checkout_session_not_usable:' . $session->status], 422);
                }
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['error' => 'checkout_session_not_found'], 404);
        } catch (\RuntimeException $e) {
            // Message format: "error_key" with code passed as second arg
            $httpCode = $e->getCode() ?: 422;
            if (!in_array($httpCode, [400, 403, 404, 410, 422], true)) {
                $httpCode = 422;
            }
            return response()->json(['error' => $e->getMessage()], $httpCode);
        }

        // Decrypt billing snapshot for use by the plugin
        $billing = $session->billing_snapshot;

        return response()->json([
            'checkout_id'        => $session->id,
            'gateway'            => $session->gateway,
            'order_ref'          => $session->order_ref,
            'amount_minor'       => $session->amount_minor,
            'amount_display'     => $session->amount_display,
            'currency'           => $session->currency,
            'mode'               => $session->mode,
            'capture_method'     => $session->capture_method,
            'enable_wallets'     => $session->enable_wallets,
            'descriptor'         => $session->descriptor,
            'description_format' => $session->description_format,
            'billing'            => $billing,
            'status'             => $session->status,
            'expires_at'         => $session->expires_at->toIso8601String(),
        ]);
    }

    // ── POST /api/checkout-sessions/{id}/refresh ──────────────────────────

    /**
     * Update amount/currency on an active session (e.g. cart changed before paying).
     * Called by money site plugin when order total changes.
     */
    public function refresh(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'amount'   => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
        ]);

        $session = CheckoutSession::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if (!$session->isUsable()) {
            return response()->json(['error' => 'checkout_session_not_refreshable:' . $session->status], 422);
        }

        $amountMinor   = (int) round((float) $validated['amount'] * 100);
        $amountDisplay = number_format((float) $validated['amount'], 2, '.', '');

        $session = $this->sessionService->refresh($session, $amountMinor, $validated['currency'], $amountDisplay);

        return response()->json([
            'checkout_id'    => $session->id,
            'amount_minor'   => $session->amount_minor,
            'amount_display' => $session->amount_display,
            'currency'       => $session->currency,
            'status'         => $session->status,
        ]);
    }

    // ── POST /api/checkout-sessions/{id}/complete ─────────────────────────

    /**
     * Mark a session as completed and create a Transaction record.
     * Called by money site plugin (process_payment) after Stripe confirms.
     */
    public function complete(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'gateway_transaction_id'   => 'required|string|max:100',
            'stripe_payment_intent_id' => 'nullable|string|max:100',
        ]);

        $session = CheckoutSession::findOrFail($id);

        if ($session->isCompleted()) {
            return response()->json([
                'checkout_id' => $session->id,
                'status'      => $session->status,
                'message'     => 'already_completed',
            ]);
        }

        if (!$session->isUsable()) {
            return response()->json(['error' => 'checkout_session_not_completable:' . $session->status], 422);
        }

        // Create Transaction record so the Panel dashboard shows this payment
        $billing  = $session->billing_snapshot ?? [];
        $shipping = $billing['shipping'] ?? [];
        unset($billing['shipping']); // keep billing_data clean

        $transaction = \App\Models\Transaction::create([
            'site_id'                => $session->site_id,
            'order_id'               => $session->order_ref,
            'amount'                 => number_format($session->amount_minor / 100, 2, '.', ''),
            'currency'               => strtoupper($session->currency),
            'gateway'                => $session->gateway,
            'status'                 => 'completed',
            'gateway_transaction_id' => $validated['gateway_transaction_id'],
            'money_site_domain'      => $session->meta['money_site_domain'] ?? null,
            'billing_data'           => !empty($billing) ? $billing : null,
        ]);

        // Link transaction back to session
        $session->update(['transaction_id' => $transaction->id]);

        $session = $this->sessionService->complete(
            $session,
            $validated['gateway_transaction_id'],
            $validated['stripe_payment_intent_id'] ?? null
        );

        return response()->json([
            'checkout_id'    => $session->id,
            'status'         => $session->status,
            'transaction_id' => $transaction->id,
            'completed_at'   => $session->completed_at?->toIso8601String(),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function resolveGroupId(int $userId, ?string $rawGroupId): ?int
    {
        if ($rawGroupId === null || $rawGroupId === '') {
            return null;
        }

        if (ctype_digit((string) $rawGroupId)) {
            return (int) $rawGroupId;
        }

        $group = \App\Models\SiteGroup::where('user_id', $userId)
            ->where('name', $rawGroupId)
            ->first();

        return $group?->id;
    }
}
