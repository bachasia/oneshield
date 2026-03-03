<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShieldSite;
use App\Services\HmacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConnectController extends Controller
{
    public function __construct(private HmacService $hmacService) {}

    /**
     * Register a new shield site.
     * POST /api/connect/register
     *
     * Called by the oneshield-connect plugin on first setup.
     */
    public function register(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'site_url'  => 'required|url|max:500',
            'site_name' => 'required|string|max:255',
        ]);

        // Check if this site URL is already registered for this user
        $existing = ShieldSite::where('user_id', $user->id)
            ->where('url', $validated['site_url'])
            ->first();

        if ($existing) {
            return response()->json([
                'site_id'  => $existing->id,
                'site_key' => $existing->site_key,
                'status'   => 'already_registered',
            ]);
        }

        $site = ShieldSite::create([
            'user_id'  => $user->id,
            'name'     => $validated['site_name'],
            'url'      => rtrim($validated['site_url'], '/'),
            'site_key' => $this->hmacService->generateToken(64),
            'is_active' => true,
            'last_heartbeat_at' => now(),
        ]);

        return response()->json([
            'site_id'  => $site->id,
            'site_key' => $site->site_key,
            'status'   => 'registered',
        ], 201);
    }

    /**
     * Heartbeat ping from shield site.
     * POST /api/connect/heartbeat
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'site_id' => 'required|integer',
        ]);

        $site = ShieldSite::where('id', $validated['site_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        $site->update(['last_heartbeat_at' => now()]);

        // Build credentials payload — only include keys when gateway is enabled & configured
        $credentials = [];

        if ($site->stripe_enabled && $site->hasGatewayCredentials('stripe')) {
            $credentials['stripe'] = [
                'public_key'  => $site->stripe_public_key,
                'secret_key'  => $site->stripe_secret_key,
                'mode'        => $site->stripe_mode,
            ];
        }

        if ($site->paypal_enabled && $site->hasGatewayCredentials('paypal')) {
            $credentials['paypal'] = [
                'client_id'     => $site->paypal_client_id,
                'client_secret' => $site->paypal_secret,
                'mode'          => $site->paypal_mode,
            ];
        }

        return response()->json([
            'status' => 'ok',
            'config' => [
                'paypal_mode'  => $site->paypal_mode,
                'stripe_mode'  => $site->stripe_mode,
                'is_active'    => $site->is_active,
            ],
            'credentials' => $credentials,
        ]);
    }

    /**
     * Get site configuration.
     * GET /api/connect/status/{site_id}
     */
    public function status(Request $request, int $siteId): JsonResponse
    {
        $user = $request->user();

        $site = ShieldSite::where('id', $siteId)
            ->where('user_id', $user->id)
            ->with('group')
            ->firstOrFail();

        return response()->json([
            'site' => [
                'id'        => $site->id,
                'name'      => $site->name,
                'url'       => $site->url,
                'is_active' => $site->is_active,
                'group'     => $site->group ? ['id' => $site->group->id, 'name' => $site->group->name] : null,
            ],
            'gateways_configured' => [
                'paypal'  => $site->supportsGateway('paypal'),
                'stripe'  => $site->supportsGateway('stripe'),
                'airwallex' => $site->supportsGateway('airwallex'),
            ],
            'modes' => [
                'paypal' => $site->paypal_mode,
                'stripe' => $site->stripe_mode,
            ],
        ]);
    }
}
