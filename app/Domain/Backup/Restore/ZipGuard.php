<?php

declare(strict_types=1);

namespace App\Domain\Backup\Restore;

use Normalizer;
use ZipArchive;

/**
 * The first look at an untrusted ZIP (D-023, AK-4.8.7) – before a single byte is extracted: entry names (no
 * absolute paths, `..`, drive letters, backslashes, control characters, unknown top folders, duplicates after
 * case folding + NFC), no symlinks or other special entries, and zip-bomb limits (entry count, total size,
 * compression ratio).
 */
final class ZipGuard
{
    /** Top-level names of a backup; files only at the root, folders below. */
    public const array BACKUP_ROOT = ['manifest.json', 'LIESMICH.txt', 'data/', 'csv/', 'dokumente/', 'abgeleitet/'];

    /** A password-protected backup: a note and the encrypted inner ZIP (D-023). */
    public const array PROTECTED_ROOT = ['LIESMICH.txt', 'backup.zip'];

    /** Ratio only matters for entries above this size: tiny files compress well and harmlessly. */
    private const int RATIO_FROM = 1 << 20;

    /**
     * @param  list<string>  $root  allowed root files and folders (folders end in /)
     * @return array<string, array{index: int, size: int, encrypted: bool}> file entries by name
     */
    public static function entries(ZipArchive $zip, array $root, int $maxEntries, int $maxBytes, int $maxRatio): array
    {
        if ($zip->numFiles > $maxEntries) {
            throw new RestoreFailed('too_many_entries');
        }
        $entries = [];
        $seen = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new RestoreFailed('unreadable');
            }
            $name = (string) $stat['name'];
            $isDir = str_ends_with($name, '/');
            self::checkName($isDir ? substr($name, 0, -1) : $name, $root, $isDir);

            $folded = self::fold($name);
            if (isset($seen[$folded])) {
                throw new RestoreFailed('duplicate', ['name' => $name]);
            }
            $seen[$folded] = true;

            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                $type = ($attr >> 16) & 0xF000;
                if ($type !== 0 && $type !== 0x8000 && ! ($isDir && $type === 0x4000)) {
                    throw new RestoreFailed('special_entry', ['name' => $name]);
                }
            }
            if ($isDir) {
                if ($stat['size'] !== 0) {
                    throw new RestoreFailed('special_entry', ['name' => $name]);
                }

                continue;
            }

            $size = (int) $stat['size'];
            $packed = (int) $stat['comp_size'];
            $total += $size;
            if ($total > $maxBytes) {
                throw new RestoreFailed('too_large');
            }
            if ($size > self::RATIO_FROM && ($packed === 0 || $size / $packed > $maxRatio)) {
                throw new RestoreFailed('ratio', ['name' => $name]);
            }
            $entries[$name] = ['index' => $i, 'size' => $size, 'encrypted' => $stat['encryption_method'] !== ZipArchive::EM_NONE];
        }

        return $entries;
    }

    /**
     * One entry name (without a trailing / for folders).
     *
     * @param  list<string>  $root
     */
    public static function checkName(string $name, array $root, bool $isDir = false): void
    {
        if ($name === '' || strlen($name) > 255 || ! mb_check_encoding($name, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F\\\\:]/', $name) === 1 || str_starts_with($name, '/')) {
            throw new RestoreFailed('bad_path', ['name' => self::printable($name)]);
        }
        $segments = explode('/', $name);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RestoreFailed('bad_path', ['name' => self::printable($name)]);
            }
        }
        $top = count($segments) === 1 && ! $isDir ? $segments[0] : $segments[0].'/';
        if (! in_array($top, $root, true)) {
            throw new RestoreFailed('unknown_entry', ['name' => self::printable($name)]);
        }
    }

    /** Names that would land on the same file on a case-insensitive or normalising file system. */
    public static function fold(string $name): string
    {
        $normal = class_exists(Normalizer::class) ? Normalizer::normalize($name, Normalizer::FORM_C) : $name;

        return mb_strtolower(is_string($normal) ? $normal : $name, 'UTF-8');
    }

    private static function printable(string $name): string
    {
        return mb_substr((string) preg_replace('/[^\x20-\x7E]/', '?', $name), 0, 120);
    }
}
