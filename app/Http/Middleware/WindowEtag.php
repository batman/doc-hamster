<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Point 164.8c: a window (request header X-DocHamster-Window) gets an ETag, so
 * the browser's quiet refresh of a window kept in memory costs a 304 without
 * a body when nothing changed. The same address serves the full page without
 * that header, hence Vary.
 */
final class WindowEtag
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Vary', trim($response->headers->get('Vary', '').', X-DocHamster-Window', ', '));

        if (! $request->isMethod('GET') || $request->header('X-DocHamster-Window') !== '1' || $response->getStatusCode() !== 200) {
            return $response;
        }
        $content = $response->getContent();
        if (! is_string($content) || $content === '') {
            return $response;
        }
        $response->setEtag(hash('xxh128', $content), true);
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->isNotModified($request);

        return $response;
    }
}
