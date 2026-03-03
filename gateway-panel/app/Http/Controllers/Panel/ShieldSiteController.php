<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\ShieldSite;
use App\Models\SiteGroup;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShieldSiteController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $sites = ShieldSite::where('user_id', $user->id)
            ->with('group')
            ->when($request->group_id, fn ($q) => $q->where('group_id', $request->group_id))
            ->when($request->status, fn ($q) => match($request->status) {
                'active'   => $q->where('is_active', true),
                'inactive' => $q->where('is_active', false),
                default    => $q,
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        // Attach gross received per site (paypal + stripe totals)
        $siteIds = $sites->pluck('id');
        $grossByGateway = Transaction::whereIn('site_id', $siteIds)
            ->where('status', 'completed')
            ->selectRaw('site_id, gateway, SUM(amount) as total')
            ->groupBy('site_id', 'gateway')
            ->get()
            ->groupBy('site_id');

        $sites->getCollection()->transform(function (ShieldSite $site) use ($grossByGateway) {
            $gateways = $grossByGateway->get($site->id, collect());
            $site->gross_paypal = (float) ($gateways->firstWhere('gateway', 'paypal')?->total ?? 0);
            $site->gross_stripe = (float) ($gateways->firstWhere('gateway', 'stripe')?->total ?? 0);
            return $site;
        });

        $groups = SiteGroup::where('user_id', $user->id)->get(['id', 'name']);

        // Summary stats for header
        $allSites = ShieldSite::where('user_id', $user->id)->get();
        $stats = [
            'live_paypal'    => $allSites->where('paypal_enabled', true)->where('paypal_mode', 'live')->count(),
            'live_stripe'    => $allSites->where('stripe_enabled', true)->where('stripe_mode', 'live')->count(),
            'test_paypal'    => $allSites->where('paypal_enabled', true)->where('paypal_mode', 'sandbox')->count(),
            'test_stripe'    => $allSites->where('stripe_enabled', true)->where('stripe_mode', 'test')->count(),
        ];

        return Inertia::render('Sites/Index', [
            'sites'   => $sites,
            'groups'  => $groups,
            'filters' => $request->only(['group_id', 'status']),
            'stats'   => $stats,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'url'      => 'required|url|max:500',
            'group_id' => 'nullable|integer|exists:site_groups,id',
        ]);

        $validated['user_id'] = $user->id;
        $validated['site_key'] = bin2hex(random_bytes(32));

        ShieldSite::create($validated);

        return redirect()->route('sites.index')->with('success', 'Mesh site added successfully.');
    }

    public function update(Request $request, ShieldSite $site): RedirectResponse
    {
        abort_if($site->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'name'                  => 'sometimes|string|max:255',
            'group_id'              => 'nullable|integer|exists:site_groups,id',
            // PayPal
            'paypal_client_id'      => 'nullable|string',
            'paypal_secret'         => 'nullable|string',
            'paypal_mode'           => 'nullable|in:sandbox,live',
            'paypal_enabled'        => 'nullable|boolean',
            'paypal_income_limit'   => 'nullable|numeric|min:0',
            'paypal_max_per_order'  => 'nullable|numeric|min:0',
            // Stripe
            'stripe_public_key'     => 'nullable|string',
            'stripe_secret_key'     => 'nullable|string',
            'stripe_mode'           => 'nullable|in:test,live',
            'stripe_enabled'        => 'nullable|boolean',
            'stripe_webhook_secret' => 'nullable|string',
            'stripe_income_limit'   => 'nullable|numeric|min:0',
            'stripe_max_per_order'  => 'nullable|numeric|min:0',
            // Spin
            'receive_cycle'         => 'nullable|in:lifetime,monthly,weekly,daily',
            // Status
            'is_active'             => 'sometimes|boolean',
        ]);

        // Don't overwrite encrypted fields with empty strings
        foreach (['paypal_client_id', 'paypal_secret', 'stripe_public_key', 'stripe_secret_key', 'stripe_webhook_secret'] as $field) {
            if (isset($validated[$field]) && $validated[$field] === '') {
                unset($validated[$field]);
            }
        }

        $site->update($validated);

        return back()->with('success', 'Site settings updated.');
    }

    public function destroy(Request $request, ShieldSite $site): RedirectResponse
    {
        abort_if($site->user_id !== $request->user()->id, 403);
        $site->delete();

        return redirect()->route('sites.index')->with('success', 'Site removed.');
    }

    public function toggle(Request $request, ShieldSite $site): RedirectResponse
    {
        abort_if($site->user_id !== $request->user()->id, 403);

        $site->update(['is_active' => !$site->is_active, 'failure_count' => 0]);

        return back()->with('success', 'Site status updated.');
    }

    /**
     * Check site connectivity (ping the shield site URL).
     */
    public function check(Request $request, ShieldSite $site): RedirectResponse
    {
        abort_if($site->user_id !== $request->user()->id, 403);

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)->get($site->url);
            $status   = $response->successful() ? 'reachable' : 'unreachable';
        } catch (\Throwable) {
            $status = 'unreachable';
        }

        return back()->with('success', "Site \"{$site->name}\" is {$status}.");
    }
}
