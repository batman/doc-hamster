<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Banking\TransactionMatch;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use App\Domain\Properties\PropertyValues;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Writes receipts into a ZIP for the tax advisor (MVP of PLAN §10): the files
 * by month plus `index.csv` (`;`, UTF-8 with BOM, opens in Excel). Originals
 * are added as they are, so large exports don't need much memory. The active
 * properties (Rechnungsbetrag, IBAN …) follow as columns of their own (A245).
 */
final class ReceiptArchive
{
    /**
     * @param  Collection<int, Document>  $documents
     * @param  iterable<Property>|null  $properties  the property columns; null = the active ones of the organisation
     */
    public static function write(Collection $documents, string $target, ?iterable $properties = null): void
    {
        $properties = collect($properties ?? Property::ordered(true))->values();
        $format = new PropertyValues;

        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the ZIP file.');
        }

        $index = fopen('php://temp', 'r+');
        if ($index === false) {
            throw new RuntimeException('Could not create the index.');
        }
        fwrite($index, "\xEF\xBB\xBF");
        fputcsv($index, ['Datum', 'Titel', 'Datei', 'Typ', 'Größe (Bytes)', 'SHA-256', 'Tags', 'Buchung', 'Buchungsnotiz', ...$properties->map(static fn (Property $p): string => self::safeCell($p->name))->all()], ';', '"', '');

        foreach ($documents as $document) {
            $values = $document->properties->keyBy('property_id');
            $cells = $properties->map(static function (Property $property) use ($values, $format): string {
                $value = $values->get($property->id);

                return $value instanceof DocumentProperty ? self::safeCell($format->display($value->setRelation('property', $property))) : '';
            })->all();
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
                    self::safeCell($document->tags->pluck('name')->implode(', ')),
                    self::safeCell(self::bookings($document)),
                    self::safeCell(self::bookingNotes($document)),
                    ...$cells,
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

    /** The linked bank transactions, without the purpose: that can hold more than this receipt. */
    private static function bookings(Document $document): string
    {
        return $document->matches
            ->map(static fn (TransactionMatch $match): string => trim(sprintf(
                '%s %s %s',
                $match->transaction->booking_date->format('d.m.Y'),
                $match->transaction->formattedAmount(),
                $match->transaction->counterparty,
            )))
            ->implode(' / ');
    }

    /** Point 147, A321: the notes of the linked bookings, next to them. */
    private static function bookingNotes(Document $document): string
    {
        return $document->matches->map(static fn (TransactionMatch $match): string => trim((string) $match->transaction->note))
            ->filter(static fn (string $note): bool => $note !== '')->implode(' / ');
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
