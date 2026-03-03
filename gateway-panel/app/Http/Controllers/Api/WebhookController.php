<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MeshSite;
use App\Models\Transaction;
use App\Services\SiteRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private SiteRouterService $siteRouter) {}

    /**
     * Handle PayPal IPN/Webhook.
     * POST /api/webhook/paypal/{site_id}
     */
    public function paypal(Request $request, int $siteId): JsonResponse
    {
        $site = MeshSite::findOrFail($siteId);

        $payload = $request->all();
        Log::channel('webhooks')->info('PayPal webhook received', [
            'site_id' => $siteId,
            'payload' => $payload,
        ]);

        // PayPal IPN verification would go here in production
        // For now, we process based on payment_status field
        $txnId     = $payload['txn_id'] ?? $payload['id'] ?? null;
        $status    = $this->mapPaypalStatus($payload['payment_status'] ?? $payload['status'] ?? '');
        $orderId   = $payload['invoice'] ?? $payload['custom'] ?? null;

        if ($txnId && $orderId) {
            $transaction = Transaction::where('site_id', $siteId)
                ->where('order_id', $orderId)
                ->first();

            if ($transaction && $transaction->status === 'pending') {
                $transaction->update([
                    'status'                 => $status,
                    'gateway_transaction_id' => $txnId,
                    'raw_response'           => $payload,
                ]);

                if ($status === 'completed') {
                    $this->siteRouter->recordSuccess($site);
                }
            }
        }

        return response()->json(['status' => 'received']);
    }

    /**
     * Handle Stripe Webhook.
     * POST /api/webhook/stripe/{site_id}
     */
    public function stripe(Request $request, int $siteId): JsonResponse
    {
        $site = MeshSite::findOrFail($siteId);

        $payload = $request->all();
        Log::channel('webhooks')->info('Stripe webhook received', [
            'site_id' => $siteId,
            'event_type' => $payload['type'] ?? 'unknown',
        ]);

        $eventType = $payload['type'] ?? '';
        $object    = $payload['data']['object'] ?? [];

        if (in_array($eventType, ['payment_intent.succeeded', 'charge.succeeded'])) {
            $txnId   = $object['id'] ?? null;
            $orderId = $object['metadata']['order_id'] ?? null;

            if ($txnId && $orderId) {
                $transaction = Transaction::where('site_id', $siteId)
                    ->where('order_id', $orderId)
                    ->first();

                if ($transaction && $transaction->status === 'pending') {
                    $transaction->update([
                        'status'                 => 'completed',
                        'gateway_transaction_id' => $txnId,
                        'raw_response'           => $payload,
                    ]);

                    $this->siteRouter->recordSuccess($site);
                }
            }
        } elseif (in_array($eventType, ['payment_intent.payment_failed', 'charge.failed'])) {
            $txnId   = $object['id'] ?? null;
            $orderId = $object['metadata']['order_id'] ?? null;

            if ($txnId && $orderId) {
                Transaction::where('site_id', $siteId)
                    ->where('order_id', $orderId)
                    ->where('status', 'pending')
                    ->update(['status' => 'failed', 'raw_response' => $payload]);
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

    private function mapPaypalStatus(string $status): string
    {
        return match(strtolower($status)) {
            'completed' => 'completed',
            'refunded', 'reversed' => 'refunded',
            'denied', 'failed', 'expired', 'voided' => 'failed',
            default => 'pending',
        };
    }
}
