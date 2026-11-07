<?php

declare(strict_types=1);

/*
| Global stand-ins for Laravel's facade aliases, so the fixture analysis only
| reports the D-020 rule errors (no "unknown class" noise). Never autoloaded –
| PHPStan analyses this file together with the fixtures, nothing executes it.
*/
final class Http
{
    public static function get(string $url): mixed
    {
        throw new RuntimeException('Fixture stub – never executed.');
    }
}

final class Mail
{
    public static function raw(string $text, Closure $callback): mixed
    {
        throw new RuntimeException('Fixture stub – never executed.');
    }
}

final class Notification
{
    /** @param list<mixed> $targets */
    public static function send(array $targets, object $notification): mixed
    {
        throw new RuntimeException('Fixture stub – never executed.');
    }
}
