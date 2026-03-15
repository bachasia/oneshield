<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\BlacklistEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemBlacklistController extends Controller
{
    /**
     * Display system blacklist editor grouped by type.
     * GET /admin/system-blacklist
     */
    public function index(): Response
    {
        $join = fn (string $type): string => BlacklistEntry::where('is_system', true)
            ->where('type', $type)
            ->orderBy('value')
            ->pluck('value')
            ->implode("\n");

        return Inertia::render('Admin/SystemBlacklist', [
            'emails'   => $join('email'),
            'cities'   => $join('city'),
            'states'   => $join('state'),
            'zipcodes' => $join('zipcode'),
        ]);
    }

    /**
     * Replace all system entries for each type with submitted newline-separated values.
     * POST /admin/system-blacklist/save
     */
    public function save(Request $request): RedirectResponse
    {
        $request->validate([
            'emails'   => 'nullable|string',
            'cities'   => 'nullable|string',
            'states'   => 'nullable|string',
            'zipcodes' => 'nullable|string',
        ]);

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

            // Replace all system entries for this type (no user_id — global)
            BlacklistEntry::where('is_system', true)
                ->where('type', $type)
                ->delete();

            foreach ($values as $value) {
                BlacklistEntry::create([
                    'type'      => $type,
                    'value'     => $value,
                    'is_system' => true,
                    'user_id'   => null,
                ]);
            }
        }

        return back()->with('success', 'System blacklist saved.');
    }
}
