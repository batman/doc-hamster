<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Middleware\RedirectToSetup;
use App\Http\Middleware\RequireSameOriginApi;
use App\Http\Middleware\ResolveCurrentOrg;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Outside the `web` group: monitoring polls must not start sessions.
        then: function (): void {
            Route::get('/health', HealthController::class)->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A local reverse proxy (e.g. nginx in front of Apache on Plesk) terminates TLS; trust its
        // X-Forwarded-* headers so scheme and host are right. More proxies via TRUSTED_PROXIES (comma-separated).
        $middleware->trustProxies(at: array_merge(
            ['127.0.0.1', '::1'],
            array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))),
        ));
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [SetLocale::class, RedirectToSetup::class]);
        $middleware->alias([
            'org' => ResolveCurrentOrg::class,
            'api.same-origin' => RequireSameOriginApi::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
