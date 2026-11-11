<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Text documents written in the app (point 21): plain text (.txt, .md) or formatted text (.html, sanitized).
 * They are documents like uploads – one stored file each – so download, folders, tags, trash and search
 * keep working; the search finds the plain text through ocr_text.
 */
final class TextDocument
{
    public const MAX_BYTES = 1_048_576;

    private const MIMES = ['text/plain', 'text/markdown', 'text/html'];

    public static function isText(?DocumentFile $file): bool
    {
        return $file !== null && in_array($file->mime, self::MIMES, true);
    }

    public static function isRich(?DocumentFile $file): bool
    {
        return $file !== null && $file->mime === 'text/html';
    }

    public static function create(string $orgId, string $userId, string $title, bool $rich, ?string $folderId = null): Document
    {
        $title = Str::limit(trim($title), 250, '');
        [$mime, $extension] = match (true) {
            $rich => ['text/html', 'html'],
            Str::endsWith(Str::lower($title), '.md') => ['text/markdown', 'md'],
            default => ['text/plain', 'txt'],
        };

        return DB::transaction(static function () use ($orgId, $userId, $title, $mime, $extension, $folderId): Document {
            $document = Document::query()->create([
                'org_id' => $orgId,
                'folder_id' => $folderId ?? Folder::defaultIdFor($orgId),
                'uploaded_by' => $userId,
                'title' => $title,
            ]);
            $path = $orgId.'/'.now()->format('Y/m').'/'.Str::lower((string) Str::ulid()).'.'.$extension;
            Storage::disk('local')->put($path, '');

            DocumentFile::query()->create([
                'org_id' => $orgId,
                'document_id' => $document->id,
                'path' => $path,
                'mime' => $mime,
                'size' => 0,
                'sha256' => hash('sha256', ''),
            ]);
            // Nothing to recognise: the text is known.
            $document->forceFill(['ocr_text' => '', 'ocr_status' => 'done'])->save();

            return $document;
        });
    }

    public static function read(DocumentFile $file): string
    {
        $content = Storage::disk('local')->get($file->path);

        return is_string($content) ? $content : '';
    }

    /** The content as it may be shown: formatted text is sanitized again on every read. */
    public static function display(DocumentFile $file): string
    {
        $content = self::read($file);

        return self::isRich($file) ? RichText::sanitize($content) : $content;
    }

    public static function save(Document $document, DocumentFile $file, string $content): void
    {
        $content = str_replace("\r\n", "\n", $content);
        $content = self::isRich($file) ? RichText::sanitize($content) : $content;
        $plain = self::isRich($file) ? RichText::toPlainText($content) : $content;

        Storage::disk('local')->put($file->path, $content);
        $file->update(['size' => strlen($content), 'sha256' => hash('sha256', $content)]);
        $document->forceFill(['ocr_text' => $plain, 'ocr_status' => 'done'])->save();
    }
}
