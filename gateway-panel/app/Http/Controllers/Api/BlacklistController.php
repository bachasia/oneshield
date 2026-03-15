<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlacklistEntry;
use Illuminate\Http\JsonResponse;

class BlacklistController extends Controller
{
    /**
     * Return all blacklist entries for WC plugin caching.
     * GET /api/blacklist
     *
     * Response: { emails: [], addresses: [], updated_at: "" }
     */
    public function index(): JsonResponse
    {
        $emails    = BlacklistEntry::where('type', 'email')->pluck('value')->all();
        $addresses = BlacklistEntry::where('type', 'address')->pluck('value')->all();

        $latestEntry = BlacklistEntry::latest('updated_at')->first();
        $updatedAt   = $latestEntry?->updated_at?->toIso8601String() ?? '';

        return response()->json([
            'emails'     => $emails,
            'addresses'  => $addresses,
            'updated_at' => $updatedAt,
        ]);
    }
}
