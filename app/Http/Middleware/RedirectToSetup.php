<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Not installed yet → every page leads to the setup wizard (D-024).
 * Installed → the wizard is gone (404).
 */
final class RedirectToSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $installed = config('dochamster.installed') === true;
        $isSetup = $request->is('setup', 'setup/*');

        if (! $installed && ! $isSetup) {
            return redirect('/setup');
        }

        abort_if($installed && $isSetup, 404);

        return $next($request);
    }
}
