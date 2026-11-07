<?php

declare(strict_types=1);

use Tests\Unit\Installer\InstallerBrowser;
use Tests\Unit\Installer\InstallerFixtures;

InstallerFixtures::loadInstaller();

it('detects HTTPS directly and behind a proxy', function (array $server, bool $expected): void {
    expect(isHttpsRequest($server))->toBe($expected);
})->with([
    'HTTPS on' => [['HTTPS' => 'on'], true],
    'HTTPS 1 (IIS)' => [['HTTPS' => '1'], true],
    'HTTPS off' => [['HTTPS' => 'off'], false],
    'HTTPS empty' => [['HTTPS' => ''], false],
    'no server vars' => [[], false],
    'forwarded proto https' => [['HTTP_X_FORWARDED_PROTO' => 'https'], true],
    'forwarded proto uppercase' => [['HTTP_X_FORWARDED_PROTO' => 'HTTPS'], true],
    'forwarded proto http' => [['HTTP_X_FORWARDED_PROTO' => 'http'], false],
    'proxy wins over off' => [['HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https'], true],
]);

it('warns about missing HTTPS on the welcome page', function (): void {
    $browser = InstallerBrowser::start([]);
    try {
        [$status, , $body] = $browser->get('/install.php');

        expect($status)->toBe(200)
            ->and($body)->toContain('ohne HTTPS');
    } finally {
        $browser->stop();
    }
});

it('stays silent about HTTPS behind a proxy', function (): void {
    $browser = InstallerBrowser::start([]);
    try {
        [$status, , $body] = $browser->get('/install.php', ['X-Forwarded-Proto: https']);

        expect($status)->toBe(200)
            ->and($body)->not->toContain('ohne HTTPS');
    } finally {
        $browser->stop();
    }
});

it('blocks the install without HTTPS even when a release fits', function (): void {
    [$routes, $files] = InstallerBrowser::releaseRoutes('0.2.0', '8.3.0', str_repeat('a', 64));
    $browser = InstallerBrowser::start($routes, $files);
    try {
        $body = $browser->unlock();

        expect($body)->toContain('Passende Version')
            ->and($body)->toContain('<li class="fail">HTTPS ✘</li>')
            ->and($body)->not->toContain('name="install"');
    } finally {
        $browser->stop();
    }
});

it('offers the install behind an HTTPS proxy', function (): void {
    [$routes, $files] = InstallerBrowser::releaseRoutes('0.2.0', '8.3.0', str_repeat('a', 64));
    $browser = InstallerBrowser::start($routes, $files);
    try {
        $body = $browser->unlock(['X-Forwarded-Proto: https']);

        expect($body)->toContain('<li class="ok">HTTPS ✔</li>')
            ->and($body)->toContain('name="install"');
    } finally {
        $browser->stop();
    }
});
