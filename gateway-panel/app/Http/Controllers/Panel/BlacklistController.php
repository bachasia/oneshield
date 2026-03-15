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
     * Display blacklist editor with all entries grouped by type as textarea strings.
     * GET /blacklist
     */
    public function index(): Response
    {
        $join = fn (string $type): string => BlacklistEntry::where('type', $type)
            ->orderBy('value')
            ->pluck('value')
            ->implode("\n");

        return Inertia::render('Blacklist/Index', [
            'emails'   => $join('email'),
            'cities'   => $join('city'),
            'states'   => $join('state'),
            'zipcodes' => $join('zipcode'),
        ]);
    }

    /**
     * Replace all entries for each type with the submitted newline-separated values.
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

            // Replace all entries for this type
            BlacklistEntry::where('type', $type)->delete();

            foreach ($values as $value) {
                BlacklistEntry::create(['type' => $type, 'value' => $value]);
            }
        }

        return back()->with('success', 'Blacklist saved.');
    }
}
