<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\ShieldSite;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $totalSites  = ShieldSite::where('user_id', $user->id)->count();
        $activeSites = ShieldSite::where('user_id', $user->id)->active()->count();

        $todayTransactions = Transaction::whereHas(
            'site', fn ($q) => $q->where('user_id', $user->id)
        )->whereDate('created_at', today())->count();

        $totalRevenue = Transaction::whereHas(
            'site', fn ($q) => $q->where('user_id', $user->id)
        )->where('status', 'completed')->sum('amount');

        $recentTransactions = Transaction::whereHas(
            'site', fn ($q) => $q->where('user_id', $user->id)
        )->with('site')->latest()->limit(10)->get();

        return Inertia::render('Dashboard', [
            'stats' => [
                'total_sites'        => $totalSites,
                'active_sites'       => $activeSites,
                'today_transactions' => $todayTransactions,
                'total_revenue'      => $totalRevenue,
            ],
            'recent_transactions' => $recentTransactions,
            'token_secret'        => $user->token_secret,
        ]);
    }
}
