<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Jobs\Local\RecognizeText;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Takes in one file: checked by its bytes, stored outside the web root
 * (PLAN §11.2), with its small preview. Used by the upload and the printer.
 */
final class DocumentIntake
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'application/pdf' => 'pdf',
    ];

    /**
     * From the file's bytes – client name and client MIME type can say anything.
     *
     * @return array{string, string}|null MIME type and file extension, null for anything else
     */
    public static function detect(mixed $upload): ?array
    {
        if (! $upload instanceof UploadedFile) {
            return null;
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath());

        return isset(self::EXTENSIONS[$mime]) ? [$mime, self::EXTENSIONS[$mime]] : null;
    }

    /**
     * @param  list<string>  $tagIds
     */
    public static function store(UploadedFile $upload, string $mime, string $extension, string $orgId, string $userId, string $title, array $tagIds = [], bool $inPrintTray = false, ?string $folderId = null): Document
    {
        $document = DB::transaction(static function () use ($upload, $mime, $extension, $orgId, $userId, $title, $tagIds, $inPrintTray, $folderId): Document {
            $document = Document::query()->create([
                'org_id' => $orgId,
                'folder_id' => $folderId ?? Folder::defaultIdFor($orgId),
                'uploaded_by' => $userId,
                'title' => Str::limit($title, 250, ''),
                'in_print_tray' => $inPrintTray,
            ]);
            $path = $upload->storeAs($orgId.'/'.now()->format('Y/m'), Str::lower((string) Str::ulid()).'.'.$extension, 'local');

            DocumentFile::query()->create([
                'org_id' => $orgId,
                'document_id' => $document->id,
                'path' => (string) $path,
                'mime' => $mime,
                'size' => (int) $upload->getSize(),
                'sha256' => (string) hash_file('sha256', $upload->getRealPath()),
            ]);
            $document->tags()->sync($tagIds);

            return $document;
        });

        // Once, at upload: lists show only this small copy (design point 14).
        $file = $document->files()->firstOrFail();
        $file->update(['thumb_path' => Thumbnail::make($file)]);

        // Text for the search, in the background (cron worker, local queue).
        $document->forceFill(['ocr_status' => 'pending'])->save();
        RecognizeText::dispatch($document->id);

        return $document;
    }
}
