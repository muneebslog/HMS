<?php

use App\Http\Middleware\EnsureOpenShift;
use App\Http\Middleware\EnsurePrintAgentToken;
use App\Http\Middleware\EnsureRoleAssigned;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\ForceHttpsForDomain;
use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies as TrustProxiesMiddleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(TrustProxiesMiddleware::class, TrustProxies::class);

        $middleware->prepend(ForceHttpsForDomain::class);

        $middleware->alias([
            'open.shift' => EnsureOpenShift::class,
            'print.agent' => EnsurePrintAgentToken::class,
            'role' => EnsureUserRole::class,
            'role.assigned' => EnsureRoleAssigned::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
