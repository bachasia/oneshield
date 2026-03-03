<?php

namespace App\Http\Middleware;

use App\Models\GatewayToken;
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

        // Resolve the token → find associated user
        $gatewayToken = GatewayToken::where('token', $tokenValue)
            ->active()
            ->first();

        if (!$gatewayToken) {
            // Also check user.token_secret (primary token)
            $user = User::where('token_secret', $tokenValue)->first();
            if (!$user) {
                return response()->json(['error' => 'Invalid token'], 401);
            }
            $tokenSecret = $tokenValue; // token_secret is the key itself
        } else {
            $user = $gatewayToken->user;
            $tokenSecret = $gatewayToken->token;
            $gatewayToken->update(['last_used_at' => now()]);
        }

        $payload = $request->all();
        $valid = $this->hmacService->verify(
            $payload,
            $signature,
            (int) $timestamp,
            $tokenSecret
        );

        if (!$valid) {
            return response()->json(['error' => 'Invalid or expired signature'], 401);
        }

        // Bind the authenticated user for downstream controllers
        $request->merge(['_authenticated_user' => $user]);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
