<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsurePrincipal;
use App\Http\Middleware\PublicCacheHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        apiPrefix: 'api',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Pure bearer-token API: no cookies, sessions or CSRF are used (see docs/02-architecture.md).
        $middleware->alias([
            'principal' => EnsurePrincipal::class,
            'admin.role' => EnsureAdminRole::class,
            'public.cache' => PublicCacheHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        ApiExceptionRenderer::register($exceptions);
    })
    ->create();
