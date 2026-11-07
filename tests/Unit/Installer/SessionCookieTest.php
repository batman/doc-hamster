<?php

declare(strict_types=1);

use Tests\Unit\Installer\InstallerFixtures;

InstallerFixtures::loadInstaller();

it('detects HTTPS directly and behind a proxy', function (array $server, bool $expected): void {
    expect(isHttpsRequest($server))->toBe($expected);
})->with([
    'HTTPS on' => [['HTTPS' => 'on'], true],
    'HTTPS 1 (IIS)' => [['HTTPS' => '1'], true],
    'HTTPS off' => [['HTTPS' => 'off'], false],
    'no server vars' => [[], false],
    'forwarded proto https' => [['HTTP_X_FORWARDED_PROTO' => 'https'], true],
    'forwarded proto uppercase' => [['HTTP_X_FORWARDED_PROTO' => 'HTTPS'], true],
    'forwarded proto http' => [['HTTP_X_FORWARDED_PROTO' => 'http'], false],
    'proxy wins over off' => [['HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https'], true],
]);

it('wires the session cookie flags to the HTTPS detection', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/installer/install.php');

    // Static by necessity: the cookie flags are set once per installer process.
    // The live header check below proves HttpOnly and SameSite on the wire.
    expect($source)->toContain("session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => \$https])")
        ->and($source)->toContain('$https = isHttpsRequest($_SERVER);');
});

it('sets HttpOnly and SameSite=Strict on the live session cookie', function (): void {
    $docRoot = InstallerFixtures::makeTempDir('dh-cookie-web');
    copy(dirname(__DIR__, 3).'/installer/install.php', $docRoot.'/install.php');
    // Serving the real installer runs its body: it drops a code file next to
    // the web root and PHP limits inside it. Both are test litter to remove.
    $codeFile = dirname($docRoot).'/dochamster-install-code.txt';
    if (is_file($codeFile)) {
        unlink($codeFile);
    }
    [$proc, $base] = InstallerFixtures::startServer($docRoot);

    try {
        $context = stream_context_create(['http' => ['timeout' => 5]]);
        $body = @file_get_contents($base.'/install.php', false, $context);
        $cookies = [];
        foreach ($http_response_header ?? [] as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                $cookies[] = $header;
            }
        }
    } finally {
        InstallerFixtures::stopServer($proc);
        if (is_file($codeFile)) {
            unlink($codeFile);
        }
        InstallerFixtures::removeDir($docRoot);
    }

    // The welcome page proves the installer body ran instead of a static file;
    // plain HTTP must not set Secure (HTTPS wiring is covered by isHttpsRequest).
    expect($body)->toContain('Willkommen')
        ->and($cookies)->not->toBeEmpty();
    $session = implode("\n", $cookies);
    expect($session)->toContain('HttpOnly')
        ->and($session)->toContain('SameSite=Strict')
        ->and($session)->not->toContain('Secure');
});
