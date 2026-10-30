<?php

declare(strict_types=1);

use App\Setup\CronLine;

it('uses the command line PHP, not the web server program', function (string $binary, string $php): void {
    expect(CronLine::command($binary, '/srv/dochamster/artisan'))
        ->toBe($php.' /srv/dochamster/artisan schedule:run');
})->with([
    'Plesk FPM' => ['/opt/plesk/php/8.3/sbin/php-fpm', '/opt/plesk/php/8.3/bin/php'],
    'Debian FPM' => ['/usr/sbin/php-fpm8.3', '/usr/bin/php8.3'],
    'CGI' => ['/usr/bin/php-cgi8.3', '/usr/bin/php8.3'],
    'already CLI' => ['/usr/bin/php', '/usr/bin/php'],
    'unknown' => ['', 'php'],
]);
