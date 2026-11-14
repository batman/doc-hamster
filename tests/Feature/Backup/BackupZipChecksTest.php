<?php

declare(strict_types=1);

use App\Domain\Backup\BackupWriter;
use App\Domain\Backup\Restore\Manifest;
use App\Domain\Backup\Restore\RestoreFailed;
use App\Domain\Backup\Restore\RestoreSource;
use App\Domain\Backup\Restore\ZipGuard;
use App\Domain\Backup\Zip\StreamZip;
use Illuminate\Support\Facades\Storage;

/*
| Point 206.9: the restore treats the ZIP as untrusted – entry names, special entries, zip-bomb limits, manifest and
| checksums. No database: these checks run before anything is written. Synthetic content only.
*/

const BACKUP_TEST_SCHEMA = '2026_11_16_100000_create_backups_table';
const BACKUP_TEST_ORG = '01JD0000000000000000000000';

beforeEach(function (): void {
    Storage::fake('local');
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dh-backup-test-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function (): void {
    array_map('unlink', glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: []);
    rmdir($this->dir);
});

/**
 * @param  array<string, string>  $entries  name => content
 */
function backupZip(string $dir, array $entries, ?Closure $tweak = null): string
{
    $path = $dir.DIRECTORY_SEPARATOR.bin2hex(random_bytes(4)).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    if ($tweak !== null) {
        $tweak($zip);
    }
    $zip->close();

    return $path;
}

/**
 * @param  array<string, string>  $files  name => content
 * @param  array<string, mixed>  $override
 */
function backupManifest(array $files, array $override = []): string
{
    $list = [];
    foreach ($files as $name => $content) {
        $list[] = ['path' => $name, 'size' => strlen($content), 'sha256' => hash('sha256', $content), 'type' => 'data'];
    }

    return (string) json_encode([...['format' => BackupWriter::FORMAT, 'format_version' => 1, 'app_version' => '1.0.0', 'schema_version' => BACKUP_TEST_SCHEMA,
        'org_id' => BACKUP_TEST_ORG, 'created_by' => null, 'full' => true, 'files' => $list], ...$override]);
}

/**
 * @return array<string, array{index: int, size: int, encrypted: bool}>
 */
function backupGuard(string $path, int $maxEntries = 100, int $maxBytes = 1 << 30, int $ratio = 200): array
{
    $zip = new ZipArchive;
    $zip->open($path);
    try {
        return ZipGuard::entries($zip, ZipGuard::BACKUP_ROOT, $maxEntries, $maxBytes, $ratio);
    } finally {
        $zip->close();
    }
}

function backupFails(Closure $run, string $reason): void
{
    try {
        $run();
    } catch (RestoreFailed $e) {
        expect($e->reason)->toBe($reason);

        return;
    }
    test()->fail("Expected the restore to stop with {$reason}.");
}

it('accepts the names of a backup', function (string $name): void {
    ZipGuard::checkName($name, ZipGuard::BACKUP_ROOT);
    expect(true)->toBeTrue();
})->with(['manifest.json', 'data/documents.json', 'dokumente/2026-01/rechnung_01JD0000000000000000000000.pdf', 'abgeleitet/x_vorschau.jpg']);

it('rejects paths that could leave the staging folder or are unexpected', function (string $name, string $reason): void {
    backupFails(fn () => ZipGuard::checkName($name, ZipGuard::BACKUP_ROOT), $reason);
})->with([
    ['/etc/passwd', 'bad_path'],
    ['../outside.json', 'bad_path'],
    ['data/../../outside.json', 'bad_path'],
    ['data/./x.json', 'bad_path'],
    ['data//x.json', 'bad_path'],
    ['C:/windows/x.json', 'bad_path'],
    ['data\\x.json', 'bad_path'],
    ["data/x\x01.json", 'bad_path'],
    ['evil/x.json', 'unknown_entry'],
    ['data', 'unknown_entry'],
]);

it('rejects symlinks, duplicate names and too many entries', function (): void {
    $link = backupZip($this->dir, ['data/a.json' => '[]'], fn (ZipArchive $zip) => $zip->setExternalAttributesName('data/a.json', ZipArchive::OPSYS_UNIX, 0o120777 << 16));
    backupFails(fn () => backupGuard($link), 'special_entry');

    backupFails(fn () => backupGuard(backupZip($this->dir, ['data/A.json' => '[]', 'data/a.json' => '[]'])), 'duplicate');
    backupFails(fn () => backupGuard(backupZip($this->dir, ['data/a.json' => '[]', 'data/b.json' => '[]', 'data/c.json' => '[]']), maxEntries: 2), 'too_many_entries');
});

it('stops zip bombs by ratio and by total size', function (): void {
    $bomb = backupZip($this->dir, ['data/a.json' => str_repeat('0', 4 * 1024 * 1024)]);

    backupFails(fn () => backupGuard($bomb), 'ratio');
    backupFails(fn () => backupGuard(backupZip($this->dir, ['data/a.json' => str_repeat('x', 2000)]), maxBytes: 1000), 'too_large');
});

it('checks the manifest against the entries and refuses a newer schema', function (): void {
    $files = ['data/documents.json' => '[]', 'LIESMICH.txt' => 'hallo'];
    $entries = backupGuard(backupZip($this->dir, [...$files, 'manifest.json' => backupManifest($files)]));

    expect(Manifest::parse(backupManifest($files), $entries, BACKUP_TEST_SCHEMA)->totalBytes())->toBe(7);

    backupFails(fn () => Manifest::parse(backupManifest($files), $entries, '2026_01_01_000000_older'), 'newer');
    backupFails(fn () => Manifest::parse(backupManifest(['data/documents.json' => '[]']), $entries, BACKUP_TEST_SCHEMA), 'unlisted');
    backupFails(fn () => Manifest::parse(backupManifest([...$files, 'data/tags.json' => '[]']), $entries, BACKUP_TEST_SCHEMA), 'missing_file');
    backupFails(fn () => Manifest::parse(backupManifest(['data/documents.json' => '[ ]', 'LIESMICH.txt' => 'hallo']), $entries, BACKUP_TEST_SCHEMA), 'checksum');
    backupFails(fn () => Manifest::parse('{"format":"something-else"}', $entries, BACKUP_TEST_SCHEMA), 'manifest');
});

it('verifies every staged file against its SHA-256 and cleans up', function (): void {
    $files = ['data/documents.json' => '[{"id":"x"}]'];
    $manifest = (string) json_encode([...json_decode(backupManifest($files), true), 'files' => [['path' => 'data/documents.json', 'size' => 12, 'sha256' => hash('sha256', '[{"id":"y"}]'), 'type' => 'data']]]);
    $source = new RestoreSource(backupZip($this->dir, [...$files, 'manifest.json' => $manifest]), null, BACKUP_TEST_SCHEMA);

    backupFails(fn () => $source->open(), 'checksum');
    expect(is_dir($source->dir))->toBeFalse();

    $good = (new RestoreSource(backupZip($this->dir, [...$files, 'manifest.json' => backupManifest($files)]), null, BACKUP_TEST_SCHEMA))->open();
    expect(file_get_contents($good->path('data/documents.json')))->toBe('[{"id":"x"}]');
    $good->delete();
});

it('writes a streamed ZIP that ZipArchive reads back byte for byte', function (): void {
    $path = $this->dir.DIRECTORY_SEPARATOR.'stream.zip';
    $out = fopen($path, 'wb');
    $zip = new StreamZip($out);
    [$size, $sha] = $zip->addString('data/a.json', '{"a":1}');
    $zip->addString('LIESMICH.txt', str_repeat('ä', 3000));
    $zip->finish();
    fclose($out);

    $read = new ZipArchive;
    expect($read->open($path, ZipArchive::CHECKCONS))->toBeTrue()
        ->and($read->getFromName('data/a.json'))->toBe('{"a":1}')
        ->and($read->getFromName('LIESMICH.txt'))->toBe(str_repeat('ä', 3000))
        ->and([$size, $sha])->toBe([7, hash('sha256', '{"a":1}')]);
    $read->close();
});
