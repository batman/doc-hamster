<?php

declare(strict_types=1);

namespace App\Http\Speed;

use Illuminate\Http\Request;

/**
 * Point 164.8: a request the browser sent ahead of a click – the window cache (header X-DocHamster-Prefetch) or the
 * browser's own prefetch of a main-menu page (Sec-Purpose: prefetch). Such a request must not count as a visit
 * (badges stay, no speed measurement): the person may never open it.
 */
final class Prefetch
{
    public static function is(?Request $request = null): bool
    {
        $request ??= request();

        return $request->header('X-DocHamster-Prefetch') === '1'
            || str_contains(strtolower((string) $request->header('Sec-Purpose')), 'prefetch');
    }
}
