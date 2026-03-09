<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckoutSession;
use App\Models\ShieldSite;
use App\Models\Transaction;
use App\Services\SiteRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private SiteRouterService $siteRouter) {}

    /**
     * Handle PayPal IPN/Webhook.
     * POST /api/webhook/paypal/{site_id}
     *
     * Verifies the IPN with PayPal's verification endpoint before processing.
     */
    public function paypal(Request $request, int $siteId): JsonResponse
    {
        $site    = ShieldSite::findOrFail($siteId);
        $payload = $request->all();
        $rawBody = $request->getContent();

        Log::channel('webhooks')->info('PayPal webhook received', [
            'site_id' => $siteId,
            'payload' => $payload,
        ]);

        // Verify IPN with PayPal
        if (!$this->verifyPaypalIpn($rawBody, $site->paypal_mode ?? 'sandbox')) {
            Log::channel('webhooks')->warning('PayPal IPN verification failed', ['site_id' => $siteId]);
            // Still return 200 to prevent PayPal from retrying (but don't process)
            return response()->json(['status' => 'invalid']);
        }

        $txnId   = $payload['txn_id'] ?? $payload['id'] ?? null;
        $status  = $this->mapPaypalStatus($payload['payment_status'] ?? $payload['status'] ?? '');
        $orderId = $payload['invoice'] ?? $payload['custom'] ?? null;

        if ($txnId && $orderId) {
            $transaction = Transaction::where('site_id', $siteId)
                ->where('order_id', $orderId)
                ->first();

            if ($transaction) {
                // Legacy: update existing pending record
                if ($transaction->status === 'pending') {
                    $transaction->update([
                        'status'                 => $status,
                        'gateway_transaction_id' => $txnId,
                        'raw_response'           => $payload,
                    ]);

                    if ($status === 'completed') {
                        $this->siteRouter->recordSuccess($site);
                    } elseif ($status === 'failed') {
                        $this->siteRouter->recordFailure($site);
                    }
                }
            } elseif (in_array($status, ['completed', 'failed'])) {
                // checkout_id mode: create transaction now
                $session = CheckoutSession::where('site_id', $siteId)
                    ->where('order_ref', $orderId)
                    ->whereIn('status', ['created', 'processing'])
                    ->latest()
                    ->first();

                $transaction = Transaction::create([
                    'site_id'                => $siteId,
                    'order_id'               => $orderId,
                    'amount'                 => $session ? $session->amount_minor / 100 : (float) ($payload['mc_gross'] ?? 0),
                    'currency'               => strtoupper($session?->currency ?? ($payload['mc_currency'] ?? 'USD')),
                    'gateway'                => 'paypal',
                    'status'                 => $status,
                    'gateway_transaction_id' => $txnId,
                    'money_site_domain'      => $session?->meta['money_site_domain'] ?? 'unknown',
                    'billing_data'           => $session?->billing_snapshot ?: null,
                    'raw_response'           => $payload,
                ]);

                if ($session) {
                    $session->update(['transaction_id' => $transaction->id]);
                    if ($status === 'completed') {
                        $session->markCompleted($txnId);
                    }
                }

                if ($status === 'completed') {
                    $this->siteRouter->recordSuccess($site);
                } else {
                    $this->siteRouter->recordFailure($site);
                }
            }
        }

        return response()->json(['status' => 'received']);
    }

    /**
     * Handle Stripe Webhook.
     * POST /api/webhook/stripe/{site_id}
     *
     * Verifies Stripe-Signature header using the site's Stripe secret key.
     */
    public function stripe(Request $request, int $siteId): JsonResponse
    {
        $site    = ShieldSite::findOrFail($siteId);
        $rawBody = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        Log::channel('webhooks')->info('Stripe webhook received', [
            'site_id'    => $siteId,
            'event_type' => json_decode($rawBody, true)['type'] ?? 'unknown',
        ]);

        // Verify Stripe signature if we have the webhook secret configured
        $webhookSecret = $site->stripe_webhook_secret ?? null;
        if ($webhookSecret && $sigHeader) {
            if (!$this->verifyStripeSignature($rawBody, $sigHeader, $webhookSecret)) {
                Log::channel('webhooks')->warning('Stripe signature verification failed', ['site_id' => $siteId]);
                return response()->json(['error' => 'Invalid signature'], 400);
            }
        }

        $payload   = json_decode($rawBody, true) ?? [];
        $eventType = $payload['type'] ?? '';
        $object    = $payload['data']['object'] ?? [];

        if (in_array($eventType, ['payment_intent.succeeded', 'charge.succeeded'])) {
            $txnId   = $object['id'] ?? null;
            $orderId = $object['metadata']['order_id'] ?? null;

            if ($txnId && $orderId) {
                // Try to find existing transaction (legacy mode — created at get-site time)
                $transaction = Transaction::where('site_id', $siteId)
                    ->where('order_id', $orderId)
                    ->first();

                if ($transaction) {
                    // Legacy: update existing pending record
                    if ($transaction->status === 'pending') {
                        $transaction->update([
                            'status'                 => 'completed',
                            'gateway_transaction_id' => $txnId,
                            'raw_response'           => $payload,
                        ]);
                        $this->siteRouter->recordSuccess($site);
                    }
                } else {
                    // checkout_id mode: create transaction now from checkout_session
                    $session = \App\Models\CheckoutSession::where('site_id', $siteId)
                        ->where('order_ref', $orderId)
                        ->whereIn('status', ['created', 'processing'])
                        ->latest()
                        ->first();

                    $transaction = Transaction::create([
                        'site_id'                => $siteId,
                        'order_id'               => $orderId,
                        'amount'                 => $session ? $session->amount_minor / 100 : ($object['amount'] ?? 0) / 100,
                        'currency'               => strtoupper($session?->currency ?? ($object['currency'] ?? 'USD')),
                        'gateway'                => 'stripe',
                        'status'                 => 'completed',
                        'gateway_transaction_id' => $txnId,
                        'money_site_domain'      => $session?->meta['money_site_domain'] ?? 'unknown',
                        'billing_data'           => $session?->billing_snapshot ?: null,
                        'raw_response'           => $payload,
                    ]);

                    if ($session) {
                        $session->update(['transaction_id' => $transaction->id]);
                        $session->markCompleted($txnId, $txnId);
                    }

                    $this->siteRouter->recordSuccess($site);
                }
            }
        } elseif (in_array($eventType, ['payment_intent.payment_failed', 'charge.failed'])) {
            $txnId   = $object['id'] ?? null;
            $orderId = $object['metadata']['order_id'] ?? null;

            if ($txnId && $orderId) {
                // Try legacy first
                $updated = Transaction::where('site_id', $siteId)
                    ->where('order_id', $orderId)
                    ->where('status', 'pending')
                    ->update(['status' => 'failed', 'gateway_transaction_id' => $txnId, 'raw_response' => $payload]);

                if ($updated) {
                    $this->siteRouter->recordFailure($site);
                } else {
                    // checkout_id mode: create failed transaction
                    $session = \App\Models\CheckoutSession::where('site_id', $siteId)
                        ->where('order_ref', $orderId)
                        ->whereIn('status', ['created', 'processing'])
                        ->latest()
                        ->first();

                    $transaction = Transaction::create([
                        'site_id'                => $siteId,
                        'order_id'               => $orderId,
                        'amount'                 => $session ? $session->amount_minor / 100 : ($object['amount'] ?? 0) / 100,
                        'currency'               => strtoupper($session?->currency ?? ($object['currency'] ?? 'USD')),
                        'gateway'                => 'stripe',
                        'status'                 => 'failed',
                        'gateway_transaction_id' => $txnId,
                        'money_site_domain'      => $session?->meta['money_site_domain'] ?? 'unknown',
                        'raw_response'           => $payload,
                    ]);

                    if ($session) {
                        $session->update(['transaction_id' => $transaction->id]);
                    }

                    $this->siteRouter->recordFailure($site);
                }
            }
        }

        return response()->json(['status' => 'received']);
    }

    /**
     * Health check endpoint.
     * GET /api/health
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status'    => 'ok',
            'version'   => config('app.version', '1.0.0'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Verify PayPal IPN by posting back to PayPal.
     */
    private function verifyPaypalIpn(string $rawBody, string $mode): bool
    {
        $verifyUrl = $mode === 'live'
            ? 'https://ipnpb.paypal.com/cgi-bin/webscr'
            : 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';

        try {
            $response = Http::withOptions(['timeout' => 10])
                ->withBody('cmd=_notify-validate&' . $rawBody, 'application/x-www-form-urlencoded')
                ->post($verifyUrl);

            return $response->successful() && trim($response->body()) === 'VERIFIED';
        } catch (\Throwable $e) {
            Log::channel('webhooks')->error('PayPal IPN verify request failed — rejecting (fail-closed)', ['error' => $e->getMessage()]);
            // Fail-closed: reject unverifiable IPN to prevent spoofed webhooks from completing transactions.
            // PayPal will retry unacknowledged IPNs, so legitimate payments will not be permanently lost.
            return false;
        }
    }

    /**
     * Verify Stripe webhook signature (manual implementation, no Stripe SDK needed).
     *
     * @see https://stripe.com/docs/webhooks/signatures
     */
    private function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool
    {
        // Parse Stripe-Signature header: t=timestamp,v1=signature,...
        $parts = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$k, $v] = explode('=', $part, 2) + [null, null];
            if ($k && $v) {
                $parts[$k][] = $v;
            }
        }

        $timestamp  = (int) ($parts['t'][0] ?? 0);
        $signatures = $parts['v1'] ?? [];

        if (!$timestamp || empty($signatures)) {
            return false;
        }

        // Reject if older than 5 minutes
        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expected      = hash_hmac('sha256', $signedPayload, $secret);

        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    private function mapPaypalStatus(string $status): string
    {
        return match(strtolower($status)) {
            'completed'                         => 'completed',
            'refunded', 'reversed'              => 'refunded',
            'denied', 'failed', 'expired', 'voided' => 'failed',
            default                             => 'pending',
        };
    }

    /**
     * Receive a normalised Stripe webhook event forwarded by a shield site.
     *
     * POST /api/webhook/from-shield
     *
     * The shield site has already:
     *  - Verified the Stripe-Signature with the whsec_ it holds
     *  - Parsed the event type and extracted PI ID / order metadata
     *
     * This endpoint is authenticated via the standard HMAC middleware
     * (same X-OneShield-Signature / X-OneShield-Token headers used by other
     * shield→panel calls), so we trust the parsed fields without re-verifying
     * the original Stripe signature.
     *
     * What this method does:
     *  1. Resolve shield site from X-OneShield-Token
     *  2. Update / create Transaction record with new status
     *  3. Push the status to the money site via ?wc-api=oneshield_webhook
     *     so WC order stays in sync even when customer's browser disconnected
     */
    public function fromShield(Request $request): JsonResponse
    {
        // ── Auth: resolve shield site from HMAC token ────────────────────────
        // HmacAuthentication middleware already verified the signature and
        // attached the authenticated User to the request. Resolve shield site
        // from shield_site_id sent in the payload.
        $user = $request->user();

        $validated = $request->validate([
            'event_type'     => 'required|string|max:100',
            'pi_id'          => 'required|string|max:255',
            'order_id'       => 'nullable|string|max:255',
            'amount'         => 'nullable|integer|min:0',
            'currency'       => 'nullable|string|size:3',
            'status'         => 'required|in:completed,failed,refunded,unknown',
            'gateway'        => 'nullable|string|max:50',
            'shield_site_id' => 'nullable|string|max:50',
            'raw'            => 'nullable|array',
        ]);

        $piId       = $validated['pi_id'];
        $orderId    = $validated['order_id']    ?? null;
        $status     = $validated['status'];
        $eventType  = $validated['event_type'];
        $amount     = $validated['amount']      ?? null;
        $currency   = strtoupper($validated['currency'] ?? 'USD');
        $rawPayload = $validated['raw']          ?? null;

        // Resolve shield site via X-OneShield-Token (user's token_secret matches
        // via HmacAuthentication; find site by shield_site_id field in payload).
        $shieldSiteId = $validated['shield_site_id'] ?? null;
        $site = null;
        if ($shieldSiteId) {
            $site = ShieldSite::where('id', $shieldSiteId)
                ->where('user_id', $user->id)
                ->first();
        }

        Log::channel('webhooks')->info('Stripe webhook from shield', [
            'event_type'     => $eventType,
            'pi_id'          => $piId,
            'order_id'       => $orderId,
            'status'         => $status,
            'shield_site_id' => $shieldSiteId,
        ]);

        // ── 1. Update / create Transaction ───────────────────────────────────
        $transaction = null;
        $moneySiteDomain = 'unknown';

        if ($orderId) {
            // Try to find via PI ID first (most reliable)
            $transaction = Transaction::where('gateway_transaction_id', $piId)->first();

            // Fallback: find via order_id scoped to this user's sites
            if (!$transaction) {
                $transaction = Transaction::where('order_id', $orderId)
                    ->whereHas('site', fn ($q) => $q->where('user_id', $user->id))
                    ->latest()
                    ->first();
            }
        }

        if ($transaction) {
            $moneySiteDomain = $transaction->money_site_domain ?? 'unknown';

            // Only update if it's a meaningful state transition
            $allowedTransitions = [
                'pending'    => ['completed', 'failed'],
                'completed'  => ['refunded'],
                'failed'     => [],
                'refunded'   => [],
            ];
            $currentStatus = $transaction->status ?? 'pending';
            if (in_array($status, $allowedTransitions[$currentStatus] ?? [], true)
                || $status === 'refunded') {
                $transaction->update([
                    'status'                 => $status,
                    'gateway_transaction_id' => $piId,
                    'raw_response'           => $rawPayload ?? $transaction->raw_response,
                ]);

                if ($status === 'completed' && $site) {
                    $this->siteRouter->recordSuccess($site);
                } elseif ($status === 'failed' && $site) {
                    $this->siteRouter->recordFailure($site);
                }
            }
        } elseif ($status === 'completed' && $orderId) {
            // No existing record — create from checkout session if available
            $session = CheckoutSession::where('order_ref', $orderId)
                ->whereHas('site', fn ($q) => $q->where('user_id', $user->id))
                ->latest()
                ->first();

            $transaction = Transaction::create([
                'site_id'                => $session?->site_id ?? $site?->id,
                'order_id'               => $orderId,
                'amount'                 => $session
                    ? $session->amount_minor / 100
                    : ($amount ? $amount / 100 : 0),
                'currency'               => $session?->currency ?? $currency,
                'gateway'                => $validated['gateway'] ?? 'stripe',
                'status'                 => 'completed',
                'gateway_transaction_id' => $piId,
                'money_site_domain'      => $session?->meta['money_site_domain'] ?? 'unknown',
                'billing_data'           => $session?->billing_snapshot ?: null,
                'raw_response'           => $rawPayload,
            ]);

            if ($session) {
                $session->update(['transaction_id' => $transaction->id]);
                $session->markCompleted($piId, $piId);
            }

            $moneySiteDomain = $transaction->money_site_domain;

            if ($site) {
                $this->siteRouter->recordSuccess($site);
            }
        } elseif ($status === 'failed' && $orderId) {
            // No existing record (checkout_id mode) — create a failed Transaction
            // so the failure is visible in the Panel even if the customer closed
            // the browser before the money site called /confirm.
            $session = CheckoutSession::where('order_ref', $orderId)
                ->whereHas('site', fn ($q) => $q->where('user_id', $user->id))
                ->latest()
                ->first();

            $transaction = Transaction::create([
                'site_id'                => $session?->site_id ?? $site?->id,
                'order_id'               => $orderId,
                'amount'                 => $session
                    ? $session->amount_minor / 100
                    : ($amount ? $amount / 100 : 0),
                'currency'               => $session?->currency ?? $currency,
                'gateway'                => $validated['gateway'] ?? 'stripe',
                'status'                 => 'failed',
                'gateway_transaction_id' => $piId,
                'money_site_domain'      => $session?->meta['money_site_domain'] ?? 'unknown',
                'billing_data'           => $session?->billing_snapshot ?: null,
                'raw_response'           => $rawPayload,
            ]);

            $moneySiteDomain = $transaction->money_site_domain;

            if ($site) {
                $this->siteRouter->recordFailure($site);
            }
        }

        // ── 2. Push status to money site ──────────────────────────────────────
        // Notify money site so WC order status is updated even when the
        // customer's browser disconnected after payment.
        if (!empty($moneySiteDomain) && $moneySiteDomain !== 'unknown' && $orderId) {
            $this->pushStatusToMoneySite(
                $moneySiteDomain,
                $orderId,
                $piId,
                $status,
                $user->token_secret ?? ''
            );
        }

        return response()->json(['status' => 'processed', 'event_type' => $eventType]);
    }

    /**
     * Push a payment status update to the money site's WooCommerce webhook handler.
     *
     * Money site plugin registers: ?wc-api=oneshield_webhook
     * Authenticates using the same HMAC-SHA256 scheme as all other Panel→MoneySite calls.
     * The money site IPN handler verifies with the token_secret stored in its gateway settings.
     */
    private function pushStatusToMoneySite(
        string $moneySiteDomain,
        string $orderId,
        string $gatewayTxnId,
        string $status,
        string $tokenSecret = ''
    ): void {
        $baseUrl    = 'https://' . ltrim($moneySiteDomain, '/');
        $webhookUrl = rtrim($baseUrl, '/') . '/?wc-api=oneshield_webhook';

        $payload = [
            'order_id'               => $orderId,
            'gateway_transaction_id' => $gatewayTxnId,
            'status'                 => $status,
        ];

        $timestamp = time();
        $message   = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $timestamp;
        $signature = hash_hmac('sha256', $message, $tokenSecret);

        try {
            Http::timeout(8)
                ->withHeaders([
                    'X-OneShield-Signature' => $signature,
                    'X-OneShield-Timestamp' => (string) $timestamp,
                    'X-OneShield-Token'     => $tokenSecret,
                    'Content-Type'          => 'application/json',
                ])
                ->post($webhookUrl, $payload);
        } catch (\Throwable $e) {
            Log::channel('webhooks')->warning('pushStatusToMoneySite failed', [
                'domain' => $moneySiteDomain,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
