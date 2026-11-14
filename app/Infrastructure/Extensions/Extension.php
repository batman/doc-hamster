<?php

declare(strict_types=1);

namespace App\Infrastructure\Extensions;

use InvalidArgumentException;

/**
 * One entry of the fixed extension catalogue (point 173, Einstellungen → Erweiterungen).
 *
 * Two kinds:
 *  - DATA: a data pack – files only, no code (OCR languages, later translation models). Every file has a pinned
 *    URL, SHA-256 and size; it lands in storage/app/<dir>/<name>.
 *  - MODULE: a module with code (e.g. a bank connection, D-021) – one zip with a pinned SHA-256 and size. Planned
 *    install: unpack to modules/<key>, check its module.json with ModuleManifest, register the service provider
 *    named there, run its migrations; removing unregisters it and keeps its data unless asked. Not installable yet:
 *    such entries show "kommt später" (installable() is false), there is no install path for them.
 *
 * dir is below storage/app for data packs and below the app root for modules (modules/<key>).
 * A group names the feature the pack belongs to ('ocr', 'translate'), meta carries what that feature needs
 * (e.g. ['lang' => 'ell'] for an OCR language). requires lists catalogue keys installed along with it (172.4: the
 * translation runtime under every language pair; "Griechisch → Deutsch" = el-en + en-de, a bundle without own files).
 */
final readonly class Extension
{
    public const string DATA = 'data';

    public const string MODULE = 'module';

    /**
     * @param  list<array{name: string, url: string, sha256: string, size: int}>  $files
     * @param  array<string, string>  $meta
     * @param  list<string>  $requires
     */
    public function __construct(
        public string $key,
        public string $kind,
        public string $group,
        public ?string $version,
        public array $files,
        public string $dir,
        public array $meta = [],
        public array $requires = [],
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $key) !== 1) {
            throw new InvalidArgumentException('Bad extension key: '.$key);
        }
        if (! in_array($kind, [self::DATA, self::MODULE], true)) {
            throw new InvalidArgumentException('Bad extension kind: '.$kind);
        }
        if (preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*$#', $dir) !== 1) {
            throw new InvalidArgumentException('Bad extension dir: '.$dir);
        }
        foreach ($files as $file) {
            if (preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/', $file['name']) !== 1 || str_contains($file['name'], '..')
                || ! str_starts_with($file['url'], 'https://') || preg_match('/^[0-9a-f]{64}$/', $file['sha256']) !== 1 || $file['size'] <= 0) {
                throw new InvalidArgumentException('Bad extension file in '.$key);
            }
        }
    }

    /** Only complete data packs (or bundles of them) can be installed; modules come later (see the class comment). */
    public function installable(): bool
    {
        return $this->kind === self::DATA && $this->version !== null && ($this->files !== [] || $this->requires !== []);
    }

    /** Shown on Einstellungen → Erweiterungen (the translation runtime is only installed along with a pair). */
    public function listed(): bool
    {
        return ($this->meta['hidden'] ?? '') !== '1';
    }

    /** Download size in bytes (0 = not known yet). */
    public function size(): int
    {
        return array_sum(array_column($this->files, 'size'));
    }

    /**
     * The storage paths (relative to storage/app) of the pack's files.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(fn (array $f): string => $this->dir.'/'.$f['name'], $this->files);
    }
}
