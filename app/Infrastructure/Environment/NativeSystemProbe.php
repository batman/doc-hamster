<?php

declare(strict_types=1);

namespace App\Infrastructure\Environment;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

final class NativeSystemProbe implements SystemProbe
{
    /** Written every minute by the scheduler (routes/console.php). */
    public const string HEARTBEAT_CACHE_KEY = 'dochamster.cron_heartbeat';

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly Cache $cache,
        private readonly DatabaseManager $db,
        private readonly ExecutableFinder $finder = new ExecutableFinder,
    ) {}

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }

    public function iniBytes(string $key): int
    {
        $value = ini_get($key);

        if ($value === false || $value === '') {
            return 0;
        }

        return trim($value) === '-1' ? -1 : ini_parse_quantity($value);
    }

    public function functionEnabled(string $name): bool
    {
        if (! function_exists($name)) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array($name, $disabled, true);
    }

    public function executableExists(string $name): bool
    {
        return $this->finder->find($name) !== null;
    }

    public function databaseVersion(): string
    {
        // `SELECT VERSION()` instead of the PDO attribute: older MariaDB servers
        // report "5.5.5-10.x" there for MySQL protocol compatibility.
        $row = $this->db->connection()->selectOne('select version() as version');

        return is_object($row) && isset($row->version) ? (string) $row->version : '';
    }

    public function appUrl(): string
    {
        $url = $this->config->get('app.url');

        return is_string($url) ? $url : '';
    }

    public function environment(): string
    {
        return $this->app->environment();
    }

    public function lastCronHeartbeat(): ?int
    {
        try {
            $value = $this->cache->get(self::HEARTBEAT_CACHE_KEY);
        } catch (Throwable) {
            // The cache lives in the database; the database check reports that problem.
            return null;
        }

        return is_int($value) ? $value : null;
    }

    public function now(): int
    {
        return time();
    }
}
