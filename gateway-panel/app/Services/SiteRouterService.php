<?php

namespace App\Services;

use App\Models\ShieldSite;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SiteRouterService
{
    /**
     * Select an active shield site for the given gateway, optional group, and order amount.
     *
     * Selection strategy: random from active sites that support the gateway.
     * Circuit breaker: skip sites with failure_count >= threshold.
     * Spin limits: skip sites that have exceeded income_limit or max_per_order.
     */
    public function selectSite(int $userId, string $gateway, ?int $groupId = null, float $amount = 0.0): ?ShieldSite
    {
        $threshold = (int) config('oneshield.circuit_breaker.failure_threshold', 5);

        // Load active sites from cache (heartbeat-filtered list)
        $sites = $this->getCachedActiveSites($userId, $groupId);

        // Filter in-memory: circuit breaker + heartbeat freshness
        $sites = $sites->filter(
            fn (ShieldSite $site) => $site->failure_count < $threshold
                && $site->last_heartbeat_at !== null
                && $site->last_heartbeat_at->gte(now()->subMinutes(10))
        );

        // Filter by gateway support + spin limits
        $eligible = $sites->filter(
            fn (ShieldSite $site) => $site->supportsGateway($gateway)
                && $this->passesSpinLimits($site, $gateway, $amount)
        );

        if ($eligible->isEmpty()) {
            // Fallback: try without heartbeat requirement (uses same cache, relaxed filter)
            $eligible = $this->fallbackSelect($userId, $gateway, $groupId, $amount);
        }

        if ($eligible->isEmpty()) {
            return null;
        }

        return $eligible->random();
    }

    /**
     * Return active shield sites for a user from Redis cache.
     * Cache key is per-user (+ optional group) and expires after configured TTL.
     * Invalidated explicitly by recordFailure() / recordSuccess().
     */
    public function getCachedActiveSites(int $userId, ?int $groupId = null): Collection
    {
        $ttl      = (int) config('oneshield.cache.active_sites_ttl', 60);
        $cacheKey = $this->sitesCacheKey($userId, $groupId);

        return Cache::remember($cacheKey, $ttl, function () use ($userId, $groupId) {
            $query = ShieldSite::where('user_id', $userId)->active();

            if ($groupId !== null) {
                $query->where('group_id', $groupId);
            }

            return $query->get();
        });
    }

    /**
     * Invalidate the active-sites cache for a given user.
     * Called whenever a site's active/failure state changes.
     */
    public function invalidateSitesCache(int $userId, ?int $groupId = null): void
    {
        Cache::forget($this->sitesCacheKey($userId, $groupId));

        // Also forget the group-less key if a group key was given (and vice versa)
        if ($groupId !== null) {
            Cache::forget($this->sitesCacheKey($userId, null));
        }
    }

    private function sitesCacheKey(int $userId, ?int $groupId): string
    {
        return sprintf('oneshield:active_sites:u%d:g%s', $userId, $groupId ?? '0');
    }

    /**
     * Check whether a site passes the spin/income limit rules for the given gateway and order amount.
     *
     * Rules checked:
     *  1. max_per_order  — single order amount must not exceed the configured cap (0 = unlimited)
     *  2. income_limit   — total completed revenue in the current receive_cycle must not exceed cap (0 = unlimited)
     *
     * Note: Airwallex does not yet have per-gateway limits in the DB schema (Phase 2).
     * Until those columns are added, Airwallex routes bypass spin limits entirely.
     */
    public function passesSpinLimits(ShieldSite $site, string $gateway, float $amount): bool
    {
        // Airwallex: no spin-limit columns in DB yet — no restrictions applied
        if ($gateway === 'airwallex') {
            return true;
        }

        $maxPerOrder  = (float) ($gateway === 'paypal' ? $site->paypal_max_per_order : $site->stripe_max_per_order);
        $incomeLimit  = (float) ($gateway === 'paypal' ? $site->paypal_income_limit  : $site->stripe_income_limit);

        // 1. Per-order cap
        if ($maxPerOrder > 0 && $amount > $maxPerOrder) {
            return false;
        }

        // 2. Income limit within cycle
        if ($incomeLimit > 0) {
            $cycleStart  = $this->getCycleStart($site->receive_cycle);
            $totalEarned = Transaction::where('site_id', $site->id)
                ->where('gateway', $gateway)
                ->where('status', 'completed')
                ->where('created_at', '>=', $cycleStart)
                ->sum('amount');

            if ((float) $totalEarned >= $incomeLimit) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return the start of the current receive cycle as a Carbon instance.
     */
    private function getCycleStart(string $cycle): Carbon
    {
        return match ($cycle) {
            'daily'    => now()->startOfDay(),
            'weekly'   => now()->startOfWeek(),
            'monthly'  => now()->startOfMonth(),
            default    => Carbon::createFromTimestamp(0), // 'lifetime' → epoch = no restriction by date
        };
    }

    /**
     * Fallback: select from active sites ignoring heartbeat (site might have stale heartbeat).
     * Uses the same cache but without heartbeat filter.
     */
    private function fallbackSelect(int $userId, string $gateway, ?int $groupId, float $amount = 0.0): Collection
    {
        $threshold = (int) config('oneshield.circuit_breaker.failure_threshold', 5);

        return $this->getCachedActiveSites($userId, $groupId)->filter(
            fn (ShieldSite $site) => $site->failure_count < $threshold
                && $site->supportsGateway($gateway)
                && $this->passesSpinLimits($site, $gateway, $amount)
        );
    }

    /**
     * Build the iframe URL for a shield site checkout.
     */
    public function buildIframeUrl(ShieldSite $site, string $gateway, string $orderId, string $token): string
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
    public function recordFailure(ShieldSite $site): void
    {
        $threshold = (int) config('oneshield.circuit_breaker.failure_threshold', 5);

        $site->increment('failure_count');
        $site->refresh();

        if ($site->failure_count >= $threshold && $site->is_active) {
            $site->update([
                'is_active'   => false,
                'disabled_at' => now(),
            ]);
        }

        // Invalidate cache so next routing skips this site immediately
        $this->invalidateSitesCache($site->user_id, $site->group_id);
    }

    /**
     * Reset failure count after a successful interaction.
     */
    public function recordSuccess(ShieldSite $site): void
    {
        if ($site->failure_count > 0 || !$site->is_active) {
            $site->update([
                'failure_count' => 0,
                'is_active'     => true,
                'disabled_at'   => null,
            ]);

            // Re-populate cache so site becomes available immediately
            $this->invalidateSitesCache($site->user_id, $site->group_id);
        }
    }

    /**
     * Auto-reset circuit breaker for sites that have been disabled for long enough.
     * Called from a scheduled command.
     */
    public function resetStaleCircuitBreakers(): int
    {
        $resetAfter = (int) config('oneshield.circuit_breaker.reset_after_min', 30);

        return ShieldSite::where('is_active', false)
            ->whereNotNull('disabled_at')
            ->where('disabled_at', '<=', now()->subMinutes($resetAfter))
            ->update([
                'is_active'     => true,
                'failure_count' => 0,
                'disabled_at'   => null,
            ]);
    }
}
