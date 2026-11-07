<?php

declare(strict_types=1);

namespace App\Infrastructure\Environment;

/**
 * What the footer and sidebar show as the version: the release number from
 * the VERSION file (written by release.yml), else the git short hash when
 * this runs from a checkout, else 'dev' (e.g. install.php hosting).
 */
final class AppVersion
{
    public static function current(string $basePath): string
    {
        $file = $basePath.'/VERSION';
        if (is_file($file)) {
            $version = trim((string) file_get_contents($file));
            if ($version !== '') {
                return $version;
            }
        }

        return self::gitHash($basePath) ?? 'dev';
    }

    private static function gitHash(string $basePath): ?string
    {
        return self::readHeadFile($basePath);
    }

    /**
     * Plain .git/HEAD read: needs no git binary and trips no ownership
     * check.
     */
    private static function readHeadFile(string $basePath): ?string
    {
        $headFile = $basePath.'/.git/HEAD';
        if (! is_file($headFile)) {
            return null;
        }
        $head = trim((string) file_get_contents($headFile));
        if (preg_match('/^[0-9a-f]{40}$/', $head) === 1) {
            return substr($head, 0, 7);
        }
        if (! str_starts_with($head, 'ref: ')) {
            return null;
        }
        $ref = substr($head, 5);
        $refFile = $basePath.'/.git/'.$ref;
        if (! is_file($refFile)) {
            return self::packedRef($basePath, $ref);
        }
        $hash = trim((string) file_get_contents($refFile));

        return preg_match('/^[0-9a-f]{40}$/', $hash) === 1 ? substr($hash, 0, 7) : null;
    }

    /**
     * After `git gc` a branch hash sits in .git/packed-refs instead of its
     * own file. Read it there too, so no git process is ever started (this
     * runs on every request without a config cache).
     */
    private static function packedRef(string $basePath, string $ref): ?string
    {
        $packed = $basePath.'/.git/packed-refs';
        if (! is_file($packed)) {
            return null;
        }
        foreach (file($packed, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{40}) (\S+)$/', $line, $m) === 1 && $m[2] === $ref) {
                return substr($m[1], 0, 7);
            }
        }

        return null;
    }
}
