<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $host      = $request->getHost();                       // e.g. zidoecom.oneshieldx.com
        $appHost   = config('app.host', 'oneshieldx.com');       // base domain

        // Extract subdomain
        $subdomain = $this->extractSubdomain($host, $appHost);

        // No subdomain (bare domain) or admin subdomain → skip, handled elsewhere
        if ($subdomain === null || $subdomain === 'admin') {
            return $next($request);
        }

        // Resolve tenant by tenant_id
        $tenant = User::where('tenant_id', $subdomain)
                      ->where('is_super_admin', false)
                      ->first();

        if (! $tenant) {
            abort(404, 'Tenant not found.');
        }

        // Bind tenant onto request so controllers can use $request->tenant()
        $request->macro('tenant', fn () => $tenant);
        // Also set the macro as an attribute for middleware chain access
        $request->attributes->set('_tenant', $tenant);

        return $next($request);
    }

    private function extractSubdomain(string $host, string $appHost): ?string
    {
        // Strip port if present (e.g. localhost:8080)
        $host = explode(':', $host)[0];

        // localhost or IP in dev → no subdomain
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        // Remove base domain suffix
        $suffix = '.' . ltrim($appHost, '.');
        if (str_ends_with($host, $suffix)) {
            $sub = substr($host, 0, strlen($host) - strlen($suffix));
            // Only single-level subdomain
            if ($sub && ! str_contains($sub, '.')) {
                return $sub;
            }
        }

        return null;
    }
}
