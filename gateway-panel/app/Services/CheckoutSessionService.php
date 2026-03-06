<?php

namespace App\Services;

use App\Models\CheckoutSession;
use App\Models\ShieldSite;
use App\Models\User;
use Illuminate\Support\Str;

class CheckoutSessionService
{
    /**
     * TTL for a new checkout session in minutes.
     */
    private int $ttlMinutes;

    public function __construct()
    {
        $this->ttlMinutes = (int) config('oneshield.checkout_session.ttl_minutes', 30);
    }

    /**
     * Create a new checkout session.
     *
     * @param  User        $user       Authenticated panel user (tenant)
     * @param  ShieldSite  $site       The shield site that will handle the payment
     * @param  array       $data {
     *     @var string  gateway
     *     @var string  order_ref
     *     @var int     amount_minor       Amount in smallest unit (e.g. cents)
     *     @var string  currency           ISO 4217 lowercase
     *     @var string  amount_display     Human-readable string, e.g. "19.99"
     *     @var string  mode               live|test
     *     @var string  capture_method     automatic|manual
     *     @var bool    enable_wallets
     *     @var string|null  descriptor
     *     @var string|null  description_format
     *     @var array|null   billing        Billing data (will be encrypted)
     *     @var string|null  idempotency_key
     *     @var array|null   meta
     * }
     */
    public function create(User $user, ShieldSite $site, array $data): CheckoutSession
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;

        // Idempotency: return existing active session for the same key
        if ($idempotencyKey) {
            $existing = CheckoutSession::where('idempotency_key', $idempotencyKey)
                ->where('user_id', $user->id)
                ->active()
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return CheckoutSession::create([
            'id'                 => (string) Str::uuid(),
            'user_id'            => $user->id,
            'site_id'            => $site->id,
            'gateway'            => $data['gateway'],
            'order_ref'          => $data['order_ref'],
            'amount_minor'       => $data['amount_minor'],
            'currency'           => strtolower($data['currency']),
            'amount_display'     => $data['amount_display'],
            'mode'               => $data['mode'] ?? 'live',
            'capture_method'     => $data['capture_method'] ?? 'automatic',
            'enable_wallets'     => $data['enable_wallets'] ?? true,
            'descriptor'         => isset($data['descriptor']) ? substr($data['descriptor'], 0, 22) : null,
            'description_format' => $data['description_format'] ?? null,
            'billing_snapshot'   => !empty($data['billing']) ? $data['billing'] : null,
            'idempotency_key'    => $idempotencyKey,
            'status'             => CheckoutSession::STATUS_CREATED,
            'expires_at'         => now()->addMinutes($this->ttlMinutes),
            'meta'               => $data['meta'] ?? null,
        ]);
    }

    /**
     * Resolve a session by ID for a given site (called by shield-site plugin).
     *
     * Validates:
     *  - Session exists
     *  - Session not expired
     *  - site_id matches (tenant isolation)
     *  - Status is usable
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \RuntimeException on validation failure
     */
    public function resolve(string $checkoutId, int $siteId): CheckoutSession
    {
        $session = CheckoutSession::findOrFail($checkoutId);

        if ($session->site_id !== $siteId) {
            throw new \RuntimeException('checkout_session_site_mismatch', 403);
        }

        if ($session->expires_at->isPast()) {
            if ($session->status === CheckoutSession::STATUS_CREATED) {
                $session->markExpired();
            }
            throw new \RuntimeException('checkout_session_expired', 410);
        }

        if (!$session->isUsable()) {
            throw new \RuntimeException('checkout_session_not_usable', 422);
        }

        return $session;
    }

    /**
     * Refresh amount/currency on an existing active session (before payment confirmation).
     * Also resets status to "created" so a new payment intent can be created.
     */
    public function refresh(CheckoutSession $session, int $amountMinor, string $currency, string $amountDisplay): CheckoutSession
    {
        $session->update([
            'amount_minor'   => $amountMinor,
            'currency'       => strtolower($currency),
            'amount_display' => $amountDisplay,
            'status'         => CheckoutSession::STATUS_CREATED,
        ]);

        $session->refresh();
        return $session;
    }

    /**
     * Mark session as complete (called after successful webhook / payment).
     */
    public function complete(CheckoutSession $session, string $gatewayTxnId, ?string $stripePaymentIntentId = null): CheckoutSession
    {
        $session->markCompleted($gatewayTxnId, $stripePaymentIntentId);
        $session->refresh();
        return $session;
    }

    /**
     * Expire stale sessions (cron job).
     * Returns the number of sessions expired.
     */
    public function expireStale(): int
    {
        return CheckoutSession::expired()->update([
            'status' => CheckoutSession::STATUS_EXPIRED,
        ]);
    }

    /**
     * Build the iframe URL using a checkout_id (replaces buildIframeUrl).
     */
    public function buildIframeUrl(ShieldSite $site, string $checkoutId): string
    {
        return rtrim($site->url, '/') . '/?' . http_build_query([
            'os-checkout'  => '1',
            'checkout_id'  => $checkoutId,
        ]);
    }
}
