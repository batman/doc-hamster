<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * UI language (PLAN §13: German, English prepared): the signed-in user's
 * `locale` if we have texts for it, otherwise APP_LOCALE.
 */
final class SetLocale
{
    public const SUPPORTED = ['de', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && in_array($user->locale, self::SUPPORTED, true)) {
            app()->setLocale($user->locale);
        }

        return $next($request);
    }
}
