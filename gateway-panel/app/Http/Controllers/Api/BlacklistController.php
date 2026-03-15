<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlacklistEntry;
use Illuminate\Http\JsonResponse;

class BlacklistController extends Controller
{
    /**
     * Return all blacklist entries grouped by type for WC plugin caching.
     * GET /api/blacklist
     *
     * Response: { emails, cities, states, zipcodes, updated_at }
     */
    public function index(): JsonResponse
    {
        $emails   = BlacklistEntry::where('type', 'email')->pluck('value')->all();
        $cities   = BlacklistEntry::where('type', 'city')->pluck('value')->all();
        $states   = BlacklistEntry::where('type', 'state')->pluck('value')->all();
        $zipcodes = BlacklistEntry::where('type', 'zipcode')->pluck('value')->all();

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
