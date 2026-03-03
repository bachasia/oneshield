<?php

namespace App\Services;

use App\Models\MeshSite;
use Illuminate\Database\Eloquent\Collection;

class SiteRouterService
{
    /**
     * Select an active mesh site for the given gateway and optional group.
     *
     * Selection strategy: random from active sites that support the gateway.
     * Circuit breaker: skip sites with failure_count >= 5.
     */
    public function selectSite(int $userId, string $gateway, ?int $groupId = null): ?MeshSite
    {
        $query = MeshSite::where('user_id', $userId)
            ->active()
            ->where('failure_count', '<', 5) // circuit breaker threshold
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '>=', now()->subMinutes(10)); // heartbeat within 10 min

        if ($groupId !== null) {
            $query->where('group_id', $groupId);
        }

        $sites = $query->get();

        // Filter by gateway support
        $eligible = $sites->filter(fn (MeshSite $site) => $site->supportsGateway($gateway));

        if ($eligible->isEmpty()) {
            // Fallback: try without heartbeat requirement if no eligible sites
            $eligible = $this->fallbackSelect($userId, $gateway, $groupId);
        }

        return $eligible->random() ?? null;
    }

    /**
     * Fallback: select from active sites ignoring heartbeat (site might have stale heartbeat).
     */
    private function fallbackSelect(int $userId, string $gateway, ?int $groupId): Collection
    {
        $query = MeshSite::where('user_id', $userId)
            ->active()
            ->where('failure_count', '<', 5);

        if ($groupId !== null) {
            $query->where('group_id', $groupId);
        }

        return $query->get()->filter(fn (MeshSite $site) => $site->supportsGateway($gateway));
    }

    /**
     * Build the iframe URL for a mesh site checkout.
     */
    public function buildIframeUrl(MeshSite $site, string $gateway, string $orderId, string $token): string
    {
        return rtrim($site->url, '/') . '/?' . http_build_query([
            'fe-checkout' => '1',
            'gateway' => $gateway,
            'order_id' => $orderId,
            'token' => $token,
        ]);
    }

    /**
     * Record a site failure and potentially trip the circuit breaker.
     */
    public function recordFailure(MeshSite $site): void
    {
        $site->increment('failure_count');

        if ($site->failure_count >= 5) {
            $site->update(['is_active' => false]);
        }
    }

    /**
     * Reset failure count after a successful interaction.
     */
    public function recordSuccess(MeshSite $site): void
    {
        if ($site->failure_count > 0) {
            $site->update(['failure_count' => 0]);
        }
    }
}
