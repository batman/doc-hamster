<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * The command cron runs every minute (D-010). In the browser PHP_BINARY is the
 * FPM or CGI program, which can't run scripts from cron, so it is mapped to
 * the command line PHP next to it (e.g. …/sbin/php-fpm → …/bin/php).
 */
final class CronLine
{
    public static function command(string $phpBinary, string $artisan): string
    {
        $php = (string) preg_replace('#/sbin/php-fpm([0-9.]*)$#', '/bin/php$1', $phpBinary);
        $php = (string) preg_replace('#/php-cgi([0-9.]*)$#', '/php$1', $php);

        return ($php === '' ? 'php' : $php).' '.$artisan.' schedule:run';
    }
}
