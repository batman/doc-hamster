<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Org\CurrentOrg;
use App\Domain\Org\SpeedOptions;
use App\Http\Speed\SpeedLog;
use App\Infrastructure\Environment\CheckStatus;
use App\Infrastructure\Environment\SpeedCheck;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Einstellungen → Geschwindigkeit (point 164.7): the measured server times per page, the speed checks with
 * tips (164.6), self tests in the browser and the options (prefetch, thumbnails).
 */
final class SpeedController
{
    /** Size of the download test (bytes): big enough to measure, small enough for a phone. */
    public const int DOWNLOAD_BYTES = 2_000_000;

    public function index(CurrentOrg $org, SpeedLog $log, SpeedCheck $check): View
    {
        $checks = $check->run();

        return view('settings.speed', [
            'rows' => array_map(static function (array $row): array {
                // "POST name|w": method (not for GET), route name, |w = loaded into a window.
                $window = str_ends_with($row['route'], '|w');
                $label = $window ? substr($row['route'], 0, -2) : $row['route'];
                $parts = explode(' ', $label, 2);
                [$method, $name] = count($parts) === 2 ? $parts : ['', $label];
                $key = 'speed.routes.'.$name;
                $title = trans()->has($key) ? __($key) : $name;

                return [...$row, 'title' => trim($method.' '.$title), 'window' => $window];
            }, $log->summary()),
            'checks' => $checks,
            'uncached' => collect($checks)->contains(static fn ($c): bool => in_array($c->key, ['speed_config', 'speed_routes', 'speed_events'], true) && $c->status !== CheckStatus::Ok),
            'options' => SpeedOptions::of($org->organization()),
        ]);
    }

    public function options(Request $request, CurrentOrg $org): RedirectResponse
    {
        $data = $request->validate([
            'prefetch' => ['sometimes', Rule::in(SpeedOptions::PREFETCH)],
            'thumbs' => ['sometimes', Rule::in(SpeedOptions::THUMBS)],
        ]);
        SpeedOptions::save($org->organization(), $data);

        return redirect()->route('speed.index')->with('status', __('speed.saved'));
    }

    /** "Jetzt vorbereiten": configuration, routes, events and pages compiled once (php artisan optimize). */
    public function optimize(): RedirectResponse
    {
        try {
            $ok = Artisan::call('optimize') === 0;
        } catch (Throwable $e) {
            report($e);
            $ok = false;
        }
        if (! $ok) {
            // Half-built caches are worse than none (not optimize:clear: that would empty the application cache).
            foreach (['config:clear', 'route:clear', 'event:clear'] as $command) {
                Artisan::call($command);
            }
        }

        return redirect()->route('speed.index')->with('status', __($ok ? 'speed.optimized' : 'speed.optimize_failed'));
    }

    public function reset(SpeedLog $log): RedirectResponse
    {
        $log->clear();

        return redirect()->route('speed.index')->with('status', __('speed.reset_done'));
    }

    /** Self test: the database's answer time (median of 10 × SELECT 1). */
    public function db(): JsonResponse
    {
        return response()->json(['ms' => SpeedCheck::dbLatency()], 200, ['Cache-Control' => 'no-store']);
    }

    /** Self test: a fixed amount of bytes that don't compress, never cached. */
    public function download(): Response
    {
        return response(random_bytes(self::DOWNLOAD_BYTES), 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }
}
