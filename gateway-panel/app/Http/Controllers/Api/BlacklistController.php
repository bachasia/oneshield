<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlacklistEntry;
use Illuminate\Http\JsonResponse;

class BlacklistController extends Controller
{
    /**
     * Return blacklist entries for the authenticated tenant, merged with system entries
     * if the tenant has use_system_blacklist enabled.
     * GET /api/blacklist
     *
     * Response: { emails, cities, states, zipcodes, updated_at }
     */
    public function index(): JsonResponse
    {
        $user = request()->user();

        // Fetch customer-specific entries (scoped by user_id)
        $customerQuery = BlacklistEntry::where('is_system', false)
            ->where('user_id', $user->id);

        $emails   = (clone $customerQuery)->where('type', 'email')->pluck('value')->all();
        $cities   = (clone $customerQuery)->where('type', 'city')->pluck('value')->all();
        $states   = (clone $customerQuery)->where('type', 'state')->pluck('value')->all();
        $zipcodes = (clone $customerQuery)->where('type', 'zipcode')->pluck('value')->all();

        // Merge system entries per field type based on individual toggles
        $systemQuery = BlacklistEntry::where('is_system', true);

        if ($user->use_system_blacklist_emails) {
            $emails = array_values(array_unique(array_merge($emails, (clone $systemQuery)->where('type', 'email')->pluck('value')->all())));
        }
        if ($user->use_system_blacklist_cities) {
            $cities = array_values(array_unique(array_merge($cities, (clone $systemQuery)->where('type', 'city')->pluck('value')->all())));
        }
        if ($user->use_system_blacklist_states) {
            $states = array_values(array_unique(array_merge($states, (clone $systemQuery)->where('type', 'state')->pluck('value')->all())));
        }
        if ($user->use_system_blacklist_zipcodes) {
            $zipcodes = array_values(array_unique(array_merge($zipcodes, (clone $systemQuery)->where('type', 'zipcode')->pluck('value')->all())));
        }

        $latestEntry = BlacklistEntry::latest('updated_at')->first();
        $updatedAt   = $latestEntry?->updated_at?->toIso8601String() ?? '';

        return response()->json([
            'emails'     => $emails,
            'cities'     => $cities,
            'states'     => $states,
            'zipcodes'   => $zipcodes,
            'updated_at' => $updatedAt,
        ]);
    }
}
