<?php

use App\Http\Middleware\ApiCors;
use App\Http\Middleware\HmacAuthentication;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecureHeaders;
use App\Http\Middleware\SuperAdmin;
use App\Http\Middleware\TenantSubscriptionActive;
use App\Http\Middleware\ThrottlePerToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Web: Inertia + secure headers + tenant resolution on every web request
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            SecureHeaders::class,
            ResolveTenant::class,
        ]);

        // Register middleware aliases
        $middleware->alias([
            'hmac'                => HmacAuthentication::class,
            'cors.api'            => ApiCors::class,
            'throttle.token'      => ThrottlePerToken::class,
            'super_admin'         => SuperAdmin::class,
            'tenant.active'       => TenantSubscriptionActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
