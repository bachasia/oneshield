<?php

namespace App\Http\Middleware;

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
        $allowedOrigins = config('oneshield.cors_origins', ['*']);
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
}
