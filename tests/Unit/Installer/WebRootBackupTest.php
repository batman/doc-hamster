<?php

declare(strict_types=1);

use Tests\Unit\Installer\InstallerFixtures;

InstallerFixtures::loadInstaller();

beforeEach(function (): void {
    $this->dir = InstallerFixtures::makeTempDir('dh-backup');
});

afterEach(function (): void {
    InstallerFixtures::removeDir($this->dir);
});

it('writes a new file without a backup', function (): void {
    placeFile($this->dir.'/index.php', 'new', true);

    expect(file_get_contents($this->dir.'/index.php'))->toBe('new')
        ->and(glob($this->dir.'/index.php.bak-*'))->toBe([]);
});

it('keeps identical content without a backup', function (): void {
    InstallerFixtures::writeFiles($this->dir, ['index.php' => 'same']);

    placeFile($this->dir.'/index.php', 'same', true);

    expect(glob($this->dir.'/index.php.bak-*'))->toBe([]);
});

it('backs up a replaced file byte for byte', function (): void {
    InstallerFixtures::writeFiles($this->dir, ['index.php' => 'old bytes']);

    placeFile($this->dir.'/index.php', 'new bytes', true);

    $backups = glob($this->dir.'/index.php.bak-*') ?: [];
    expect($backups)->toHaveCount(1)
        ->and(file_get_contents($backups[0]))->toBe('old bytes')
        ->and(file_get_contents($this->dir.'/index.php'))->toBe('new bytes');
});

it('overwrites without a backup when keepOld is false', function (): void {
    InstallerFixtures::writeFiles($this->dir, ['index.php' => 'old']);

    placeFile($this->dir.'/index.php', 'new', false);

    expect(file_get_contents($this->dir.'/index.php'))->toBe('new')
        ->and(glob($this->dir.'/index.php.bak-*'))->toBe([]);
});

it('never clobbers an earlier backup', function (): void {
    InstallerFixtures::writeFiles($this->dir, ['index.php' => 'v1']);

    // A leftover backup from an earlier run in the same second.
    $first = backupPath($this->dir.'/index.php');
    file_put_contents($first, 'earlier backup');

    placeFile($this->dir.'/index.php', 'v2', true);

    $backups = glob($this->dir.'/index.php.bak-*') ?: [];
    sort($backups);
    expect($backups)->toHaveCount(2)
        ->and(file_get_contents($first))->toBe('earlier backup');
    $contents = array_map('file_get_contents', $backups);
    sort($contents);
    expect($contents)->toBe(['earlier backup', 'v1']);
});

it('moveToBackup is a no-op for a missing file', function (): void {
    moveToBackup($this->dir.'/nope.html');

    expect(glob($this->dir.'/*'))->toBe([]);
});

it('wires the web root with backup and leaves foreign files alone', function (): void {
    $app = $this->dir.'/app';
    $web = $this->dir.'/web';
    mkdir($app.'/public/css', 0777, true);
    mkdir($web, 0777, true);
    InstallerFixtures::writeFiles($app.'/public', [
        'index.php' => "<?php require __DIR__.'/../vendor/autoload.php';",
        '.htaccess' => 'new htaccess',
        'robots.txt' => 'new robots',
        'css/app.css' => 'new css',
        '.user.ini' => 'must not be copied',
    ]);
    // A previous site in the web root: entry files get replaced with backup,
    // everything else (other-site.php, placeholder page) stays untouched.
    InstallerFixtures::writeFiles($web, [
        'index.php' => 'old site entry',
        '.htaccess' => 'old htaccess',
        'index.html' => 'hoster placeholder',
        'other-site.php' => 'foreign code',
    ]);

    wireWebRoot($app, $web, true);

    expect(file_get_contents($web.'/robots.txt'))->toBe('new robots')
        ->and(file_get_contents($web.'/css/app.css'))->toBe('new css')
        ->and(is_file($web.'/.user.ini'))->toBeFalse()
        ->and(file_get_contents($web.'/other-site.php'))->toBe('foreign code')
        ->and(file_get_contents($web.'/index.html'))->toBe('hoster placeholder');
    $indexBackups = glob($web.'/index.php.bak-*') ?: [];
    $htaccessBackups = glob($web.'/.htaccess.bak-*') ?: [];
    expect($indexBackups)->toHaveCount(1)
        ->and(file_get_contents($indexBackups[0]))->toBe('old site entry')
        ->and($htaccessBackups)->toHaveCount(1)
        ->and(file_get_contents($htaccessBackups[0]))->toBe('old htaccess');
});

it('wires the web root without backup when keepOld is false', function (): void {
    $app = $this->dir.'/app';
    $web = $this->dir.'/web';
    mkdir($app.'/public', 0777, true);
    mkdir($web, 0777, true);
    InstallerFixtures::writeFiles($app.'/public', [
        'index.php' => "<?php require __DIR__.'/../vendor/autoload.php';",
    ]);
    InstallerFixtures::writeFiles($web, ['index.php' => 'old']);

    wireWebRoot($app, $web, false);

    expect(glob($web.'/index.php.bak-*'))->toBe([])
        ->and(file_get_contents($web.'/index.php'))->toContain($app);
});
