<?php

namespace Tests\Unit;

use App\Models\MeshSite;
use App\Models\User;
use App\Services\HmacService;
use App\Services\SiteRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\OneShieldTestHelpers;
use Tests\TestCase;

/**
 * Unit tests for SiteRouterService.
 *
 * Covers:
 * - selectSite: basic happy path
 * - selectSite: group_id filter
 * - selectSite: gateway filter (only sites supporting requested gateway)
 * - selectSite: ignores inactive sites
 * - selectSite: circuit breaker threshold (failure_count >= 5)
 * - selectSite: stale heartbeat fallback
 * - recordFailure: increments counter + trips breaker at threshold
 * - recordSuccess: resets counter + re-enables site
 * - resetStaleCircuitBreakers: re-enables sites disabled > 30 min ago
 * - buildIframeUrl: correct query string construction
 */
class SiteRouterServiceTest extends TestCase
{
    use RefreshDatabase, OneShieldTestHelpers;

    private SiteRouterService $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHmac();
        $this->router = new SiteRouterService();
    }

    // =========================================================================
    // selectSite
    // =========================================================================

    /** @test */
    public function select_site_returns_an_active_site(): void
    {
        $user = $this->createUser();
        $site = $this->createMeshSite($user);

        $result = $this->router->selectSite($user->id, 'stripe');

        $this->assertNotNull($result);
        $this->assertEquals($site->id, $result->id);
    }

    /** @test */
    public function select_site_returns_null_when_no_sites_exist(): void
    {
        $user   = $this->createUser();
        $result = $this->router->selectSite($user->id, 'stripe');

        $this->assertNull($result);
    }

    /** @test */
    public function select_site_ignores_inactive_sites(): void
    {
        $user = $this->createUser();
        $this->createMeshSite($user, ['is_active' => false]);

        $result = $this->router->selectSite($user->id, 'stripe');

        $this->assertNull($result);
    }

    /** @test */
    public function select_site_ignores_sites_with_failure_count_at_or_above_threshold(): void
    {
        $user = $this->createUser();
        $this->createMeshSite($user, ['failure_count' => 5]);

        $result = $this->router->selectSite($user->id, 'stripe');

        $this->assertNull($result);
    }

    /** @test */
    public function select_site_ignores_sites_that_dont_support_the_gateway(): void
    {
        $user = $this->createUser();
        // Stripe-only, no PayPal
        $this->createMeshSite($user, [
            'paypal_client_id' => null,
            'paypal_secret'    => null,
        ]);

        $result = $this->router->selectSite($user->id, 'paypal');

        $this->assertNull($result);
    }

    /** @test */
    public function select_site_respects_group_id_filter(): void
    {
        $user   = $this->createUser();
        $groupA = \App\Models\SiteGroup::create(['user_id' => $user->id, 'name' => 'A']);
        $groupB = \App\Models\SiteGroup::create(['user_id' => $user->id, 'name' => 'B']);

        $siteA = $this->createMeshSite($user, ['group_id' => $groupA->id]);
        $this->createMeshSite($user, [
            'url'      => 'https://site-b.example.com',
            'group_id' => $groupB->id,
        ]);

        $result = $this->router->selectSite($user->id, 'stripe', $groupA->id);

        $this->assertNotNull($result);
        $this->assertEquals($siteA->id, $result->id);
    }

    /** @test */
    public function select_site_uses_fallback_when_heartbeat_is_stale(): void
    {
        $user = $this->createUser();
        // Heartbeat 15 minutes ago — normally excluded, but fallback includes it
        $site = $this->createMeshSite($user, [
            'last_heartbeat_at' => now()->subMinutes(15),
        ]);

        $result = $this->router->selectSite($user->id, 'stripe');

        // Fallback should still find it
        $this->assertNotNull($result);
        $this->assertEquals($site->id, $result->id);
    }

    // =========================================================================
    // recordFailure / recordSuccess
    // =========================================================================

    /** @test */
    public function record_failure_increments_failure_count(): void
    {
        $user = $this->createUser();
        $site = $this->createMeshSite($user, ['failure_count' => 0]);

        $this->router->recordFailure($site);

        $site->refresh();
        $this->assertEquals(1, $site->failure_count);
    }

    /** @test */
    public function record_failure_trips_circuit_breaker_at_threshold(): void
    {
        config(['oneshield.circuit_breaker.failure_threshold' => 5]);

        $user = $this->createUser();
        $site = $this->createMeshSite($user, ['failure_count' => 4, 'is_active' => true]);

        $this->router->recordFailure($site); // 4 → 5, should disable

        $site->refresh();
        $this->assertEquals(5, $site->failure_count);
        $this->assertFalse($site->is_active);
        $this->assertNotNull($site->disabled_at);
    }

    /** @test */
    public function record_failure_does_not_disable_if_already_inactive(): void
    {
        config(['oneshield.circuit_breaker.failure_threshold' => 5]);

        $user = $this->createUser();
        $site = $this->createMeshSite($user, ['failure_count' => 4, 'is_active' => false]);

        $this->router->recordFailure($site);

        $site->refresh();
        // Still inactive, but disabled_at should NOT be reset to now (already was null)
        $this->assertFalse($site->is_active);
    }

    /** @test */
    public function record_success_resets_failure_count_and_re_enables_site(): void
    {
        $user = $this->createUser();
        $site = $this->createMeshSite($user, [
            'failure_count' => 3,
            'is_active'     => false,
            'disabled_at'   => now()->subHour(),
        ]);

        $this->router->recordSuccess($site);

        $site->refresh();
        $this->assertEquals(0, $site->failure_count);
        $this->assertTrue($site->is_active);
        $this->assertNull($site->disabled_at);
    }

    /** @test */
    public function record_success_does_nothing_if_already_healthy(): void
    {
        $user = $this->createUser();
        $site = $this->createMeshSite($user, ['failure_count' => 0, 'is_active' => true]);

        $this->router->recordSuccess($site);

        $site->refresh();
        $this->assertEquals(0, $site->failure_count);
        $this->assertTrue($site->is_active);
    }

    // =========================================================================
    // resetStaleCircuitBreakers
    // =========================================================================

    /** @test */
    public function reset_stale_circuit_breakers_re_enables_old_disabled_sites(): void
    {
        config(['oneshield.circuit_breaker.reset_after_min' => 30]);

        $user = $this->createUser();
        // Disabled 40 minutes ago → should be re-enabled
        $stale = $this->createMeshSite($user, [
            'is_active'     => false,
            'failure_count' => 5,
            'disabled_at'   => now()->subMinutes(40),
        ]);
        // Disabled 10 minutes ago → should NOT be re-enabled yet
        $fresh = $this->createMeshSite($user, [
            'url'           => 'https://fresh-disabled.example.com',
            'is_active'     => false,
            'failure_count' => 5,
            'disabled_at'   => now()->subMinutes(10),
        ]);

        $count = $this->router->resetStaleCircuitBreakers();

        $this->assertEquals(1, $count);

        $stale->refresh();
        $this->assertTrue($stale->is_active);
        $this->assertEquals(0, $stale->failure_count);

        $fresh->refresh();
        $this->assertFalse($fresh->is_active);
    }

    /** @test */
    public function reset_stale_circuit_breakers_ignores_still_active_sites(): void
    {
        config(['oneshield.circuit_breaker.reset_after_min' => 30]);

        $user = $this->createUser();
        $this->createMeshSite($user, ['is_active' => true]);

        $count = $this->router->resetStaleCircuitBreakers();

        $this->assertEquals(0, $count);
    }

    // =========================================================================
    // buildIframeUrl
    // =========================================================================

    /** @test */
    public function build_iframe_url_includes_all_parameters(): void
    {
        $user = $this->createUser();
        $site = $this->createMeshSite($user, ['url' => 'https://mesh.example.com/']);

        $url = $this->router->buildIframeUrl($site, 'stripe', 'ORDER-42', 'token-abc');

        $this->assertStringStartsWith('https://mesh.example.com/', $url);
        $this->assertStringContainsString('fe-checkout=1', $url);
        $this->assertStringContainsString('gateway=stripe', $url);
        $this->assertStringContainsString('order_id=ORDER-42', $url);
        $this->assertStringContainsString('token=token-abc', $url);
    }

    /** @test */
    public function build_iframe_url_strips_trailing_slash_from_site_url(): void
    {
        $user = $this->createUser();
        $site = $this->createMeshSite($user, ['url' => 'https://mesh.example.com/']);

        $url = $this->router->buildIframeUrl($site, 'paypal', 'ORD-1', 'tok');

        // Should not have double slash
        $this->assertStringNotContainsString('example.com//', $url);
    }
}
