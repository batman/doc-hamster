<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Environment\SystemProbe;
use RuntimeException;

/**
 * A server that meets every minimum requirement and has every add-on tool.
 * Tests switch single properties off to provoke one finding at a time.
 */
final class FakeSystemProbe implements SystemProbe
{
    public string $phpVersion = '8.4.11';

    /** @var list<string> */
    public array $extensions = ['pdo_mysql', 'mbstring', 'intl', 'sodium', 'fileinfo', 'exif', 'zip', 'gd', 'vips'];

    /** @var array<string, int> */
    public array $ini = ['memory_limit' => 512 * 1024 * 1024, 'upload_max_filesize' => 32 * 1024 * 1024, 'post_max_size' => 32 * 1024 * 1024];

    /** @var list<string> */
    public array $disabledFunctions = [];

    /** @var list<string> */
    public array $executables = ['tesseract', 'ocrmypdf', 'whisper-cli', 'ffmpeg', 'heif-convert'];

    public ?string $databaseVersion = '10.11.6-MariaDB-0+deb12u1';

    public string $appUrl = 'https://dochamster.example.com';

    public string $environment = 'production';

    public int $now = 1_800_000_000;

    public ?int $lastHeartbeat = 1_800_000_000 - 60;

    public function phpVersion(): string
    {
        return $this->phpVersion;
    }

    public function extensionLoaded(string $name): bool
    {
        return in_array($name, $this->extensions, true);
    }

    public function iniBytes(string $key): int
    {
        return $this->ini[$key] ?? 0;
    }

    public function functionEnabled(string $name): bool
    {
        return ! in_array($name, $this->disabledFunctions, true);
    }

    public function executableExists(string $name): bool
    {
        return in_array($name, $this->executables, true);
    }

    public function databaseVersion(): string
    {
        return $this->databaseVersion ?? throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused (db.example.com as dochamster)');
    }

    public function appUrl(): string
    {
        return $this->appUrl;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function lastCronHeartbeat(): ?int
    {
        return $this->lastHeartbeat;
    }

    public function now(): int
    {
        return $this->now;
    }
}
