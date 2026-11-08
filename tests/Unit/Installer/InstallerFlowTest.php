<?php

declare(strict_types=1);

use Tests\Unit\Installer\InstallerBrowser;
use Tests\Unit\Installer\InstallerFixtures;

InstallerFixtures::loadInstaller();

it('installs a fitting release end to end', function (): void {
    // Build the fixture package first: the manifest carries its real SHA-256.
    $zipDir = InstallerFixtures::makeTempDir('dh-flow-zip');
    try {
        InstallerFixtures::makeZip($zipDir.'/release.zip', [
            'dochamster/artisan' => 'marker',
            'dochamster/VERSION' => '0.2.0',
            'dochamster/public/index.php' => "<?php require __DIR__.'/../vendor/autoload.php'; echo 'hi';",
            'dochamster/public/.htaccess' => 'Options -Indexes',
            'dochamster/public/js/app.js' => 'console.log(1);',
        ]);
        $zipBytes = (string) file_get_contents($zipDir.'/release.zip');
    } finally {
        InstallerFixtures::removeDir($zipDir);
    }

    [$routes, $files] = InstallerBrowser::releaseRoutes('0.2.0', '8.3.0', hash('sha256', $zipBytes));
    $files[InstallerBrowser::zipPath('0.2.0')] = $zipBytes;
    $browser = InstallerBrowser::start($routes, $files);
    try {
        $checks = $browser->unlock(['X-Forwarded-Proto: https']);

        expect($checks)->toContain('name="install"')
            ->and(preg_match('/name="csrf" value="([^"]+)"/', $checks, $csrf))->toBe(1);

        [$status, , $done] = $browser->post('/install.php', ['csrf' => $csrf[1], 'install' => '1'], ['X-Forwarded-Proto: https']);

        expect($status)->toBe(200)
            ->and($done)->toContain('Dateien installiert');

        // The app landed outside the web root; the web root only holds the entry.
        expect(is_file($browser->parent.'/dochamster/artisan'))->toBeTrue();
        $index = (string) file_get_contents($browser->webRoot.'/index.php');

        expect($index)->toContain(var_export($browser->parent.'/dochamster/', true))
            ->and($index)->not->toContain("__DIR__.'/../'")
            ->and(InstallerFixtures::lints($index))->toBeTrue()
            ->and(is_file($browser->webRoot.'/js/app.js'))->toBeTrue();

        // The setup code in the handover link matches the stored one.
        expect(preg_match('/\/setup\?code=([^"\']+)/', $done, $link))->toBe(1);
        $setupCode = trim((string) file_get_contents($browser->parent.'/dochamster/storage/app/private/setup-code.txt'));

        expect(rawurldecode($link[1]))->toBe($setupCode);

        // Temp files, the code file and the installer itself are gone.
        expect(is_file($browser->webRoot.'/install.php'))->toBeFalse('the installer deletes itself')
            ->and(is_file($browser->parent.'/dochamster-install-code.txt'))->toBeFalse()
            ->and(is_dir($browser->parent.'/dochamster-install-tmp'))->toBeFalse()
            ->and(is_file($browser->parent.'/dochamster-download.zip'))->toBeFalse();
    } finally {
        $browser->stop();
    }
});
