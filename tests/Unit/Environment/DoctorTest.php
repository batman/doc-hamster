<?php

declare(strict_types=1);

use App\Infrastructure\Environment\Addon;
use App\Infrastructure\Environment\CheckResult;
use App\Infrastructure\Environment\CheckStatus;
use App\Infrastructure\Environment\Doctor;
use Tests\Support\FakeSystemProbe;

function check(FakeSystemProbe $probe, string $key): CheckResult
{
    foreach ((new Doctor($probe))->run() as $result) {
        if ($result->key === $key) {
            return $result;
        }
    }

    throw new LogicException("No check named {$key}");
}

it('passes everything on a server that meets the minimum requirements', function (): void {
    $results = (new Doctor(new FakeSystemProbe))->run();

    expect(Doctor::hasFailures($results))->toBeFalse()
        ->and(array_map(fn (CheckResult $r): CheckStatus => $r->status, $results))
        ->each->toBe(CheckStatus::Ok);
});

it('fails on PHP older than 8.4', function (): void {
    $probe = new FakeSystemProbe;
    $probe->phpVersion = '8.3.14';

    expect(check($probe, 'php_version'))
        ->status->toBe(CheckStatus::Fail)
        ->detail->toBe('version_too_old');
});

it('lists every missing required extension', function (): void {
    $probe = new FakeSystemProbe;
    $probe->extensions = ['pdo_mysql', 'mbstring', 'fileinfo', 'exif', 'zip', 'gd'];

    expect(check($probe, 'php_extensions'))
        ->status->toBe(CheckStatus::Fail)
        ->params->toBe(['missing' => 'intl, sodium']);
});

it('fails when memory or upload limits are too small', function (string $key, int $bytes): void {
    $probe = new FakeSystemProbe;
    $probe->ini[$key] = $bytes;

    expect(check($probe, $key)->status)->toBe(CheckStatus::Fail);
})->with([
    'memory_limit 128 MB' => ['memory_limit', 128 * 1024 * 1024],
    'upload_max_filesize 2 MB' => ['upload_max_filesize', 2 * 1024 * 1024],
    'upload_max_filesize 0 (no uploads)' => ['upload_max_filesize', 0],
    'post_max_size 8 MB' => ['post_max_size', 8 * 1024 * 1024],
]);

it('treats unlimited limits as ok', function (string $key, int $bytes): void {
    $probe = new FakeSystemProbe;
    $probe->ini[$key] = $bytes;

    expect(check($probe, $key)->status)->toBe(CheckStatus::Ok);
})->with([
    'memory_limit -1' => ['memory_limit', -1],
    'post_max_size 0' => ['post_max_size', 0],
]);

it('checks the database version per server type', function (string $version, CheckStatus $expected): void {
    $probe = new FakeSystemProbe;
    $probe->databaseVersion = $version;

    expect(check($probe, 'database')->status)->toBe($expected);
})->with([
    'MariaDB 10.11' => ['10.11.6-MariaDB-0+deb12u1', CheckStatus::Ok],
    'MariaDB 11.8' => ['11.8.3-MariaDB', CheckStatus::Ok],
    'MariaDB 10.6 too old' => ['10.6.18-MariaDB', CheckStatus::Fail],
    'MySQL 8.4' => ['8.4.3', CheckStatus::Ok],
    'MySQL 8.0 too old' => ['8.0.39', CheckStatus::Fail],
]);

it('never leaks connection details when the database is unreachable', function (): void {
    $probe = new FakeSystemProbe;
    $probe->databaseVersion = null;

    $result = check($probe, 'database');

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->detail)->toBe('db_unreachable')
        ->and($result->params)->toBe([]);
});

it('requires HTTPS in production but only warns on a developer machine', function (string $environment, CheckStatus $expected): void {
    $probe = new FakeSystemProbe;
    $probe->appUrl = 'http://localhost:8000';
    $probe->environment = $environment;

    expect(check($probe, 'https')->status)->toBe($expected);
})->with([
    'production' => ['production', CheckStatus::Fail],
    'local' => ['local', CheckStatus::Warning],
]);

it('warns when cron never ran or stopped running', function (?int $heartbeatAge, string $detail): void {
    $probe = new FakeSystemProbe;
    $probe->lastHeartbeat = $heartbeatAge === null ? null : $probe->now - $heartbeatAge;

    expect(check($probe, 'cron'))
        ->status->toBe(CheckStatus::Warning)
        ->detail->toBe($detail);
})->with([
    'never' => [null, 'cron_never'],
    '10 minutes ago' => [600, 'cron_stale'],
]);

it('offers an add-on only when all its tools are present', function (): void {
    $probe = new FakeSystemProbe;
    $probe->executables = ['tesseract'];
    $doctor = new Doctor($probe);

    expect($doctor->isAvailable(Addon::Ocr))->toBeFalse()
        ->and(check($probe, 'addon_ocr'))
        ->status->toBe(CheckStatus::Warning)
        ->params->toBe(['missing' => 'ocrmypdf']);
});

it('turns off every tool-based add-on when proc_open is disabled, without failing the core', function (): void {
    $probe = new FakeSystemProbe;
    $probe->disabledFunctions = ['proc_open'];
    $doctor = new Doctor($probe);

    expect($doctor->isAvailable(Addon::Ocr))->toBeFalse()
        ->and($doctor->isAvailable(Addon::SpeechToText))->toBeFalse()
        ->and($doctor->isAvailable(Addon::Heic))->toBeFalse()
        ->and($doctor->isAvailable(Addon::Vips))->toBeTrue()
        ->and(Doctor::hasFailures($doctor->run()))->toBeFalse();
});
