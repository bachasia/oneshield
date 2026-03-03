<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate-limit API requests per token (not per IP).
 *
 * Default: 100 requests/minute per token value.
 */
class ThrottlePerToken
{
    public function __construct(private RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next, int $maxAttempts = 0): Response
    {
        if ($maxAttempts === 0) {
            $maxAttempts = (int) config('oneshield.rate_limits.api_per_token', 100);
        }

        $token = $request->header('X-OneShield-Token', $request->ip());
        $key   = 'api_token:' . sha1($token);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = $this->limiter->availableIn($key);
            return response()->json([
                'error'       => 'Too many requests',
                'retry_after' => $retryAfter,
            ], 429)->withHeaders([
                'Retry-After'               => $retryAfter,
                'X-RateLimit-Limit'         => $maxAttempts,
                'X-RateLimit-Remaining'     => 0,
            ]);
        }

        $this->limiter->hit($key, 60); // 60-second window

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit',     $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', max(0, $maxAttempts - $this->limiter->attempts($key)));

        return $response;
    }
}
