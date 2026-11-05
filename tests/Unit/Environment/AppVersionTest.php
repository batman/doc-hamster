<?php

declare(strict_types=1);

use App\Infrastructure\Environment\AppVersion;

it('prefers the VERSION file', function (): void {
    $dir = sys_get_temp_dir().'/dochamster-version-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/VERSION', "v0.1.17\n");

    expect(AppVersion::current($dir))->toBe('v0.1.17');

    unlink($dir.'/VERSION');
    rmdir($dir);
});

it('falls back to the git short hash inside a checkout', function (): void {
    $dir = sys_get_temp_dir().'/dochamster-version-'.uniqid();
    mkdir($dir);
    exec('git -C '.escapeshellarg($dir).' init -q 2>&1');
    exec('git -C '.escapeshellarg($dir).' -c user.email=t@t -c user.name=t commit -q --allow-empty -m x 2>&1');
    $hash = trim((string) shell_exec('git -C '.escapeshellarg($dir).' rev-parse --short HEAD'));

    expect($hash)->not->toBe('')
        ->and(AppVersion::current($dir))->toBe($hash);

    exec('rm -rf '.escapeshellarg($dir));
});

it('reads a detached HEAD too', function (): void {
    $dir = sys_get_temp_dir().'/dochamster-version-'.uniqid();
    mkdir($dir);
    exec('git -C '.escapeshellarg($dir).' init -q 2>&1');
    exec('git -C '.escapeshellarg($dir).' -c user.email=t@t -c user.name=t commit -q --allow-empty -m x 2>&1');
    exec('git -C '.escapeshellarg($dir).' checkout -q --detach 2>&1');
    $hash = trim((string) shell_exec('git -C '.escapeshellarg($dir).' rev-parse --short HEAD'));

    expect(AppVersion::current($dir))->toBe($hash);

    exec('rm -rf '.escapeshellarg($dir));
});

it('says dev without VERSION file or git', function (): void {
    $dir = sys_get_temp_dir().'/dochamster-version-'.uniqid();
    mkdir($dir);

    // A temp dir is no git checkout (unless TMPDIR itself sits in one).
    if (trim((string) shell_exec('git -C '.escapeshellarg($dir).' rev-parse --is-inside-work-tree 2>/dev/null')) === 'true') {
        $this->markTestSkipped('TMPDIR is inside a git checkout.');
    }

    expect(AppVersion::current($dir))->toBe('dev');

    rmdir($dir);
});
