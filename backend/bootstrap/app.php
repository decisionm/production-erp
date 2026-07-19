<?php

use App\Exceptions\DomainException;
use App\Http\Middleware\EnsureModulePermission;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'module' => EnsureModulePermission::class,
            'active' => EnsureUserIsActive::class,
        ]);

        // There is no server-side named "login" route — the SPA owns /login
        // client-side. Without this, the framework default tries route('login')
        // for any unauthenticated request and throws RouteNotFoundException
        // instead of a clean 401.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Any module's exception implementing DomainException (an expected
        // business-rule violation, not a bug) renders as a plain 422.
        $exceptions->render(function (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
    })->create();
