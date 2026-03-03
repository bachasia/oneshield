<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HmacService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class SuperAdminController extends Controller
{
    public function __construct(private HmacService $hmacService) {}

    // ── Dashboard ────────────────────────────────────────────────────────

    public function dashboard(): Response
    {
        $tenants = User::where('is_super_admin', false)->with('activeSubscription.plan')->get();

        $stats = [
            'total_tenants'  => $tenants->count(),
            'active'         => $tenants->filter(fn ($u) => $u->activeSubscription?->isActive())->count(),
            'trial'          => $tenants->filter(fn ($u) => $u->activeSubscription?->status === 'trial')->count(),
            'suspended'      => $tenants->filter(fn ($u) => $u->activeSubscription?->status === 'suspended')->count(),
            'mrr'            => $tenants->sum(fn ($u) => $u->activeSubscription?->isActive()
                ? ($u->activeSubscription->plan->price_usd ?? 0)
                : 0),
        ];

        $recentTenants = User::where('is_super_admin', false)
            ->with('activeSubscription.plan')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($u) => $this->formatTenant($u));

        return Inertia::render('Admin/Dashboard', [
            'stats'         => $stats,
            'recentTenants' => $recentTenants,
        ]);
    }

    // ── Tenants List ─────────────────────────────────────────────────────

    public function tenants(Request $request): Response
    {
        $query = User::where('is_super_admin', false)
            ->with('activeSubscription.plan');

        if ($search = $request->input('search')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                                       ->orWhere('email', 'like', "%{$search}%")
                                       ->orWhere('tenant_id', 'like', "%{$search}%"));
        }

        if ($plan = $request->input('plan')) {
            $query->whereHas('activeSubscription.plan', fn ($q) => $q->where('name', $plan));
        }

        if ($status = $request->input('status')) {
            $query->whereHas('subscriptions', fn ($q) => $q->where('status', $status));
        }

        $tenants = $query->latest()->paginate(20)->withQueryString()
            ->through(fn ($u) => $this->formatTenant($u));

        return Inertia::render('Admin/Tenants/Index', [
            'tenants' => $tenants,
            'plans'   => Plan::active()->get(['id', 'name', 'label']),
            'filters' => $request->only(['search', 'plan', 'status']),
        ]);
    }

    // ── Create Tenant ────────────────────────────────────────────────────

    public function createTenant(): Response
    {
        return Inertia::render('Admin/Tenants/Create', [
            'plans' => Plan::active()->orderBy('price_usd')->get(),
        ]);
    }

    public function storeTenant(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|string|min:8',
            'tenant_id'  => 'required|string|alpha_dash|max:50|unique:users,tenant_id|not_in:admin,www,api,mail',
            'plan_id'    => 'required|exists:plans,id',
            'expires_at' => 'nullable|date|after:today',
            'notes'      => 'nullable|string|max:1000',
        ]);

        $tenant = User::create([
            'name'          => $validated['name'],
            'email'         => $validated['email'],
            'password'      => Hash::make($validated['password']),
            'tenant_id'     => strtolower($validated['tenant_id']),
            'token_secret'  => $this->hmacService->generateToken(64),
            'is_super_admin' => false,
        ]);

        // Create default gateway token
        $tenant->gatewayTokens()->create([
            'name'  => 'Default Token',
            'token' => $this->hmacService->generateToken(64),
        ]);

        $plan = Plan::find($validated['plan_id']);

        // Create subscription
        $tenant->subscriptions()->create([
            'plan_id'            => $validated['plan_id'],
            'status'             => $plan->name === 'trial' ? 'trial' : 'active',
            'expires_at'         => $validated['expires_at'] ?? null,
            'notes'              => $validated['notes'] ?? null,
            'created_by_admin_id' => $request->user()->id,
        ]);

        return redirect()->route('admin.tenants.show', $tenant)
            ->with('success', "Tenant \"{$tenant->name}\" created at {$tenant->tenant_id}.oneshieldx.com");
    }

    // ── Show Tenant ──────────────────────────────────────────────────────

    public function showTenant(User $tenant): Response
    {
        abort_if($tenant->is_super_admin, 404);

        $tenant->load('activeSubscription.plan', 'subscriptions.plan', 'subscriptions.createdByAdmin');

        $stats = [
            'shield_sites'      => $tenant->shieldSites()->count(),
            'transactions_total' => Transaction::whereHas('site', fn ($q) => $q->where('user_id', $tenant->id))->count(),
            'volume_30d'        => Transaction::whereHas('site', fn ($q) => $q->where('user_id', $tenant->id))
                ->where('status', 'completed')
                ->where('created_at', '>=', now()->subDays(30))
                ->sum('amount'),
        ];

        return Inertia::render('Admin/Tenants/Show', [
            'tenant'  => $this->formatTenant($tenant),
            'history' => $tenant->subscriptions->map(fn ($s) => [
                'id'           => $s->id,
                'plan'         => $s->plan?->label,
                'status'       => $s->status,
                'expires_at'   => $s->expires_at?->toDateString(),
                'notes'        => $s->notes,
                'created_by'   => $s->createdByAdmin?->name ?? 'System',
                'created_at'   => $s->created_at->toDateTimeString(),
            ]),
            'plans'  => Plan::active()->orderBy('price_usd')->get(),
            'stats'  => $stats,
        ]);
    }

    // ── Update Subscription ──────────────────────────────────────────────

    public function updateSubscription(Request $request, User $tenant): RedirectResponse
    {
        abort_if($tenant->is_super_admin, 404);

        $validated = $request->validate([
            'plan_id'    => 'required|exists:plans,id',
            'status'     => 'required|in:trial,active,suspended,expired',
            'expires_at' => 'nullable|date',
            'notes'      => 'nullable|string|max:1000',
        ]);

        // Expire current active subscription
        $tenant->subscriptions()
               ->whereIn('status', ['active', 'trial'])
               ->update(['status' => 'expired']);

        // Create new subscription record (audit trail)
        $tenant->subscriptions()->create([
            'plan_id'            => $validated['plan_id'],
            'status'             => $validated['status'],
            'expires_at'         => $validated['expires_at'] ?? null,
            'notes'              => $validated['notes'] ?? null,
            'created_by_admin_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Subscription updated.');
    }

    // ── Suspend / Unsuspend ──────────────────────────────────────────────

    public function suspendTenant(Request $request, User $tenant): RedirectResponse
    {
        abort_if($tenant->is_super_admin, 404);

        $tenant->subscriptions()
               ->whereIn('status', ['active', 'trial'])
               ->update(['status' => 'suspended']);

        return back()->with('success', "Tenant \"{$tenant->name}\" suspended.");
    }

    public function unsuspendTenant(Request $request, User $tenant): RedirectResponse
    {
        abort_if($tenant->is_super_admin, 404);

        // Reactivate the most recent subscription
        $sub = $tenant->subscriptions()->where('status', 'suspended')->latest()->first();

        if ($sub) {
            $sub->update(['status' => 'active']);
        }

        return back()->with('success', "Tenant \"{$tenant->name}\" reactivated.");
    }

    // ── Impersonate ──────────────────────────────────────────────────────

    public function impersonate(Request $request, User $tenant): RedirectResponse
    {
        abort_if($tenant->is_super_admin, 403);

        $request->session()->put('impersonating_user_id', $request->user()->id);
        Auth::login($tenant);

        return redirect('/dashboard')->with('success', "Viewing as {$tenant->name}");
    }

    public function stopImpersonating(Request $request): RedirectResponse
    {
        $adminId = $request->session()->pull('impersonating_user_id');

        if ($adminId) {
            $admin = User::find($adminId);
            if ($admin) {
                Auth::login($admin);
            }
        }

        return redirect('/admin')->with('success', 'Returned to Super Admin panel.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function formatTenant(User $user): array
    {
        $sub  = $user->activeSubscription;
        $plan = $sub?->plan;

        return [
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'tenant_id'    => $user->tenant_id,
            'subdomain_url' => "https://{$user->tenant_id}.oneshieldx.com",
            'plan'         => $plan ? [
                'name'             => $plan->name,
                'label'            => $plan->label,
                'price_usd'        => $plan->price_usd,
                'max_shield_sites' => $plan->max_shield_sites,
            ] : null,
            'subscription_status' => $sub?->statusLabel() ?? 'No subscription',
            'expires_at'   => $sub?->expires_at?->toDateString(),
            'sites_used'   => $user->shieldSites()->count(),
            'created_at'   => $user->created_at->toDateString(),
        ];
    }
}
