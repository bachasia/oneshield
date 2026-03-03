<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
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

        return Inertia::render('Settings/Index', [
            'token_secret'  => $user->token_secret,
            'gateway_tokens' => $user->gatewayTokens()->get(['id', 'name', 'is_active', 'last_used_at', 'created_at']),
            'webhook_urls'  => [
                'paypal' => $webhookBase . '/paypal/{site_id}',
                'stripe' => $webhookBase . '/stripe/{site_id}',
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
}
