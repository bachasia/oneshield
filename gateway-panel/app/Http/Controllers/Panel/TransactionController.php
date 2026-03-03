<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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
