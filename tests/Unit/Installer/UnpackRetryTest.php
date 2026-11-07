<?php

declare(strict_types=1);

use Tests\Unit\Installer\InstallerFixtures;

InstallerFixtures::loadInstaller();

beforeAll(function (): void {
    InstallerFixtures::startSharedServer();
});

afterAll(function (): void {
    InstallerFixtures::stopSharedServer();
});

beforeEach(function (): void {
    $this->dir = InstallerFixtures::makeTempDir('dh-unpack');
});

afterEach(function (): void {
    InstallerFixtures::removeDir($this->dir);
});

it('accepts a well-formed package', function (): void {
    $zipPath = $this->dir.'/good.zip';
    InstallerFixtures::makeZip($zipPath, [
        'dochamster/' => '',
        'dochamster/artisan' => 'marker',
        'dochamster/public/index.php' => 'entry',
        'dochamster/storage/app/private/.gitkeep' => '',
    ]);

    $zip = InstallerFixtures::openZip($zipPath);
    assertZipPaths($zip);
    $zip->close();

    expect(true)->toBeTrue();
});

it('rejects hostile entry names', function (string $entry): void {
    $zipPath = $this->dir.'/evil.zip';
    InstallerFixtures::makeZip($zipPath, ['dochamster/artisan' => 'marker', $entry => 'evil']);

    $zip = InstallerFixtures::openZip($zipPath);

    try {
        assertZipPaths($zip);
        $thrown = false;
    } catch (RuntimeException) {
        $thrown = true;
    } finally {
        $zip->close();
    }

    expect($thrown)->toBeTrue();
})->with([
    'parent escape' => ['../evil.php'],
    'nested parent escape' => ['dochamster/../../evil.php'],
    'bare parent' => ['dochamster/..'],
    'deep parent escape' => ['dochamster/sub/../../../evil.php'],
    'missing prefix' => ['evil.php'],
    'absolute path' => ['/etc/cron.d/evil'],
    'prefix without slash' => ['dochamster'],
    'backslash escape' => ['dochamster\\..\\evil.php'],
    'uppercase prefix' => ['DOCHAMSTER/artisan'],
]);

it('unpacks to a temp folder and leaves the app dir to the caller', function (): void {
    [$url, $sha] = InstallerFixtures::serveSharedZip('release.zip', ['dochamster/artisan' => 'marker']);
    $tmp = $this->dir.'/tmp';
    $appDir = $this->dir.'/dochamster';

    $unpacked = unpackRelease(['url' => $url, 'sha256' => $sha], $tmp, $this->dir.'/download.zip');

    expect($unpacked)->toBe($tmp.'/dochamster')
        ->and(is_file($unpacked.'/artisan'))->toBeTrue()
        ->and(is_file($this->dir.'/download.zip'))->toBeFalse()
        ->and(is_dir($appDir))->toBeFalse('rename into place is the caller job, the marker appears last');
});

it('refuses a checksum mismatch without extracting', function (): void {
    [$url] = InstallerFixtures::serveSharedZip('release-bad-sha.zip', ['dochamster/artisan' => 'marker']);
    $tmp = $this->dir.'/tmp';

    expect(fn () => unpackRelease(['url' => $url, 'sha256' => str_repeat('0', 64)], $tmp, $this->dir.'/download.zip'))
        ->toThrow(RuntimeException::class, 'beschädigt');

    expect(is_dir($tmp.'/dochamster'))->toBeFalse()
        ->and(is_dir($this->dir.'/dochamster'))->toBeFalse();
});

it('stops a hostile package before anything is extracted', function (): void {
    [$url, $sha] = InstallerFixtures::serveSharedZip('evil.zip', ['dochamster/artisan' => 'marker', '../evil.php' => 'evil']);
    $tmp = $this->dir.'/tmp';

    expect(fn () => unpackRelease(['url' => $url, 'sha256' => $sha], $tmp, $this->dir.'/download.zip'))
        ->toThrow(RuntimeException::class, 'Unerwarteter Inhalt');

    expect(is_dir($tmp.'/dochamster'))->toBeFalse()
        ->and(is_file($this->dir.'/evil.php'))->toBeFalse()
        ->and(is_dir($this->dir.'/dochamster'))->toBeFalse();
});

it('can be retried after a failure', function (): void {
    [$evilUrl, $evilSha] = InstallerFixtures::serveSharedZip('retry-evil.zip', ['evil.php' => 'evil']);
    [$goodUrl, $goodSha] = InstallerFixtures::serveSharedZip('retry-good.zip', ['dochamster/artisan' => 'marker']);
    $tmp = $this->dir.'/tmp';
    $appDir = $this->dir.'/dochamster';

    try {
        unpackRelease(['url' => $evilUrl, 'sha256' => $evilSha], $tmp, $this->dir.'/dl.zip');
        $failed = false;
    } catch (RuntimeException) {
        $failed = true;
    }
    expect($failed)->toBeTrue()
        ->and(is_file($appDir.'/artisan'))->toBeFalse('no marker after a failed run');

    $unpacked = unpackRelease(['url' => $goodUrl, 'sha256' => $goodSha], $tmp, $this->dir.'/dl.zip');

    expect(rename($unpacked, $appDir))->toBeTrue()
        ->and(is_file($appDir.'/artisan'))->toBeTrue('the marker appears with the final rename');
});

it('cleans the temp folder without following symlinks', function (): void {
    $outside = $this->dir.'/outside';
    $tmp = $this->dir.'/tmp';
    mkdir($outside, 0777, true);
    mkdir($tmp, 0777, true);
    file_put_contents($outside.'/precious.txt', 'data');
    symlink($outside.'/precious.txt', $tmp.'/link-file');
    symlink($outside, $tmp.'/link-dir');

    removeTree($tmp);

    expect(is_dir($tmp))->toBeFalse()
        ->and(file_get_contents($outside.'/precious.txt'))->toBe('data');
});
