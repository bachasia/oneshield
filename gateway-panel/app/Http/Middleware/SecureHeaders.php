<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers middleware.
 *
 * Applied to all web routes (panel + checkout iframes).
 * CSP is relaxed enough to allow Stripe / PayPal SDKs inside iframes.
 */
class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only add to HTML responses
        $contentType = $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        // X-Content-Type-Options
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // X-Frame-Options — panel pages should NOT be embeddable
        // Exception: checkout pages (/?fe-checkout=1) need to be in an iframe
        $isCheckout = str_contains($request->getRequestUri(), 'fe-checkout');
        if (!$isCheckout) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        // Referrer-Policy
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Content-Security-Policy
        if ($isCheckout) {
            // Checkout iframe: allow Stripe + PayPal JS SDKs
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' https://js.stripe.com https://www.paypal.com https://www.paypalobjects.com https://pay.google.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com",
                "frame-src https://js.stripe.com https://hooks.stripe.com https://www.paypal.com https://checkout.paypal.com",
                "connect-src 'self' https://api.stripe.com https://www.paypal.com https://api.paypal.com",
                "img-src 'self' data: https://www.paypalobjects.com",
            ]);
        } else {
            // Panel pages: strict CSP
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline'",  // Vite injects inline scripts
                "style-src 'self' 'unsafe-inline'",
                "font-src 'self' data:",
                "img-src 'self' data:",
                "frame-src 'none'",
                "object-src 'none'",
                "base-uri 'self'",
            ]);
        }

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
