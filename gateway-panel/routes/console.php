<?php

use App\Services\SiteRouterService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
*/

// Auto-reset circuit-broken sites every 15 minutes
Schedule::call(function () {
    $router  = app(SiteRouterService::class);
    $reset   = $router->resetStaleCircuitBreakers();
    if ($reset > 0) {
        Log::info("Circuit breaker: auto-reset {$reset} site(s).");
    }
})->everyFifteenMinutes()->name('circuit-breaker-reset')->withoutOverlapping();
