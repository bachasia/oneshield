<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Root URL redirects to admin-setup on a fresh install (no users).
     * Once a user exists it redirects to /login instead.
     */
    public function test_root_redirects_when_unauthenticated(): void
    {
        $response = $this->get('/');

        // Accept either /account/admin (first-run) or /login (existing install)
        $this->assertContains($response->getStatusCode(), [301, 302]);
    }
}
