<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ? [
                    'id'           => $request->user()->id,
                    'name'         => $request->user()->name,
                    'email'        => $request->user()->email,
                    'tenant_id'    => $request->user()->tenant_id,
                    'token_secret' => $request->user()->token_secret,
                ] : null,
            ],
            'flash' => [
                'success'   => fn () => $request->session()->get('success'),
                'error'     => fn () => $request->session()->get('error'),
                'new_token' => fn () => $request->session()->get('new_token'),
            ],
            'subscription' => fn () => $request->user() && ! $request->user()->is_super_admin
                ? (function () use ($request) {
                    $user = $request->user();
                    $sub  = $user->activeSubscription;
                    return [
                        'plan'        => $sub?->plan?->only(['name', 'label', 'max_shield_sites', 'price_usd']),
                        'status'      => $sub?->status,
                        'expires_at'  => $sub?->expires_at?->toDateString(),
                        'sites_used'  => $user->shieldSites()->count(),
                        'sites_limit' => $user->shieldSiteLimit(),
                    ];
                })()
                : null,
            'is_super_admin' => fn () => (bool) $request->user()?->is_super_admin,
            'impersonating'  => fn () => $request->session()->has('impersonating_user_id'),
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ]);
    }
}
