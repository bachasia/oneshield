<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\SiteGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GroupController extends Controller
{
    public function index(Request $request): Response
    {
        $groups = SiteGroup::where('user_id', $request->user()->id)
            ->withCount('shieldSites')
            ->latest()
            ->get();

        return Inertia::render('Groups/Index', ['groups' => $groups]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $validated['user_id'] = $request->user()->id;
        SiteGroup::create($validated);

        return redirect()->route('groups.index')->with('success', 'Group created.');
    }

    public function update(Request $request, SiteGroup $group): RedirectResponse
    {
        abort_if($group->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $group->update($validated);

        return back()->with('success', 'Group updated.');
    }

    public function destroy(Request $request, SiteGroup $group): RedirectResponse
    {
        abort_if($group->user_id !== $request->user()->id, 403);
        $group->delete();

        return redirect()->route('groups.index')->with('success', 'Group deleted.');
    }
}
