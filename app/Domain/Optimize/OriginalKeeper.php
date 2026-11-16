<?php

declare(strict_types=1);

namespace App\Domain\Optimize;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\Folder;
use App\Domain\Documents\Thumbnail;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Point 236: puts an optimised file in place. The document keeps its id, title, tags, properties, bank links, OCR
 * text and its file row (only path, type and size change); the original file moves to a new document of its own that
 * names the optimised one (`original_of`) – in the Papierkorb (F45, emptied after the trash period like anything
 * else) or in the archive folder. The original document copies the recognised text, if any, so the search finds it,
 * but it never takes part in bank matching or invoice reading.
 */
final class OriginalKeeper
{
    /** The suggested name of the archive folder. */
    public const string FOLDER_NAME = 'Originale';

    /**
     * @param  string  $optimized  absolute path of the new, smaller file
     */
    public static function swap(Document $document, DocumentFile $file, string $optimized, ShrinkResult $result, Organization $organization): Document
    {
        $settings = OptimizeSettings::of($organization);
        $folderId = $settings['original'] === OptimizeSettings::ARCHIVE ? self::archiveFolder($organization) : $document->folder_id;
        $disk = Storage::disk('local');
        $path = $document->org_id.'/'.now()->format('Y/m').'/'.strtolower((string) Str::ulid()).'.'.$result->extension;
        $disk->makeDirectory(dirname($path));
        if (! @rename($optimized, $disk->path($path)) && ! (@copy($optimized, $disk->path($path)) && @unlink($optimized))) {
            throw new RuntimeException('optimised file could not be stored');
        }
        $old = $file->only(['org_id', 'kind', 'page_no', 'path', 'mime', 'size', 'sha256', 'thumb_path', 'transform']); // point 238: the kept original shows turned/cropped like its words

        $original = DB::transaction(static function () use ($document, $file, $path, $result, $old, $folderId, $settings): Document {
            $original = new Document;
            $original->forceFill([
                'org_id' => $document->org_id,
                'folder_id' => $folderId,
                'uploaded_by' => $document->uploaded_by,
                'title' => $document->title,
                'doc_date' => $document->doc_date,
                'doc_date_source' => $document->doc_date_source,
                'doc_time' => $document->doc_time,
                'source' => $document->source,
                'in_print_tray' => false,
                'inbox_done_at' => now(),
                'original_of' => $document->id,
                ...($document->getAttribute('ocr_status') === 'done' ? $document->only(['ocr_text', 'ocr_words', 'ocr_status', 'ocr_pass', 'ocr_engine', 'ocr_read_at']) : []),
            ]);
            $original->created_at = $document->created_at;
            $original->save();
            if ($settings['original'] === OptimizeSettings::TRASH) {
                $original->delete();
            }

            $copy = new DocumentFile;
            $copy->forceFill([...$old, 'document_id' => $original->id, 'optimize' => 'original', 'optimize_at' => now()])->save();

            $file->forceFill([
                'path' => $path,
                'mime' => (string) $result->mime,
                'size' => $result->after,
                'sha256' => (string) hash_file('sha256', Storage::disk('local')->path($path)),
                'thumb_path' => null,
                'optimize' => ShrinkResult::DONE,
                'original_size' => $result->before,
                'optimize_at' => now(),
            ])->save();

            return $original;
        });

        // A new preview for the smaller file; where none can be made (a PDF without Imagick) the old one is copied.
        $thumb = Thumbnail::make($file, null);
        if ($thumb === null && is_string($old['thumb_path']) && $disk->exists($old['thumb_path'])) {
            $thumb = $path.'.thumb.jpg';
            $disk->copy($old['thumb_path'], $thumb);
        }
        $file->forceFill(['thumb_path' => $thumb])->save();

        return $original;
    }

    /** The archive folder from the settings; created ("Originale", no bank matching) when it is gone. */
    public static function archiveFolder(Organization $organization): string
    {
        $id = OptimizeSettings::of($organization)['folder'];
        if ($id !== null && Folder::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $organization->id)->whereKey($id)->exists()) {
            return $id;
        }
        $folder = new Folder;
        $folder->forceFill(['org_id' => $organization->id, 'name' => self::FOLDER_NAME, 'bank_matching' => false, 'is_default' => false])->save();
        OptimizeSettings::save($organization, ['folder' => $folder->id]);

        return $folder->id;
    }

    /** Whether a document only holds the original of an optimised one. */
    public static function isOriginal(string $documentId): bool
    {
        return Document::query()->withoutGlobalScopes()->whereKey($documentId)->whereNotNull('original_of')->exists();
    }
}
