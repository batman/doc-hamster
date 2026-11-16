<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\PageTransform;
use App\Domain\Documents\TextDocument;
use App\Domain\Documents\Thumbnail;
use App\Domain\Ocr\OcrWords;
use App\Domain\Ocr\ShownWords;
use App\Http\Controllers\Concerns\ScopesDocuments;
use App\Infrastructure\Environment\OcrProgress;
use App\Jobs\Local\RecognizeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Point 238: "Nach links/rechts drehen", "Zuschneiden" and "Zurücksetzen" in the detail's zoom bar. Saves at once,
 * non-destructive (A414): the page's rotation and crop box go into document_files.transform, the stored file and
 * the download stay the original. The word boxes and corrections move onto the new page, the preview is made again
 * (A415) and the document is read again (A417; values a person confirmed stay, InvoiceSuggestions).
 */
final class DocumentTransformController
{
    use ScopesDocuments;

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            // A416: multi-page PDFs – the page shown.
            'page' => ['required', 'integer', 'min:1', 'max:65535'],
            'rotation' => ['required', 'integer', 'in:0,90,180,270'],
            'crop' => ['nullable', 'array'],
            'crop.x' => ['required_with:crop', 'numeric', 'between:0,1'],
            'crop.y' => ['required_with:crop', 'numeric', 'between:0,1'],
            'crop.w' => ['required_with:crop', 'numeric', 'between:0,1'],
            'crop.h' => ['required_with:crop', 'numeric', 'between:0,1'],
        ]);
        $document = $this->visible($request)->whereKey($id)->firstOrFail();
        $file = DocumentFile::query()->where('document_id', $document->id)->orderBy('page_no')->first();
        abort_if($file === null || TextDocument::isText($file), 422);

        $page = (int) $data['page'];
        $old = $file->pageTransform($page);
        $crop = is_array($data['crop'] ?? null) ? $data['crop'] : null;
        $new = PageTransform::make((int) $data['rotation'], $crop);
        if ($new->toArray() !== $old->toArray()) {
            $file->setPageTransform($page, $new);
            $file->save();
            self::moveWords($document, $page, $old, $new);
            if ($page === 1) {
                Thumbnail::remake($file);
            }
            self::readAgain($document);
        }

        return response()->json([
            'page' => $page,
            'file' => $file->id,
            'transform' => $new->toArray(),
            'thumb' => $file->thumb_path !== null ? $file->thumbUrl() : null,
            'message' => __('documents.transform.saved'),
        ]);
    }

    /** Word boxes and corrections of the page from the old shown page onto the new one (until the new read is in). */
    private static function moveWords(Document $document, int $page, PageTransform $old, PageTransform $new): void
    {
        $words = ShownWords::words(OcrWords::raw($document), $page, $old, $new);
        $fixes = ShownWords::fixes(OcrWords::fixes($document), $page, $old, $new);
        $document->forceFill([
            'ocr_words' => OcrWords::encode($words),
            'ocr_fixes' => $fixes !== [] ? json_encode($fixes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ])->save();
    }

    /**
     * A417: read again. A document with text keeps it until the new read replaces it (the re-read flag, like F17,
     * also for the browser); one without text is queued like "Neu erkennen".
     */
    private static function readAgain(Document $document): void
    {
        $values = $document->ocr_status === 'done'
            ? ['ocr_rescan' => true]
            : ['ocr_status' => 'pending', 'ocr_pass' => null, 'ocr_failures' => 0, 'ocr_rescan' => false];
        $document->forceFill($values)->save();
        RecognizeText::dispatch($document->id);
        OcrProgress::forgetWaiting($document->org_id);
    }
}
