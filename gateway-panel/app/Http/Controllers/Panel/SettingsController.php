<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\GatewayToken;
use App\Services\HmacService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(private HmacService $hmacService) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $webhookBase = rtrim(config('app.url'), '/') . '/api/webhook';

        $appUrl = rtrim(config('app.url'), '/');

        return Inertia::render('Settings/Index', [
            'token_secret'   => $user->token_secret,
            'gateway_tokens' => $user->gatewayTokens()->get(['id', 'name', 'is_active', 'last_used_at', 'created_at']),
            'webhook_urls'   => [
                'paypal' => $webhookBase . '/paypal/{site_id}',
                'stripe' => $webhookBase . '/stripe/{site_id}',
            ],
            'plugins' => [
                [
                    'key'           => 'connect',
                    'name'          => 'OneShield Connect',
                    'description'   => 'Install on Shield Sites (payment processing sites)',
                    'version'       => config('oneshield.plugin_versions.connect', '1.0.0'),
                    'download_url'  => $appUrl . '/api/plugins/download/connect',
                ],
                [
                    'key'           => 'paygates',
                    'name'          => 'OneShield Paygates',
                    'description'   => 'Install on Money Sites (WooCommerce stores)',
                    'version'       => config('oneshield.plugin_versions.paygates', '1.0.0'),
                    'download_url'  => $appUrl . '/api/plugins/download/paygates',
                ],
            ],
        ]);
    }

    public function regenerateToken(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->update([
            'token_secret' => $this->hmacService->generateToken(64),
        ]);

        return back()->with('success', 'Token secret regenerated. Update your plugins with the new token.');
    }

    /**
     * Create a new gateway token (manual / for custom integrations).
     * POST /settings/tokens
     */
    public function createToken(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $rawToken = $this->hmacService->generateToken(40);

        GatewayToken::create([
            'user_id'   => $user->id,
            'name'      => $validated['name'],
            'token'     => $rawToken,  // stored raw; middleware compares directly
            'is_active' => true,
        ]);

        // Flash the raw token once — it cannot be retrieved again after this redirect
        return back()
            ->with('success', 'Gateway token created.')
            ->with('new_token', $rawToken);
    }

    /**
     * Revoke (soft-disable or delete) a gateway token.
     * DELETE /settings/tokens/{token}
     */
    public function revokeToken(Request $request, GatewayToken $token): RedirectResponse
    {
        abort_if($token->user_id !== $request->user()->id, 403);

        $token->update(['is_active' => false]);

        return back()->with('success', "Token \"{$token->name}\" revoked.");
    }
}
