<?php

namespace App\Http\Middleware;

use App\Models\GatewayToken;
use App\Models\ShieldSite;
use App\Models\User;
use App\Services\HmacService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HmacAuthentication
{
    public function __construct(private HmacService $hmacService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-OneShield-Signature');
        $timestamp  = $request->header('X-OneShield-Timestamp');
        $tokenValue = $request->header('X-OneShield-Token');

        if (!$signature || !$timestamp || !$tokenValue) {
            return response()->json([
                'error' => 'Missing authentication headers',
                'required' => ['X-OneShield-Signature', 'X-OneShield-Timestamp', 'X-OneShield-Token'],
            ], 401);
        }

        $user        = null;
        $tokenSecret = null;

        // 1. Check named gateway tokens (GatewayToken table)
        $gatewayToken = GatewayToken::where('token', $tokenValue)->active()->first();
        if ($gatewayToken) {
            $user        = $gatewayToken->user;
            $tokenSecret = $gatewayToken->token;
            $gatewayToken->update(['last_used_at' => now()]);
        }

        // 2. Check user.token_secret (primary token used by oneshield-paygates plugin)
        if (!$user) {
            $foundUser = User::where('token_secret', $tokenValue)->first();
            if ($foundUser) {
                $user        = $foundUser;
                $tokenSecret = $tokenValue;
            }
        }

        // 3. Check shield site site_key (used by oneshield-connect plugin heartbeat)
        if (!$user) {
            $site = ShieldSite::where('site_key', $tokenValue)->first();
            if ($site) {
                $user        = $site->user;
                $tokenSecret = $tokenValue;
                // Store site_id for controllers that need it (e.g. CheckoutSessionController::resolve)
                $request->attributes->set('site_id', $site->id);
            }
        }

        if (!$user) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        // Use raw body for HMAC verification to avoid json_encode discrepancies
        // when re-encoding $request->all() (float precision, key ordering, special chars).
        // The PHP plugin signs json_encode($payload) directly, so we must verify
        // against the same raw JSON string.
        $rawBody = $request->getContent();
        $valid = $this->hmacService->verifyRaw(
            $rawBody,
            $signature,
            (int) $timestamp,
            $tokenSecret
        );

        if (!$valid) {
            return response()->json(['error' => 'Invalid or expired signature'], 401);
        }

        // Validate subdomain matches tenant — only enforced when subdomain routing is active
        // (i.e. not on localhost/IP and not on the admin subdomain)
        $tenant = $request->attributes->get('_tenant');
        if ($tenant !== null && $tenant->id !== $user->id) {
            return response()->json([
                'error' => 'Token does not belong to this gateway endpoint.',
            ], 403);
        }

        // Bind the authenticated user for downstream controllers
        $request->merge(['_authenticated_user' => $user]);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
