<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF protection for `/api/*` (D-017). Queued uploads may be sent a day
 * later, when the session's CSRF token has expired, so instead of the token
 * every state-changing request must come from our own origin and carry a
 * header a cross-site form can't set.
 */
final class RequireSameOriginApi
{
    public const HEADER = 'X-DocHamster-Client';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            $origin = $request->headers->get('Origin');
            $fetchSite = $request->headers->get('Sec-Fetch-Site');

            abort_unless(
                $origin === $request->getSchemeAndHttpHost()
                    && ($fetchSite === null || $fetchSite === 'same-origin')
                    && $request->headers->get(self::HEADER) === '1',
                403,
            );
        }

        return $next($request);
    }
}
