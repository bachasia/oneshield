<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShieldSite;
use App\Models\Transaction;
use App\Services\CheckoutSessionService;
use App\Services\HmacService;
use App\Services\SiteRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaygatesController extends Controller
{
    public function __construct(
        private SiteRouterService $siteRouter,
        private HmacService $hmacService,
        private CheckoutSessionService $sessionService,
    ) {}

    /**
     * Select an appropriate shield site and return iframe URL.
     * POST /api/paygates/get-site
     */
    public function getSite(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'gateway'          => 'required|in:paypal,stripe,airwallex',
            'order_id'         => 'required|string|max:255',
            'amount'           => 'required|numeric|min:0.01',
            'currency'         => 'required|string|size:3',
            'group_id'         => 'nullable|string|max:255',
            'idempotency_key'  => 'nullable|string|max:100',
            'extra_params'     => 'nullable|array',
            'extra_params.*'   => 'nullable|string|max:500',
            // Billing details — only accepted when send_billing is set
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

        // Resolve group_id: accept integer ID or group name string
        $groupId = null;
        $rawGroupId = $validated['group_id'] ?? null;
        if ($rawGroupId !== null && $rawGroupId !== '') {
            if (ctype_digit((string) $rawGroupId)) {
                $groupId = (int) $rawGroupId;
            } else {
                $group = \App\Models\SiteGroup::where('user_id', $user->id)
                    ->where('name', $rawGroupId)
                    ->first();
                $groupId = $group?->id;
            }
        }

        $site = $this->siteRouter->selectSite(
            $user->id,
            $validated['gateway'],
            $groupId,
            (float) $validated['amount']
        );

        if (!$site) {
            // Provide debugging hints in the error response
            $allSites = ShieldSite::where('user_id', $user->id)->get();
            $activeSites = $allSites->where('is_active', true);
            $gwSites = $activeSites->filter(fn ($s) => $s->supportsGateway($validated['gateway']));
            $onlineSites = $gwSites->filter(fn ($s) => $s->last_heartbeat_at && $s->last_heartbeat_at->gte(now()->subMinutes(10)));

            // Per-site spin limit detail for debugging
            $gw = $validated['gateway'];
            $reqAmount = (float) $validated['amount'];
            $spinDetail = $onlineSites->map(function ($s) use ($gw, $reqAmount) {
                $maxPerOrder = (float) ($gw === 'paypal' ? $s->paypal_max_per_order : $s->stripe_max_per_order);
                $incomeLimit = (float) ($gw === 'paypal' ? $s->paypal_income_limit  : $s->stripe_income_limit);

                // Compute actual cycle earnings for income limit check
                $cycleEarned = null;
                if ($incomeLimit > 0) {
                    $cycleStart = match ($s->receive_cycle) {
                        'daily'   => now()->startOfDay(),
                        'weekly'  => now()->startOfWeek(),
                        'monthly' => now()->startOfMonth(),
                        default   => \Illuminate\Support\Carbon::createFromTimestamp(0),
                    };
                    $cycleEarned = (float) Transaction::where('site_id', $s->id)
                        ->where('gateway', $gw)
                        ->where('status', 'completed')
                        ->where('created_at', '>=', $cycleStart)
                        ->sum('amount');
                }

                // Determine blocked reason
                $reason = 'passes';
                if ($maxPerOrder > 0 && $reqAmount > $maxPerOrder) {
                    $reason = "Order amount {$reqAmount} exceeds max_per_order {$maxPerOrder}";
                } elseif ($incomeLimit > 0 && $cycleEarned >= $incomeLimit) {
                    $reason = "Cycle earnings {$cycleEarned} reached income_limit {$incomeLimit} ({$s->receive_cycle})";
                }

                // Also check if passesSpinLimits agrees
                $routerPasses = app(SiteRouterService::class)->passesSpinLimits($s, $gw, $reqAmount);

                return [
                    'site_id'         => $s->id,
                    'name'            => $s->name,
                    'max_per_order'   => $maxPerOrder,
                    'income_limit'    => $incomeLimit,
                    'receive_cycle'   => $s->receive_cycle,
                    'cycle_earned'    => $cycleEarned,
                    'order_amount'    => $reqAmount,
                    'failure_count'   => $s->failure_count,
                    'last_heartbeat'  => $s->last_heartbeat_at?->toIso8601String(),
                    'passes_spin'     => $routerPasses,
                    'blocked_reason'  => $reason,
                ];
            })->values();

            return response()->json([
                'error' => 'No active shield site available for the requested gateway',
                'debug' => [
                    'total_sites'           => $allSites->count(),
                    'active_sites'          => $activeSites->count(),
                    'gateway_enabled_sites' => $gwSites->count(),
                    'online_sites'          => $onlineSites->count(),
                    'requested_gateway'     => $gw,
                    'requested_amount'      => $reqAmount,
                    'resolved_group_id'     => $groupId,
                    'spin_detail'           => $spinDetail,
                    'hint'                  => $gwSites->isEmpty()
                        ? 'No sites have ' . $gw . ' enabled with credentials configured.'
                        : ($onlineSites->isEmpty()
                            ? 'Sites exist but none have a recent heartbeat (within 10 min). Check the Shield Site plugin connection.'
                            : 'Sites exist and are online but may have exceeded spin/income limits.'),
                ],
            ], 503);
        }

        $moneySiteDomain = parse_url($request->header('Origin', ''), PHP_URL_HOST) ?? 'unknown';

        // ── Phase 1: Dual mode (CHECKOUT_ID_ENABLED=true) ─────────────────
        // Create a checkout_session to hold payment context + billing (encrypted).
        // No Transaction record is created here — it will be created only when
        // payment completes or fails (confirm endpoint / webhook).
        $checkoutIdEnabled = config('oneshield.checkout_id_enabled', false);

        if ($checkoutIdEnabled) {
            $amountMinor   = (int) round((float) $validated['amount'] * 100);
            $amountDisplay = number_format((float) $validated['amount'], 2, '.', '');
            $extraParams   = $validated['extra_params'] ?? [];

            $checkoutToken = $this->hmacService->sign(
                ['order_id' => $validated['order_id'], 'site_id' => $site->id],
                $site->site_key
            );

            $session = $this->sessionService->create($user, $site, [
                'gateway'            => $validated['gateway'],
                'order_ref'          => $validated['order_id'],
                'amount_minor'       => $amountMinor,
                'currency'           => $validated['currency'],
                'amount_display'     => $amountDisplay,
                'mode'               => $extraParams['mode'] ?? 'live',
                'capture_method'     => $extraParams['capture_method'] ?? 'automatic',
                'enable_wallets'     => ($extraParams['enable_wallets'] ?? '1') === '1',
                'descriptor'         => $extraParams['statement_descriptor'] ?? null,
                'description_format' => $extraParams['description_format'] ?? null,
                'billing'            => $validated['billing'] ?? null,
                'idempotency_key'    => $validated['idempotency_key'] ?? null,
                'meta'               => [
                    'money_site_domain' => $moneySiteDomain,
                    'site_id'           => $site->id,
                ],
            ]);

            return response()->json([
                'site_id'    => $site->id,
                'checkout_id' => $session->id,
                'iframe_url' => $this->sessionService->buildIframeUrl($site, $session->id),
                'token'      => $checkoutToken,
                'expires_at' => $session->expires_at->toIso8601String(),
            ]);
        }

        // ── Legacy mode ───────────────────────────────────────────────────
        // Create a pending Transaction immediately so the shield site can fetch
        // billing via /api/connect/billing using txn_id in the iframe URL.
        $transaction = Transaction::create([
            'site_id'            => $site->id,
            'order_id'           => $validated['order_id'],
            'amount'             => $validated['amount'],
            'currency'           => strtoupper($validated['currency']),
            'gateway'            => $validated['gateway'],
            'status'             => 'pending',
            'money_site_domain'  => $moneySiteDomain,
            'billing_data'       => !empty($validated['billing']) ? $validated['billing'] : null,
        ]);

        $checkoutToken = $this->hmacService->sign(
            ['transaction_id' => $transaction->id, 'order_id' => $validated['order_id']],
            $site->site_key
        );

        $extraParams = array_merge($validated['extra_params'] ?? [], [
            'txn_id'  => (string) $transaction->id,
            'site_id' => (string) $site->id,
        ]);

        $iframeUrl = $this->siteRouter->buildIframeUrl(
            $site,
            $validated['gateway'],
            $validated['order_id'],
            $checkoutToken,
            (float) $validated['amount'],
            $validated['currency'],
            $extraParams
        );

        return response()->json([
            'site_id'        => $site->id,
            'transaction_id' => $transaction->id,
            'iframe_url'     => $iframeUrl,
            'token'          => $checkoutToken,
        ]);
    }

    /**
     * Update billing data on an existing pending transaction or checkout session.
     * Called by process_payment() after WC creates the order — billing is final.
     * POST /api/paygates/update-billing
     */
    public function updateBilling(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'transaction_id'     => 'nullable|integer',
            'checkout_id'        => 'nullable|string',
            'billing'            => 'required|array',
            'billing.first_name' => 'nullable|string|max:100',
            'billing.last_name'  => 'nullable|string|max:100',
            'billing.email'      => 'nullable|email|max:255',
            'billing.phone'      => 'nullable|string|max:30',
            'billing.address_1'  => 'nullable|string|max:255',
            'billing.address_2'  => 'nullable|string|max:255',
            'billing.city'       => 'nullable|string|max:100',
            'billing.state'      => 'nullable|string|max:100',
            'billing.postcode'   => 'nullable|string|max:20',
            'billing.country'    => 'nullable|string|size:2',
            // Shipping address — may differ from billing
            'shipping'            => 'nullable|array',
            'shipping.first_name' => 'nullable|string|max:100',
            'shipping.last_name'  => 'nullable|string|max:100',
            'shipping.phone'      => 'nullable|string|max:30',
            'shipping.address_1'  => 'nullable|string|max:255',
            'shipping.address_2'  => 'nullable|string|max:255',
            'shipping.city'       => 'nullable|string|max:100',
            'shipping.state'      => 'nullable|string|max:100',
            'shipping.postcode'   => 'nullable|string|max:20',
            'shipping.country'    => 'nullable|string|size:2',
        ]);

        $billing  = array_filter($validated['billing']);
        $shipping = array_filter($validated['shipping'] ?? []);

        // checkout_id mode: update billing on checkout_session
        if (!empty($validated['checkout_id'])) {
            $session = \App\Models\CheckoutSession::where('id', $validated['checkout_id'])
                ->where('user_id', $user->id)
                ->firstOrFail();

            $snapshot = $billing;
            if (!empty($shipping)) {
                $snapshot['shipping'] = $shipping;
            }
            $session->update(['billing_snapshot' => $snapshot]);
            return response()->json(['success' => true]);
        }

        // Legacy mode: update billing on pending transaction
        $transaction = Transaction::whereHas('site', fn ($q) => $q->where('user_id', $user->id))
            ->where('id', $validated['transaction_id'])
            ->where('status', 'pending')
            ->firstOrFail();

        $data = $billing;
        if (!empty($shipping)) {
            $data['shipping'] = $shipping;
        }
        $transaction->update(['billing_data' => $data]);

        return response()->json(['success' => true]);
    }

    /**
     * Confirm a completed payment.
     * POST /api/paygates/confirm
     *
     * In checkout_id mode: creates the Transaction record now (not at get-site time).
     * In legacy mode: updates the existing pending Transaction.
     */
    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'site_id'                => 'required|integer',
            'order_id'               => 'required|string',
            'gateway_transaction_id' => 'required|string',
            'status'                 => 'required|in:completed,failed',
            'gateway'                => 'nullable|in:paypal,stripe,airwallex',
            'amount'                 => 'nullable|numeric|min:0.01',
            'currency'               => 'nullable|string|size:3',
            'checkout_id'            => 'nullable|string',
        ]);

        $site = \App\Models\ShieldSite::where('id', $validated['site_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        // ── checkout_id mode: create Transaction now ──────────────────────
        if (!empty($validated['checkout_id'])) {
            $session = \App\Models\CheckoutSession::where('id', $validated['checkout_id'])
                ->where('user_id', $user->id)
                ->first();

            // Resolve amount/currency from session if not provided
            $amount   = $validated['amount']   ?? ($session ? $session->amount_minor / 100 : 0);
            $currency = $validated['currency'] ?? ($session ? strtoupper($session->currency) : 'USD');
            $gateway  = $validated['gateway']  ?? ($session?->gateway ?? 'stripe');

            $billing = $session?->billing_snapshot;

            $transaction = Transaction::create([
                'site_id'                => $site->id,
                'order_id'               => $validated['order_id'],
                'amount'                 => $amount,
                'currency'               => strtoupper($currency),
                'gateway'                => $gateway,
                'status'                 => $validated['status'],
                'gateway_transaction_id' => $validated['gateway_transaction_id'],
                'money_site_domain'      => $session?->meta['money_site_domain'] ?? 'unknown',
                'billing_data'           => $billing ?: null,
            ]);

            // Link session → transaction and mark complete/cancelled
            if ($session) {
                $session->update(['transaction_id' => $transaction->id]);
                if ($validated['status'] === 'completed') {
                    $session->markCompleted($validated['gateway_transaction_id']);
                }
            }

            return response()->json([
                'success'        => true,
                'transaction_id' => $transaction->id,
                'status'         => $transaction->status,
            ]);
        }

        // ── Legacy mode: update existing pending Transaction ──────────────
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
     * Patch a Stripe PaymentIntent with the real WooCommerce order ID.
     * POST /api/paygates/patch-order-id
     *
     * Called by process_payment() after WC creates the order so the
     * Stripe dashboard shows the real order number instead of checkout UUID.
     *
     * Flow:
     *  1. Look up CheckoutSession to get stripe_payment_intent_id + site
     *  2. Relay a signed AJAX call to the shield site's osc_patch_pi_wc_order handler
     *  3. Also update the Transaction.order_id with the real WC order ID
     */
    public function patchOrderId(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'checkout_id' => 'required|string',
            'wc_order_id' => 'required|string|max:100',
        ]);

        // Look up session — must belong to this user
        $session = \App\Models\CheckoutSession::where('id', $validated['checkout_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$session) {
            return response()->json(['error' => 'checkout_session_not_found'], 404);
        }

        $piId = $session->stripe_payment_intent_id ?? $session->gateway_transaction_id ?? null;

        if (empty($piId) || !str_starts_with($piId, 'pi_')) {
            // No PI on record yet — nothing to patch, succeed silently
            return response()->json(['success' => true, 'skipped' => true]);
        }

        // Update Transaction.order_id if linked
        if ($session->transaction_id) {
            \App\Models\Transaction::where('id', $session->transaction_id)
                ->update(['order_id' => $validated['wc_order_id']]);
        }

        // Relay to shield site via its WordPress AJAX endpoint
        $site = $session->site;
        if (!$site || empty($site->url) || empty($site->site_key)) {
            return response()->json(['success' => true, 'skipped' => true, 'reason' => 'site_url_missing']);
        }

        $ajaxUrl = rtrim($site->url, '/') . '/wp-admin/admin-ajax.php';

        // Build the resolved description so [order_id] uses the real WC order number.
        // Panel has all the data: description_format (from session) + billing (from snapshot).
        $resolvedDescription = '';
        $descFormat = $session->description_format ?? '';
        if (!empty($descFormat)) {
            $billing   = $session->billing_snapshot ?? [];
            $first     = trim($billing['first_name'] ?? '');
            $last      = trim($billing['last_name']  ?? '');
            $wcOrderId = $validated['wc_order_id'];
            $randStr   = substr(hash('sha256', 'osp_rand|' . $wcOrderId . '|' . ($session->site_id ?? '')), 0, 8);
            $moneySite = $session->meta['money_site_domain'] ?? '';

            $desc = $descFormat;
            $desc = str_replace('[order_id]',      $wcOrderId,  $desc);
            $desc = str_replace('[first_name]',    $first,      $desc);
            $desc = str_replace('[last_name]',     $last,       $desc);
            $desc = str_replace('[rand_str]',      $randStr,    $desc);
            $desc = str_replace('[merchant_site]', $moneySite,  $desc);
            $resolvedDescription = trim($desc);
        }

        $relayPayload = [
            'action'      => 'osc_patch_pi_wc_order',
            'pi_id'       => $piId,
            'wc_order_id' => $validated['wc_order_id'],
            'site_key'    => $site->site_key,
        ];
        if (!empty($resolvedDescription)) {
            $relayPayload['description'] = $resolvedDescription;
        }

        $relayResp = \Illuminate\Support\Facades\Http::timeout(8)->asForm()->post($ajaxUrl, $relayPayload);

        if ($relayResp->failed()) {
            return response()->json([
                'success' => false,
                'error'   => 'shield_relay_failed',
                'status'  => $relayResp->status(),
            ], 502);
        }

        $relayBody = $relayResp->json();

        return response()->json([
            'success'     => (bool) ($relayBody['success'] ?? false),
            'pi_id'       => $piId,
            'wc_order_id' => $validated['wc_order_id'],
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
            'gateway'        => 'required|in:paypal,stripe,airwallex',
            'order_id'       => 'required|string',
            'amount'         => 'required|numeric|min:0.01',
            'currency'       => 'required|string|size:3',
            'group_id'       => 'nullable|string|max:255',
            'extra_params'   => 'nullable|array',
            'extra_params.*' => 'nullable|string|max:500',
        ]);

        // Resolve group_id: accept integer ID or group name string
        $groupId = null;
        $rawGroupId = $validated['group_id'] ?? null;
        if ($rawGroupId !== null && $rawGroupId !== '') {
            if (ctype_digit((string) $rawGroupId)) {
                $groupId = (int) $rawGroupId;
            } else {
                $group = \App\Models\SiteGroup::where('user_id', $user->id)
                    ->where('name', $rawGroupId)
                    ->first();
                $groupId = $group?->id;
            }
        }

        $site = $this->siteRouter->selectSite(
            $user->id,
            $validated['gateway'],
            $groupId
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
                $checkoutToken,
                (float) $validated['amount'],
                $validated['currency'],
                $validated['extra_params'] ?? []
            ),
        ]);
    }
}
