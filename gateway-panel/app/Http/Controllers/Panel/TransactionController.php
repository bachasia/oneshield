<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $transactions = Transaction::whereHas(
            'site', fn ($q) => $q->where('user_id', $user->id)
        )
            ->with('site')
            ->when($request->site_id, fn ($q) => $q->where('site_id', $request->site_id))
            ->when($request->gateway, fn ($q) => $q->where('gateway', $request->gateway))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'filters'      => $request->only(['site_id', 'gateway', 'status', 'date_from', 'date_to']),
        ]);
    }

    public function show(Request $request, Transaction $transaction): Response
    {
        abort_if($transaction->site->user_id !== $request->user()->id, 403);

        return Inertia::render('Transactions/Show', [
            'transaction' => $transaction->load('site'),
        ]);
    }

    /**
     * Issue a refund for a completed Stripe transaction.
     *
     * POST /transactions/{transaction}/refund
     *
     * Flow: Panel → shield site AJAX osc_stripe_refund → Stripe Refunds API
     */
    public function refund(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();
        abort_if($transaction->site->user_id !== $user->id, 403);

        if ($transaction->status !== 'completed') {
            return response()->json(['error' => 'Only completed transactions can be refunded.'], 422);
        }

        if ($transaction->gateway !== 'stripe') {
            return response()->json(['error' => 'Manual refund is only supported for Stripe transactions.'], 422);
        }

        $piId = $transaction->gateway_transaction_id;
        if (empty($piId)) {
            return response()->json(['error' => 'No Stripe PaymentIntent ID on this transaction.'], 422);
        }

        $site    = $transaction->site;
        $ajaxUrl = rtrim($site->url, '/') . '/wp-admin/admin-ajax.php';

        $relayPayload = [
            'action'   => 'osc_stripe_refund',
            'pi_id'    => $piId,
            'site_key' => $site->site_key,
            'reason'   => 'requested_by_customer',
        ];

        $relayResp = Http::timeout(20)->asForm()->post($ajaxUrl, $relayPayload);

        if ($relayResp->failed()) {
            return response()->json([
                'error' => 'Shield site did not respond (HTTP ' . $relayResp->status() . ').',
            ], 502);
        }

        $body = $relayResp->json();

        if (empty($body['success'])) {
            $msg = is_string($body['data'] ?? null) ? $body['data'] : 'Refund failed on shield site.';
            return response()->json(['error' => $msg], 422);
        }

        // Mark transaction as refunded
        $transaction->update(['status' => 'refunded']);

        return response()->json([
            'success'   => true,
            'refund_id' => $body['data']['refund_id'] ?? '',
            'status'    => $body['data']['status']    ?? 'succeeded',
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        $user = $request->user();

        $transactions = Transaction::whereHas(
            'site', fn ($q) => $q->where('user_id', $user->id)
        )
            ->with('site')
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->latest()
            ->get();

        $csv = "ID,Order ID,Amount,Currency,Gateway,Status,Site,Domain,Date\n";
        foreach ($transactions as $tx) {
            $csv .= implode(',', [
                $tx->id,
                $tx->order_id,
                $tx->amount,
                $tx->currency,
                $tx->gateway,
                $tx->status,
                $tx->site->name ?? '',
                $tx->money_site_domain,
                $tx->created_at->toDateTimeString(),
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="transactions-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }
}
