<?php

declare(strict_types=1);

namespace App\Infrastructure\Environment;

use Symfony\Component\Process\Process;
use Throwable;

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
        return self::readHeadFile($basePath) ?? self::revParse($basePath);
    }

    /**
     * Plain .git/HEAD read: needs no git binary and trips no ownership
     * check. Anything exotic (worktrees, packed-refs) falls through to
     * rev-parse.
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
        $refFile = $basePath.'/.git/'.substr($head, 5);
        if (! is_file($refFile)) {
            return null;
        }
        $hash = trim((string) file_get_contents($refFile));

        return preg_match('/^[0-9a-f]{40}$/', $hash) === 1 ? substr($hash, 0, 7) : null;
    }

    private static function revParse(string $basePath): ?string
    {
        try {
            $process = new Process(
                ['git', 'rev-parse', '--short', 'HEAD'],
                $basePath,
                ['GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'safe.directory', 'GIT_CONFIG_VALUE_0' => $basePath],
                timeout: 5,
            );
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }
            $hash = trim($process->getOutput());

            return preg_match('/^[0-9a-f]{4,40}$/', $hash) === 1 ? $hash : null;
        } catch (Throwable) {
            return null;
        }
    }
}
