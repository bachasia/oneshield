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
}
