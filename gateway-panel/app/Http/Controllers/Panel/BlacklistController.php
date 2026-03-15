<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\BlacklistEntry;
use App\Models\ShieldSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BlacklistController extends Controller
{
    /**
     * Display blacklist editor with all entries grouped by type as textarea strings.
     * Also passes global blacklist protection settings (blacklist_action, trap_shield_id).
     * GET /blacklist
     */
    public function index(): Response
    {
        $user   = auth()->user();
        $userId = $user->id;

        $join = fn (string $type): string => BlacklistEntry::where('is_system', false)
            ->where('user_id', $userId)
            ->where('type', $type)
            ->orderBy('value')
            ->pluck('value')
            ->implode("\n");

        // All active shield sites for the trap dropdown
        $shields = ShieldSite::where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name']);

        return Inertia::render('Blacklist/Index', [
            'emails'                        => $join('email'),
            'cities'                        => $join('city'),
            'states'                        => $join('state'),
            'zipcodes'                      => $join('zipcode'),
            'use_system_blacklist_emails'   => (bool) $user->use_system_blacklist_emails,
            'use_system_blacklist_cities'   => (bool) $user->use_system_blacklist_cities,
            'use_system_blacklist_states'   => (bool) $user->use_system_blacklist_states,
            'use_system_blacklist_zipcodes' => (bool) $user->use_system_blacklist_zipcodes,
            'blacklist_action'              => $user->blacklist_action ?? 'hide',
            'trap_shield_id'                => $user->trap_shield_id,
            'shields'                       => $shields,
        ]);
    }

    /**
     * Replace all entries for this user for each type with submitted newline-separated values.
     * Also saves the global blacklist_action and trap_shield_id on the user.
     * POST /blacklist/save
     */
    public function save(Request $request): RedirectResponse
    {
        $request->validate([
            'emails'                        => 'nullable|string',
            'cities'                        => 'nullable|string',
            'states'                        => 'nullable|string',
            'zipcodes'                      => 'nullable|string',
            'blacklist_action'              => 'required|in:hide,trap',
            'trap_shield_id'               => 'nullable|integer|exists:shield_sites,id',
            'use_system_blacklist_emails'   => 'nullable|boolean',
            'use_system_blacklist_cities'   => 'nullable|boolean',
            'use_system_blacklist_states'   => 'nullable|boolean',
            'use_system_blacklist_zipcodes' => 'nullable|boolean',
        ]);

        $user   = auth()->user();
        $userId = $user->id;

        // Validate trap action requires a shield selection
        if ($request->input('blacklist_action') === 'trap' && empty($request->input('trap_shield_id'))) {
            return back()->withErrors(['trap_shield_id' => 'Please select a trap shield site.']);
        }

        // Validate trap shield belongs to this user
        if (!empty($request->input('trap_shield_id'))) {
            abort_unless(
                ShieldSite::where('id', $request->input('trap_shield_id'))
                    ->where('user_id', $userId)
                    ->exists(),
                403
            );
        }

        // Save global blacklist protection settings and per-type system flags on the user
        $user->update([
            'blacklist_action'              => $request->input('blacklist_action'),
            'trap_shield_id'               => $request->input('blacklist_action') === 'trap'
                ? $request->input('trap_shield_id')
                : null,
            'use_system_blacklist_emails'   => $request->boolean('use_system_blacklist_emails'),
            'use_system_blacklist_cities'   => $request->boolean('use_system_blacklist_cities'),
            'use_system_blacklist_states'   => $request->boolean('use_system_blacklist_states'),
            'use_system_blacklist_zipcodes' => $request->boolean('use_system_blacklist_zipcodes'),
        ]);

        // Replace per-type blacklist entries
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

}
