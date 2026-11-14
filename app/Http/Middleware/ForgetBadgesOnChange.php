<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Badges;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Point 164.4: the menu badges are kept a few seconds per session (Badges::TTL); anything the person changes
 * (every non-GET request: upload, move, match, import …) counts them again on the next read.
 */
final class ForgetBadgesOnChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $request->isMethodSafe()) {
            Badges::forget();
        }

        return $response;
    }
}
