<?php

declare(strict_types=1);

namespace App\Domain\Backup\Zip;

/**
 * Where a backup's entries go: straight to the browser (StreamZip) or into a file (ArchiveZip). Each add answers
 * the entry's size and SHA-256 for the manifest.
 */
interface ZipSink
{
    /**
     * @return array{int, string} size in bytes, SHA-256 hex
     */
    public function addString(string $name, string $contents): array;

    /**
     * @param  string  $path  a file on the server's disk
     * @return array{int, string} size in bytes, SHA-256 hex
     */
    public function addFile(string $name, string $path): array;

    public function finish(): void;
}
