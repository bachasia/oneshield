<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\BlacklistEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BlacklistController extends Controller
{
    /**
     * Display blacklist editor with all entries grouped by type as textarea strings.
     * GET /blacklist
     */
    public function index(): Response
    {
        $userId = auth()->id();

        $join = fn (string $type): string => BlacklistEntry::where('is_system', false)
            ->where('user_id', $userId)
            ->where('type', $type)
            ->orderBy('value')
            ->pluck('value')
            ->implode("\n");

        return Inertia::render('Blacklist/Index', [
            'emails'               => $join('email'),
            'cities'               => $join('city'),
            'states'               => $join('state'),
            'zipcodes'             => $join('zipcode'),
            'use_system_blacklist' => (bool) auth()->user()->use_system_blacklist,
        ]);
    }

    /**
     * Replace all entries for this user for each type with submitted newline-separated values.
     * POST /blacklist/save
     */
    public function save(Request $request): RedirectResponse
    {
        $request->validate([
            'emails'   => 'nullable|string',
            'cities'   => 'nullable|string',
            'states'   => 'nullable|string',
            'zipcodes' => 'nullable|string',
        ]);

        $userId = auth()->id();

        $types = [
            'email'   => $request->input('emails', ''),
            'city'    => $request->input('cities', ''),
            'state'   => $request->input('states', ''),
            'zipcode' => $request->input('zipcodes', ''),
        ];

        foreach ($types as $type => $raw) {
            // Parse lines: trim, lowercase, filter empty
            $values = array_values(array_filter(
                array_map(fn ($v) => strtolower(trim($v)), explode("\n", $raw ?? '')),
                fn ($v) => $v !== ''
            ));

            // Replace all customer entries for this type and user
            BlacklistEntry::where('is_system', false)
                ->where('user_id', $userId)
                ->where('type', $type)
                ->delete();

            foreach ($values as $value) {
                BlacklistEntry::create([
                    'type'      => $type,
                    'value'     => $value,
                    'is_system' => false,
                    'user_id'   => $userId,
                ]);
            }
        }

        return back()->with('success', 'Blacklist saved.');
    }

    /**
     * Toggle whether this account uses the system default blacklist.
     * PATCH /blacklist/toggle-system
     */
    public function toggleSystem(Request $request): JsonResponse
    {
        $request->validate([
            'use_system_blacklist' => 'required|boolean',
        ]);

        auth()->user()->update([
            'use_system_blacklist' => $request->boolean('use_system_blacklist'),
        ]);

        return response()->json(['ok' => true]);
    }
}
