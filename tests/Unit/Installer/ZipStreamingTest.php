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
    $this->dir = InstallerFixtures::makeTempDir('dh-zipstream');
});

afterEach(function (): void {
    InstallerFixtures::removeDir($this->dir);
});

it('unpacks a well-formed package entry by entry', function (): void {
    [$url, $sha] = InstallerFixtures::serveSharedZip('good.zip', [
        'dochamster/' => '',
        'dochamster/artisan' => 'marker',
        'dochamster/public/index.php' => 'entry',
        'dochamster/storage/app/private/.gitkeep' => '',
    ]);

    $unpacked = unpackRelease(['url' => $url, 'sha256' => $sha], $this->dir.'/tmp', $this->dir.'/download.zip');

    expect($unpacked)->toBe($this->dir.'/tmp/dochamster')
        ->and(file_get_contents($unpacked.'/artisan'))->toBe('marker')
        ->and(file_get_contents($unpacked.'/public/index.php'))->toBe('entry')
        ->and(is_file($this->dir.'/download.zip'))->toBeFalse();
});

it('rejects hostile entry names before writing anything', function (string $entry): void {
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

it('rejects a hostile package end to end without extracting it', function (): void {
    [$url, $sha] = InstallerFixtures::serveSharedZip('evil.zip', [
        'dochamster/artisan' => 'marker',
        '../evil.php' => 'evil',
    ]);

    try {
        unpackRelease(['url' => $url, 'sha256' => $sha], $this->dir.'/tmp', $this->dir.'/download.zip');
        $thrown = false;
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage();
    }

    expect($thrown)->toBe('Unerwarteter Inhalt im Paket.')
        ->and(is_file($this->dir.'/evil.php'))->toBeFalse('nothing escaped the temp folder')
        ->and(is_file($this->dir.'/tmp/dochamster/artisan'))->toBeFalse('the pre-scan fails before any entry lands');
});

it('refuses symlink entries flagged in the headers', function (): void {
    $zipPath = $this->dir.'/link.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('dochamster/artisan', 'marker');
    $zip->addFromString('dochamster/link', '/etc/passwd');
    $zip->setExternalAttributesName('dochamster/link', ZipArchive::OPSYS_UNIX, (0120000 | 0777) << 16);
    $zip->close();

    $zip = InstallerFixtures::openZip($zipPath);

    try {
        expect(isZipSymlink($zip, 1))->toBeTrue()
            ->and(isZipSymlink($zip, 0))->toBeFalse();
        assertZipPaths($zip);
        $thrown = false;
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage();
    } finally {
        $zip->close();
    }

    expect($thrown)->toBe('Unerwarteter Inhalt im Paket.');
});

it('refuses a symlink package end to end', function (): void {
    $served = InstallerFixtures::$serverDir.'/link.zip';
    $zip = new ZipArchive;
    $zip->open($served, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('dochamster/artisan', 'marker');
    $zip->addFromString('dochamster/link', '../../outside.php');
    $zip->setExternalAttributesName('dochamster/link', ZipArchive::OPSYS_UNIX, (0120000 | 0777) << 16);
    $zip->close();
    $url = InstallerFixtures::$serverUrl.'/link.zip';
    $sha = hash_file('sha256', $served);

    try {
        unpackRelease(['url' => $url, 'sha256' => $sha], $this->dir.'/tmp', $this->dir.'/download.zip');
        $thrown = false;
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage();
    }

    expect($thrown)->toBe('Unerwarteter Inhalt im Paket.')
        ->and(is_file($this->dir.'/tmp/dochamster/artisan'))->toBeFalse();
});

it('refuses entries over the per-file cap', function (): void {
    $zipPath = $this->dir.'/big.zip';
    InstallerFixtures::makeZip($zipPath, [
        'dochamster/artisan' => 'marker',
        'dochamster/blob.bin' => str_repeat('a', 17 * 1024 * 1024),
    ]);

    $zip = InstallerFixtures::openZip($zipPath);

    try {
        assertZipPaths($zip);
        $thrown = false;
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage();
    } finally {
        $zip->close();
    }

    expect($thrown)->toBe('Das Paket ist zu groß und wurde abgewiesen.');
});

it('enforces the total-size cap while extracting', function (): void {
    $zipPath = $this->dir.'/total.zip';
    InstallerFixtures::makeZip($zipPath, [
        'dochamster/a.txt' => str_repeat('a', 60),
        'dochamster/b.txt' => str_repeat('b', 60),
    ]);

    // The header pre-scan accounts the claimed sizes first.
    $zip = InstallerFixtures::openZip($zipPath);
    try {
        assertZipPaths($zip, 100, DH_MAX_ZIP_FILE_BYTES, DH_MAX_ZIP_ENTRIES);
        $prescan = false;
    } catch (RuntimeException) {
        $prescan = true;
    } finally {
        $zip->close();
    }

    // The on-disk accounting re-checks the ground truth as entries land.
    $zip = InstallerFixtures::openZip($zipPath);
    try {
        extractZipEntries($zip, $this->dir.'/tmp', 100, DH_MAX_ZIP_FILE_BYTES);
        $extracted = false;
    } catch (RuntimeException) {
        $extracted = true;
    } finally {
        $zip->close();
    }

    expect($prescan)->toBeTrue()
        ->and($extracted)->toBeTrue();
});

it('enforces the entry-count cap', function (): void {
    $zipPath = $this->dir.'/many.zip';
    InstallerFixtures::makeZip($zipPath, [
        'dochamster/a.txt' => 'a',
        'dochamster/b.txt' => 'b',
        'dochamster/c.txt' => 'c',
    ]);

    $zip = InstallerFixtures::openZip($zipPath);

    try {
        assertZipPaths($zip, DH_MAX_ZIP_BYTES, DH_MAX_ZIP_FILE_BYTES, 2);
        $thrown = false;
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage();
    } finally {
        $zip->close();
    }

    expect($thrown)->toBe('Das Paket ist zu groß und wurde abgewiesen.');
});

it('extracts valid packages intact with sufficient caps', function (): void {
    $zipPath = $this->dir.'/ok.zip';
    InstallerFixtures::makeZip($zipPath, [
        'dochamster/' => '',
        'dochamster/artisan' => 'marker',
        'dochamster/nested/deep.txt' => 'deep',
    ]);

    $zip = InstallerFixtures::openZip($zipPath);
    assertZipPaths($zip, 1024, 1024, 10);
    extractZipEntries($zip, $this->dir.'/tmp', 1024, 1024);
    $zip->close();

    expect(file_get_contents($this->dir.'/tmp/dochamster/artisan'))->toBe('marker')
        ->and(file_get_contents($this->dir.'/tmp/dochamster/nested/deep.txt'))->toBe('deep');
});

it('keeps the caps far above the real release size', function (): void {
    // v0.1.0 unpacks to 29 MB in 7606 entries with a 0.7 MB biggest file.
    // These floors catch an accidental cap shrinkage that would break real installs.
    expect(DH_MAX_ZIP_BYTES)->toBeGreaterThanOrEqual(100 * 1024 * 1024)
        ->and(DH_MAX_ZIP_FILE_BYTES)->toBeGreaterThanOrEqual(4 * 1024 * 1024)
        ->and(DH_MAX_ZIP_ENTRIES)->toBeGreaterThanOrEqual(20000);
});
