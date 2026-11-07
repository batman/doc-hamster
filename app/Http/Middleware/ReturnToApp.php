<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The phone app (/app, PHONE issue #37) uses the same forms as the website
 * (upload, scan, folders, receipts). A form sent from the app carries
 * `app_back`, a path inside /app; where the website would redirect to one of
 * its own pages, the person goes back into the app instead. Only paths below
 * /app on this host are accepted, so this can never send anyone elsewhere.
 */
final class ReturnToApp
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $back = $request->input('app_back');
        if (! $response instanceof RedirectResponse || ! $request->isMethod('POST') || ! is_string($back)) {
            return $response;
        }
        $base = route('app.home', absolute: false);
        $allowed = preg_match('#^[A-Za-z0-9/_\-]*(\?[A-Za-z0-9=&_\-]*)?$#', $back) === 1
            && ($back === $base || str_starts_with($back, rtrim($base, '/').'/') || str_starts_with($back, $base.'?'));
        if ($allowed && ! str_contains($back, '//')) {
            $response->setTargetUrl(url($back));
        }

        return $response;
    }
}
