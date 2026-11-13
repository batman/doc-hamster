<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\TextDocument;
use App\Http\Controllers\Concerns\ScopesDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Protected file delivery: the file, the download and the small image, only for people who may see the document. */
final class DocumentFileController
{
    use ScopesDocuments;

    public function file(Request $request, string $id): Response
    {
        [$file, $document] = $this->visibleFile($request, $id);
        $document->forceFill(['last_opened_at' => now()])->saveQuietly();
        // Text documents are shown as plain text, never as a page of this site (point 21).
        $type = TextDocument::isText($file) ? 'text/plain; charset=UTF-8' : $file->mime;

        return Storage::disk('local')->response($file->path, null, ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function download(Request $request, string $id): Response
    {
        [$file, $document] = $this->visibleFile($request, $id);
        $document->forceFill(['last_downloaded_at' => now()])->saveQuietly();

        return Storage::disk('local')->download($file->path, $file->downloadName($document->title), ['Content-Type' => $file->mime]);
    }

    /** The stored small image; it never changes, so the browser may keep it. */
    public function thumb(Request $request, string $id): Response
    {
        [$file] = $this->visibleFile($request, $id);
        abort_if($file->thumb_path === null, 404);

        return Storage::disk('local')->response($file->thumb_path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /**
     * @return array{DocumentFile, Document}
     */
    private function visibleFile(Request $request, string $id): array
    {
        // Explicit query, not route binding: bindings run before the `org` middleware sets the scope.
        $file = DocumentFile::query()->whereKey($id)->firstOrFail();

        return [$file, $this->visible($request)->withPrintTray()->whereKey($file->document_id)->firstOrFail()];
    }
}
