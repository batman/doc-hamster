<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Speed\Prefetch;
use App\Http\Speed\RequestTiming;
use App\Http\Speed\SpeedLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Point 164.1: every response says where its server time went (Server-Timing:
 * total, db, queries, view – DevTools → Network → Timing). After the response
 * is sent the time is kept per route for Einstellungen → Geschwindigkeit
 * (point 164.7).
 */
final class ServerTiming
{
    /** Self tests and monitoring measure themselves; they would only blur the page times. */
    private const array UNRECORDED = ['ping', 'health', 'speed.*', 'badges'];

    public function __construct(private readonly RequestTiming $timing) {}

    public function handle(Request $request, Closure $next): Response
    {
        // In production LARAVEL_START (public/index.php) also covers autoloader and bootstrap.
        $this->timing->start(defined('LARAVEL_START') && ! app()->runningUnitTests() ? (float) LARAVEL_START : microtime(true));
        // Cache::memo() keeps cache reads for one request only (layout preload, point 164.4).
        app()->forgetInstance('cache.__memoized:'.(string) config('cache.default'));

        $response = $next($request);
        $this->timing->finish();
        $response->headers->set('Server-Timing', $this->timing->header());

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;
        if (app()->runningUnitTests() || ! is_string($name) || $request->routeIs(...self::UNRECORDED) || Prefetch::is($request)) {
            return;
        }
        try {
            $label = ($request->isMethod('GET') ? '' : $request->method().' ').$name.($request->header('X-DocHamster-Window') === '1' ? '|w' : '');
            app(SpeedLog::class)->record($label, $this->timing->totalMs(), $this->timing->dbMs(), $this->timing->queries());
        } catch (Throwable) {
            // Measuring must never break a request.
        }
    }
}
