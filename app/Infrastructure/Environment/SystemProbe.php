<?php

declare(strict_types=1);

namespace App\Infrastructure\Environment;

/**
 * Read-only view of the server environment. Implemented by NativeSystemProbe;
 * tests use a fake so every check can run without a real server or database.
 */
interface SystemProbe
{
    public function phpVersion(): string;

    public function extensionLoaded(string $name): bool;

    /** An ini size setting in bytes; -1 means unlimited. */
    public function iniBytes(string $key): int;

    /** True if the function exists and is not listed in `disable_functions`. */
    public function functionEnabled(string $name): bool;

    public function executableExists(string $name): bool;

    /**
     * Version string reported by the database server, e.g. "10.11.6-MariaDB" or "8.4.3".
     *
     * @throws \Throwable when the database can't be reached
     */
    public function databaseVersion(): string;

    public function appUrl(): string;

    public function environment(): string;

    /** Unix time of the last cron heartbeat, null if cron never ran. */
    public function lastCronHeartbeat(): ?int;

    public function now(): int;
}
