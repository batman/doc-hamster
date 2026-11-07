<?php

declare(strict_types=1);

namespace App\Jobs\Net;

/*
| The same outbound calls as the bad fixture, but inside a net job – allowed
| by the D-020 PHPStan rules. Never autoloaded (tests/fixtures is not in the
| composer autoload maps) – analysis input only.
*/
final class EgressFixtureProbe
{
    public function run(): void
    {
        file_get_contents('https://example.com/a');
        fopen('https://example.com/d', 'r');
        \Http::get('https://example.com/i');
        \Mail::raw('text', static fn (): null => null);
    }
}
