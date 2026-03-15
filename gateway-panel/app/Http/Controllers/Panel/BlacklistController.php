<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\BlacklistEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BlacklistController extends Controller
{
    /**
     * Display blacklist entries with stats.
     * GET /blacklist
     */
    public function index(Request $request): Response
    {
        $query = BlacklistEntry::query();

        // Filter by type
        if ($request->type && in_array($request->type, ['email', 'address'])) {
            $query->where('type', $request->type);
        }

        // Filter by source
        if ($request->source && in_array($request->source, ['pgprints', 'custom'])) {
            $query->where('source', $request->source);
        }

        $entries = $query->latest()->paginate(50)->withQueryString();

        // Stats
        $stats = [
            'total'    => BlacklistEntry::count(),
            'pgprints' => BlacklistEntry::where('source', 'pgprints')->count(),
            'custom'   => BlacklistEntry::where('source', 'custom')->count(),
            'emails'   => BlacklistEntry::where('type', 'email')->count(),
            'addresses' => BlacklistEntry::where('type', 'address')->count(),
        ];

        $lastImport = BlacklistEntry::where('source', 'pgprints')
            ->latest('updated_at')
            ->first()
            ?->updated_at
            ?->toIso8601String();

        return Inertia::render('Blacklist/Index', [
            'entries'    => $entries,
            'stats'      => $stats,
            'lastImport' => $lastImport,
            'filters'    => $request->only(['type', 'source']),
        ]);
    }

    /**
     * Store a new custom blacklist entry.
     * POST /blacklist
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type'  => 'required|in:email,address',
            'value' => 'required|string|max:500',
            'notes' => 'nullable|string|max:500',
        ]);

        $value = strtolower(trim($validated['value']));

        if ($validated['type'] === 'address') {
            $value = app(\App\Services\BlacklistService::class)->normalizeAddress($value);
        }

        BlacklistEntry::firstOrCreate(
            ['type' => $validated['type'], 'value' => $value],
            ['source' => 'custom', 'notes' => $validated['notes'] ?? null]
        );

        return back()->with('success', 'Blacklist entry added.');
    }

    /**
     * Delete a custom blacklist entry (pgprints entries are protected).
     * DELETE /blacklist/{entry}
     */
    public function destroy(BlacklistEntry $entry): RedirectResponse
    {
        if ($entry->source !== 'custom') {
            return back()->with('error', 'Cannot delete pgprints entries.');
        }

        $entry->delete();

        return back()->with('success', 'Entry removed.');
    }
}
