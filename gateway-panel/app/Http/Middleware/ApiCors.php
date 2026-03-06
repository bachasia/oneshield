<?php

namespace App\Http\Middleware;

use App\Models\GatewayToken;
use App\Models\ShieldSite;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS middleware for API routes.
 *
 * Allows money site domains (configured via ONESHIELD_CORS_ORIGINS)
 * to call the Gateway Panel API from the browser.
 */
class ApiCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedOrigins = $this->resolveAllowedOrigins($request);
        $origin = $request->header('Origin', '');

        // Determine if this origin is allowed
        $allow = false;
        if (in_array('*', $allowedOrigins, true)) {
            $allow = true;
            $originHeader = '*';
        } elseif ($origin && in_array($origin, $allowedOrigins, true)) {
            $allow = true;
            $originHeader = $origin;
        }

        // Handle preflight OPTIONS request
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
            if ($allow) {
                $response->headers->set('Access-Control-Allow-Origin',      $originHeader);
                $response->headers->set('Access-Control-Allow-Methods',     'GET, POST, PUT, PATCH, DELETE, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers',     'Content-Type, Authorization, X-OneShield-Signature, X-OneShield-Timestamp, X-OneShield-Token, X-Requested-With');
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Access-Control-Max-Age',           '86400');
            }
            return $response;
        }

        $response = $next($request);

        if ($allow) {
            $response->headers->set('Access-Control-Allow-Origin',      $originHeader);
            $response->headers->set('Access-Control-Allow-Methods',     'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers',     'Content-Type, Authorization, X-OneShield-Signature, X-OneShield-Timestamp, X-OneShield-Token, X-Requested-With');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Vary',                             'Origin');
        }

        return $response;
    }

    private function resolveAllowedOrigins(Request $request): array
    {
        $origin = (string) $request->header('Origin', '');
        $tokenValue = (string) $request->header('X-OneShield-Token', '');

        if ($tokenValue !== '') {
            $user = $this->resolveUserFromToken($tokenValue);

            if ($user) {
                $tenantOrigins = array_values(array_filter($user->cors_origins ?? []));
                if (!empty($tenantOrigins)) {
                    return $tenantOrigins;
                }
            }

            // Tenant token provided but no whitelist configured: block by default.
            return [];
        }

        // Preflight requests don't include token value. Allow only origins that
        // appear in at least one tenant whitelist.
        if ($origin !== '' && User::whereJsonContains('cors_origins', $origin)->exists()) {
            return [$origin];
        }

        // No tenant match: block by default.
        return [];
    }

    private function resolveUserFromToken(string $tokenValue): ?User
    {
        $user = User::where('token_secret', $tokenValue)->first();
        if ($user) {
            return $user;
        }

        $gatewayToken = GatewayToken::where('token', $tokenValue)
            ->where('is_active', true)
            ->first();
        if ($gatewayToken) {
            return $gatewayToken->user;
        }

        $site = ShieldSite::where('site_key', $tokenValue)->first();
        if ($site) {
            return $site->user;
        }

        return null;
    }
}
