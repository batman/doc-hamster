<?php

declare(strict_types=1);

namespace App\Infrastructure\Extensions;

use RuntimeException;

/**
 * The module.json of a code module (point 173.1 b), checked before a module would be registered:
 *   {"key": "revolut", "version": "1.0.0", "provider": "DocHamster\\Revolut\\RevolutServiceProvider", "migrations": "database/migrations"}
 * key and version must match the catalogue entry, provider is a namespaced class name inside the module,
 * migrations a relative path inside the module (optional). Installing code modules is not available yet
 * (Extension::installable()); this is the check that step will use, after the zip passed Extensions::verify().
 */
final readonly class ModuleManifest
{
    private function __construct(
        public string $key,
        public string $version,
        public string $provider,
        public ?string $migrations,
    ) {}

    /** @param array<mixed> $json the decoded module.json */
    public static function check(array $json, Extension $entry): self
    {
        $key = $json['key'] ?? null;
        $version = $json['version'] ?? null;
        $provider = $json['provider'] ?? null;
        $migrations = $json['migrations'] ?? null;
        if ($entry->kind !== Extension::MODULE || $key !== $entry->key) {
            throw new RuntimeException('module.json: falscher Schlüssel');
        }
        if (! is_string($version) || $version !== $entry->version) {
            throw new RuntimeException('module.json: falsche Version');
        }
        if (! is_string($provider) || preg_match('/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)+$/', $provider) !== 1) {
            throw new RuntimeException('module.json: Provider ungültig');
        }
        if ($migrations !== null && (! is_string($migrations) || preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $migrations) !== 1)) {
            throw new RuntimeException('module.json: Migrationspfad ungültig');
        }

        return new self($key, $version, $provider, $migrations);
    }
}
