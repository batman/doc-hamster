<?php

declare(strict_types=1);

namespace App\Infrastructure\Environment;

use Throwable;

/**
 * Checks the server against the published minimum requirements (D-010) and
 * reports which add-ons can be offered. Used by `dochamster:doctor`,
 * `dochamster:setup` and later the admin page.
 */
final class Doctor
{
    public const string MIN_PHP = '8.4.0';

    public const string MIN_MARIADB = '10.11.0';

    public const string MIN_MYSQL = '8.4.0';

    /** @var list<string> */
    public const array REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'intl', 'sodium', 'fileinfo', 'exif', 'zip', 'gd'];

    public const int MIN_MEMORY_BYTES = 256 * 1024 * 1024;

    /** 25 MB per file (AK-4.1.6) plus request overhead, D-017. */
    public const int MIN_UPLOAD_BYTES = 26 * 1024 * 1024;

    /** Cron runs every minute; older heartbeats mean background jobs stall. */
    public const int MAX_HEARTBEAT_AGE_SECONDS = 5 * 60;

    public function __construct(private readonly SystemProbe $probe) {}

    /**
     * @return list<CheckResult>
     */
    public function run(): array
    {
        $results = [
            $this->phpVersion(),
            $this->extensions(),
            $this->memoryLimit(),
            $this->uploadLimit('upload_max_filesize'),
            $this->uploadLimit('post_max_size'),
            $this->database(),
            $this->https(),
            $this->cron(),
            $this->procOpen(),
        ];

        foreach (Addon::cases() as $addon) {
            $results[] = $this->addon($addon);
        }

        return $results;
    }

    public function isAvailable(Addon $addon): bool
    {
        if ($addon->needsProcOpen() && ! $this->probe->functionEnabled('proc_open')) {
            return false;
        }

        foreach ($addon->extensions() as $extension) {
            if (! $this->probe->extensionLoaded($extension)) {
                return false;
            }
        }

        foreach ($addon->executables() as $executable) {
            if (! $this->probe->executableExists($executable)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<CheckResult>  $results
     */
    public static function hasFailures(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->status === CheckStatus::Fail) {
                return true;
            }
        }

        return false;
    }

    private function phpVersion(): CheckResult
    {
        $version = $this->probe->phpVersion();
        $ok = version_compare($version, self::MIN_PHP, '>=');

        return new CheckResult('php_version', $ok ? CheckStatus::Ok : CheckStatus::Fail,
            $ok ? 'version' : 'version_too_old', ['version' => $version, 'minimum' => self::MIN_PHP]);
    }

    private function extensions(): CheckResult
    {
        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            fn (string $extension): bool => ! $this->probe->extensionLoaded($extension),
        ));

        return $missing === []
            ? new CheckResult('php_extensions', CheckStatus::Ok, 'all_present')
            : new CheckResult('php_extensions', CheckStatus::Fail, 'missing', ['missing' => implode(', ', $missing)]);
    }

    private function memoryLimit(): CheckResult
    {
        $bytes = $this->probe->iniBytes('memory_limit');
        $ok = $bytes === -1 || $bytes >= self::MIN_MEMORY_BYTES;

        return new CheckResult('memory_limit', $ok ? CheckStatus::Ok : CheckStatus::Fail,
            $ok ? 'size' : 'size_too_small',
            ['size' => self::formatBytes($bytes), 'minimum' => self::formatBytes(self::MIN_MEMORY_BYTES)]);
    }

    private function uploadLimit(string $key): CheckResult
    {
        $bytes = $this->probe->iniBytes($key);
        // post_max_size = 0 means "no limit"; upload_max_filesize = 0 means "no uploads".
        $unlimited = $bytes === -1 || ($key === 'post_max_size' && $bytes === 0);
        $ok = $unlimited || $bytes >= self::MIN_UPLOAD_BYTES;

        return new CheckResult($key, $ok ? CheckStatus::Ok : CheckStatus::Fail,
            $ok ? 'size' : 'size_too_small',
            ['size' => self::formatBytes($bytes), 'minimum' => self::formatBytes(self::MIN_UPLOAD_BYTES)]);
    }

    private function database(): CheckResult
    {
        try {
            $version = $this->probe->databaseVersion();
        } catch (Throwable) {
            // The exception text may contain host or user names – never show it here.
            return new CheckResult('database', CheckStatus::Fail, 'db_unreachable');
        }

        $isMariaDb = stripos($version, 'mariadb') !== false;
        $number = preg_match('/^\d+\.\d+(\.\d+)?/', $version, $match) === 1 ? $match[0] : '0';
        $minimum = $isMariaDb ? self::MIN_MARIADB : self::MIN_MYSQL;
        $ok = version_compare($number, $minimum, '>=');
        $params = ['server' => $isMariaDb ? 'MariaDB' : 'MySQL', 'version' => $number, 'minimum' => $minimum];

        return new CheckResult('database', $ok ? CheckStatus::Ok : CheckStatus::Fail,
            $ok ? 'db_version' : 'db_version_too_old', $params);
    }

    private function https(): CheckResult
    {
        if (str_starts_with(strtolower($this->probe->appUrl()), 'https://')) {
            return new CheckResult('https', CheckStatus::Ok, 'https_ok');
        }

        // Plain HTTP is fine on a developer machine, never in production (D-010).
        $isDev = in_array($this->probe->environment(), ['local', 'testing'], true);

        return new CheckResult('https', $isDev ? CheckStatus::Warning : CheckStatus::Fail, 'https_missing');
    }

    private function cron(): CheckResult
    {
        $last = $this->probe->lastCronHeartbeat();

        if ($last === null) {
            return new CheckResult('cron', CheckStatus::Warning, 'cron_never');
        }

        $age = $this->probe->now() - $last;

        return $age <= self::MAX_HEARTBEAT_AGE_SECONDS
            ? new CheckResult('cron', CheckStatus::Ok, 'cron_ok', ['minutes' => (string) intdiv($age, 60)])
            : new CheckResult('cron', CheckStatus::Warning, 'cron_stale', ['minutes' => (string) intdiv($age, 60)]);
    }

    private function procOpen(): CheckResult
    {
        return $this->probe->functionEnabled('proc_open')
            ? new CheckResult('proc_open', CheckStatus::Ok, 'proc_open_ok')
            : new CheckResult('proc_open', CheckStatus::Warning, 'proc_open_disabled');
    }

    private function addon(Addon $addon): CheckResult
    {
        if ($this->isAvailable($addon)) {
            return new CheckResult('addon_'.$addon->value, CheckStatus::Ok, 'addon_available');
        }

        $missing = [];
        if ($addon->needsProcOpen() && ! $this->probe->functionEnabled('proc_open')) {
            $missing[] = 'proc_open';
        }
        foreach ($addon->extensions() as $extension) {
            if (! $this->probe->extensionLoaded($extension)) {
                $missing[] = 'ext-'.$extension;
            }
        }
        foreach ($addon->executables() as $executable) {
            if (! $this->probe->executableExists($executable)) {
                $missing[] = $executable;
            }
        }

        return new CheckResult('addon_'.$addon->value, CheckStatus::Warning, 'addon_missing',
            ['missing' => implode(', ', $missing)]);
    }

    private static function formatBytes(int $bytes): string
    {
        return match (true) {
            $bytes === -1 => '∞',
            $bytes >= 1024 * 1024 * 1024 => round($bytes / (1024 * 1024 * 1024), 1).' GB',
            $bytes >= 1024 * 1024 => round($bytes / (1024 * 1024)).' MB',
            default => $bytes.' B',
        };
    }
}
