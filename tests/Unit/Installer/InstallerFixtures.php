<?php

declare(strict_types=1);

namespace Tests\Unit\Installer;

use RuntimeException;
use ZipArchive;

/**
 * Fixtures for the standalone web installer (installer/install.php).
 *
 * The installer ships as a single file, so the tests include it with DH_TESTING
 * defined: only its constants and functions load, the installer body is skipped.
 */
final class InstallerFixtures
{
    public static function loadInstaller(): void
    {
        if (! defined('DH_TESTING')) {
            define('DH_TESTING', true);
        }

        require_once dirname(__DIR__, 3).'/installer/install.php';
    }

    /** A fresh empty directory under the system temp dir; the caller removes it. */
    public static function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);

        return $dir;
    }

    public static function removeDir(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) {
            if (file_exists($dir) || is_link($dir)) {
                @unlink($dir);
            }

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeDir($dir.'/'.$entry);
        }
        @rmdir($dir);
    }

    /** Writes $files (relative path => content) into $dir, creating subfolders. */
    public static function writeFiles(string $dir, array $files): void
    {
        foreach ($files as $name => $content) {
            $path = $dir.'/'.$name;
            $parent = dirname($path);
            if (! is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            file_put_contents($path, $content);
        }
    }

    /** Creates a ZIP with the given entries (name => content, stored literally). */
    public static function makeZip(string $path, array $entries): void
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    public static function openZip(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        $zip->open($path);

        return $zip;
    }

    /**
     * Serves $docRoot over HTTP on a free loopback port; returns [process, baseUrl].
     * The caller stops the server with stopServer(). A token marker proves the
     * answers come from our server, not from a foreign port squatter.
     *
     * @return array{0: mixed, 1: string}
     */
    public static function startServer(string $docRoot): array
    {
        $token = bin2hex(random_bytes(8));
        file_put_contents($docRoot.'/.dh-server-check', $token);
        $context = stream_context_create(['http' => ['timeout' => 2]]);

        // Polling hits refused connections until the server is up; swallow those.
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            foreach (range(18931, 18971) as $port) {
                $proc = proc_open(
                    [PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $docRoot],
                    [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
                    $pipes
                );
                if (! is_resource($proc)) {
                    continue;
                }
                for ($i = 0; $i < 50; $i++) {
                    if (file_get_contents('http://127.0.0.1:'.$port.'/.dh-server-check', false, $context) === $token) {
                        return [$proc, 'http://127.0.0.1:'.$port];
                    }
                    usleep(100000);
                }
                self::stopServer($proc);
            }
        } finally {
            restore_error_handler();
        }

        throw new RuntimeException('No free port for the installer test server.');
    }

    public static function stopServer(mixed $proc): void
    {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    /**
     * Puts $entries behind a download URL the installer's curl download can fetch.
     * Returns [url, sha256].
     *
     * @return array{0: string, 1: string}
     */
    public static function serveZip(string $serverDir, string $serverUrl, string $name, array $entries): array
    {
        $path = $serverDir.'/'.$name;
        self::makeZip($path, $entries);

        return [$serverUrl.'/'.$name, hash_file('sha256', $path)];
    }

    public static mixed $serverProc = null;

    public static string $serverDir = '';

    public static string $serverUrl = '';

    /** One server per test file, started from beforeAll() (which has no $this). */
    public static function startSharedServer(): void
    {
        self::$serverDir = self::makeTempDir('dh-server');
        [self::$serverProc, self::$serverUrl] = self::startServer(self::$serverDir);
    }

    public static function stopSharedServer(): void
    {
        self::stopServer(self::$serverProc);
        self::removeDir(self::$serverDir);
        self::$serverProc = null;
    }

    /**
     * serveZip() against the shared server.
     *
     * @return array{0: string, 1: string}
     */
    public static function serveSharedZip(string $name, array $entries): array
    {
        return self::serveZip(self::$serverDir, self::$serverUrl, $name, $entries);
    }

    /** A GitHub release row shaped like the API returns it. */
    public static function makeRelease(string $version, array $overrides = []): array
    {
        return array_merge([
            'tag_name' => 'v'.$version,
            'draft' => false,
            'prerelease' => false,
            'assets' => [
                ['name' => 'manifest.json', 'browser_download_url' => 'https://example.com/'.$version.'/manifest.json'],
                ['name' => 'dochamster-'.$version.'.zip', 'browser_download_url' => 'https://example.com/'.$version.'/dochamster-'.$version.'.zip'],
            ],
        ], $overrides);
    }

    public static function makeManifest(string $version, string $php = '8.3.0'): array
    {
        return ['version' => $version, 'php' => $php, 'zip' => 'dochamster-'.$version.'.zip', 'sha256' => str_repeat('a', 64)];
    }

    /** True when $phpCode passes php -l. */
    public static function lints(string $phpCode): bool
    {
        $dir = self::makeTempDir('dh-lint');
        file_put_contents($dir.'/check.php', $phpCode);
        exec(PHP_BINARY.' -l '.escapeshellarg($dir.'/check.php').' 2>'.(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), $ignored, $code);
        self::removeDir($dir);

        return $code === 0;
    }
}
