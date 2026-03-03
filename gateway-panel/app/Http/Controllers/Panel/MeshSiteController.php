<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\MeshSite;
use App\Models\SiteGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MeshSiteController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $sites = MeshSite::where('user_id', $user->id)
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

        $groups = SiteGroup::where('user_id', $user->id)->get(['id', 'name']);

        return Inertia::render('Sites/Index', [
            'sites'   => $sites,
            'groups'  => $groups,
            'filters' => $request->only(['group_id', 'status']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'url'           => 'required|url|max:500',
            'group_id'      => 'nullable|integer|exists:site_groups,id',
            'paypal_client_id' => 'nullable|string',
            'paypal_secret'    => 'nullable|string',
            'paypal_mode'      => 'nullable|in:sandbox,live',
            'stripe_public_key' => 'nullable|string',
            'stripe_secret_key' => 'nullable|string',
            'stripe_mode'       => 'nullable|in:test,live',
        ]);

        $validated['user_id'] = $user->id;
        $validated['site_key'] = bin2hex(random_bytes(32));

        MeshSite::create($validated);

        return redirect()->route('sites.index')->with('success', 'Mesh site added successfully.');
    }

    public function update(Request $request, MeshSite $site): RedirectResponse
    {
        abort_if($site->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'group_id'         => 'nullable|integer|exists:site_groups,id',
            'paypal_client_id' => 'nullable|string',
            'paypal_secret'    => 'nullable|string',
            'paypal_mode'      => 'nullable|in:sandbox,live',
            'stripe_public_key' => 'nullable|string',
            'stripe_secret_key' => 'nullable|string',
            'stripe_mode'       => 'nullable|in:test,live',
            'is_active'        => 'sometimes|boolean',
        ]);

        $site->update($validated);

        return back()->with('success', 'Site settings updated.');
    }

    public function destroy(Request $request, MeshSite $site): RedirectResponse
    {
        abort_if($site->user_id !== $request->user()->id, 403);
        $site->delete();

        return redirect()->route('sites.index')->with('success', 'Site removed.');
    }

    public function toggle(Request $request, MeshSite $site): RedirectResponse
    {
        abort_if($site->user_id !== $request->user()->id, 403);

        $site->update(['is_active' => !$site->is_active, 'failure_count' => 0]);

        return back()->with('success', 'Site status updated.');
    }
}
