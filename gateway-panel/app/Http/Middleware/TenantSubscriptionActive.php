<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class TenantSubscriptionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->is_super_admin) {
            if ($request->session()->has('impersonating_user_id')) {
                return $next($request);
            }

            return redirect('/admin')->with('error', 'Super admin can only access tenant panel via impersonation.');
        }

        $sub = $user->activeSubscription;

        // No subscription at all → block with upgrade prompt
        if (! $sub) {
            if ($request->inertia()) {
                return Inertia::render('Errors/SubscriptionRequired', [
                    'message' => 'No active subscription. Contact your administrator.',
                ])->toResponse($request)->setStatusCode(402);
            }

            abort(402, 'No active subscription.');
        }

        // Subscription explicitly suspended
        if ($sub->status === 'suspended') {
            if ($request->inertia()) {
                return Inertia::render('Errors/SubscriptionRequired', [
                    'message' => 'Your account has been suspended. Contact support.',
                    'status'  => 'suspended',
                ])->toResponse($request)->setStatusCode(402);
            }

            abort(402, 'Account suspended.');
        }

        // Expired
        if (! $sub->isActive()) {
            if ($request->inertia()) {
                return Inertia::render('Errors/SubscriptionRequired', [
                    'message'    => 'Your subscription has expired. Please renew to continue.',
                    'status'     => 'expired',
                    'expires_at' => $sub->expires_at?->toDateString(),
                ])->toResponse($request)->setStatusCode(402);
            }

            abort(402, 'Subscription expired.');
        }

        return $next($request);
    }
}
