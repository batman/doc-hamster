<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response (PLAN §11.3). The CSP allows no inline
 * scripts or styles and no third-party origins (D-004: everything is served
 * from our own domain). Camera, location and microphone only for our own
 * pages (capture, audio notes).
 */
final class SecurityHeaders
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; "
        ."media-src 'self' blob:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; "
        ."form-action 'self'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->add([
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(self), geolocation=(self), microphone=(self), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ]);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
