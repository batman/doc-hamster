<?php

declare(strict_types=1);

namespace EgressFixtures\Bad;

/*
| Every statement below is an outbound call outside the net jobs and must be
| flagged by the D-020 PHPStan rules. Never executed – analysis input only.
*/
final class BadProbe
{
    public function run(): void
    {
        file_get_contents('https://example.com/a');
        file_get_contents('http://example.com/b');
        file_get_contents('HTTPS://example.com/c');
        fopen('https://example.com/d', 'r');
        file('https://example.com/e');
        readfile('http://example.com/f');
        copy('https://example.com/g', '/tmp/g');
        get_headers('https://example.com/h');
        \Http::get('https://example.com/i');
        \Mail::raw('text', static fn (): null => null);
        \Notification::send([], new \stdClass);
    }
}
