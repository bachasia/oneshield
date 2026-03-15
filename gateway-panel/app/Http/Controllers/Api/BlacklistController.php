<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlacklistEntry;
use App\Services\BlacklistService;
use Illuminate\Http\JsonResponse;

class BlacklistController extends Controller
{
    public function __construct(private BlacklistService $blacklistService) {}

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
        $list = $this->blacklistService->getListForUser($user);

        $latestEntry = BlacklistEntry::latest('updated_at')->first();
        $updatedAt   = $latestEntry?->updated_at?->toIso8601String() ?? '';

        return response()->json(array_merge($list, ['updated_at' => $updatedAt]));
    }
}
