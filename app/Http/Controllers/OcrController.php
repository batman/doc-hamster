<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Org\Permission;
use App\Infrastructure\Environment\LocalOcr;
use App\Jobs\Net\SetUpOcr;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Text recognition set-up and the browser fallback (point 18, A16/A17).
 * The browser reads one image at a time and posts the text back; only for
 * receipts the user may edit (documents.edit) or uploaded themself.
 */
final class OcrController extends Controller
{
    /** Browsers can't decode these; the server converts them later. */
    private const array SKIP_MIMES = ['image/heic', 'image/heif'];

    public function setup(LocalOcr $ocr): RedirectResponse
    {
        $ocr->markRunning();
        SetUpOcr::dispatch();

        return back();
    }

    public function next(Request $request, LocalOcr $ocr): JsonResponse|Response
    {
        if ($ocr->mode() !== 'browser') {
            return response()->noContent();
        }
        $skip = array_slice(array_filter((array) $request->query('skip', []), 'is_string'), 0, 100);

        $document = $this->editable($request)
            ->where(static fn ($w) => $w->whereNull('ocr_status')->orWhereIn('ocr_status', ['pending', 'unavailable']))
            ->whereNotIn('id', $skip)
            ->whereHas('files', static fn ($f) => $f->where('mime', 'like', 'image/%')->whereNotIn('mime', self::SKIP_MIMES))
            ->latest('created_at')->first();
        if ($document === null) {
            return response()->noContent();
        }
        $file = DocumentFile::query()->where('document_id', $document->id)
            ->where('mime', 'like', 'image/%')->whereNotIn('mime', self::SKIP_MIMES)
            ->orderBy('page_no')->firstOrFail();

        return response()->json([
            'id' => $document->id,
            'image' => route('ocr.image', $file->id),
            'save' => route('ocr.store', $document->id),
        ]);
    }

    /** Serves the image without touching last_opened_at (unlike documents.file). */
    public function image(Request $request, string $id): StreamedResponse
    {
        $file = DocumentFile::query()->whereKey($id)->where('mime', 'like', 'image/%')->firstOrFail();
        $this->editable($request)->whereKey($file->document_id)->firstOrFail();

        return Storage::disk('local')->response($file->path, null, ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, string $id): Response
    {
        $data = $request->validate(['text' => ['nullable', 'string', 'max:200000']]);
        $document = $this->editable($request)->whereKey($id)->firstOrFail();
        $document->forceFill(['ocr_text' => trim((string) ($data['text'] ?? '')), 'ocr_status' => 'done'])->save();

        return response()->noContent();
    }

    /**
     * Org-scoped by the global scope; editors see what they may view, others only their own uploads.
     *
     * @return Builder<Document>
     */
    private function editable(Request $request): Builder
    {
        $userId = (string) $request->user()?->getAuthIdentifier();
        $query = Document::query()->visibleTo($userId, Gate::allows(Permission::ViewAllDocuments->value));

        return Gate::allows(Permission::EditDocuments->value) ? $query : $query->where('uploaded_by', $userId);
    }
}
