<?php

declare(strict_types=1);

namespace Tests\Unit\Installer;

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
            @unlink($dir);

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
}
