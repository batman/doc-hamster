<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Writes receipts into a ZIP for the tax advisor (MVP of PLAN §10): the files
 * by month plus `index.csv` (`;`, UTF-8 with BOM, opens in Excel). Originals
 * are added as they are, so large exports don't need much memory.
 */
final class ReceiptArchive
{
    /**
     * @param  Collection<int, Document>  $documents
     */
    public static function write(Collection $documents, string $target): void
    {
        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the ZIP file.');
        }

        $index = fopen('php://temp', 'r+');
        if ($index === false) {
            throw new RuntimeException('Could not create the index.');
        }
        fwrite($index, "\xEF\xBB\xBF");
        fputcsv($index, ['Datum', 'Titel', 'Datei', 'Typ', 'Größe (Bytes)', 'SHA-256'], ';', '"', '');

        foreach ($documents as $document) {
            foreach ($document->files as $file) {
                $name = self::entryName($document, $file);
                $path = Storage::disk('local')->path($file->path);
                $found = is_file($path);

                if ($found) {
                    $zip->addFile($path, $name);
                    // Files are compressed already; storing them keeps the export fast.
                    $zip->setCompressionName($name, ZipArchive::CM_STORE);
                }

                fputcsv($index, [
                    $document->created_at->format('d.m.Y'),
                    self::safeCell($document->title),
                    $found ? $name : 'Datei fehlt',
                    $file->mime,
                    (string) $file->size,
                    $file->sha256,
                ], ';', '"', '');
            }
        }

        rewind($index);
        $zip->addFromString('index.csv', (string) stream_get_contents($index));
        fclose($index);

        if (! $zip->close()) {
            throw new RuntimeException('Could not write the ZIP file.');
        }
    }

    private static function entryName(Document $document, DocumentFile $file): string
    {
        $title = pathinfo($document->title, PATHINFO_FILENAME);
        $safe = Str::limit((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($title)), 60, '');
        $extension = pathinfo($file->path, PATHINFO_EXTENSION);

        return sprintf('belege/%s/%s_%s_%s.%s', $document->created_at->format('Y-m'), $document->created_at->format('Y-m-d'), $safe, Str::lower(substr($file->id, -6)), $extension);
    }

    /** File names come from users; a leading = + - @ would run as a formula in Excel. */
    private static function safeCell(string $value): string
    {
        return in_array($value[0] ?? '', ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
