<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Ocr\OcrFixes;
use App\Domain\Ocr\OcrWords;
use App\Domain\Org\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Point 119: the word positions of a document's recognised text, corrections applied (same visibility as the
 * detail view). Point 122.7/A243: corrections of single words by position, their undo, and the corrections
 * learned for the document's sender (offered in the reading view).
 */
final class DocumentWordsController extends Controller
{
    public function __construct(private readonly OcrFixes $fixes) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        return $this->answer($this->visible($request)->whereKey($id)->firstOrFail());
    }

    /** "Übernehmen" in the word popup: the word at page/box gets the text (the recognised text again = no fix). */
    public function fix(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([...self::placeRules(), 'text' => ['required', 'string', 'max:200']]);
        $document = $this->visible($request)->whereKey($id)->firstOrFail();
        $this->fixes->fix($document, (int) $data['page'], self::box($data), (string) $data['text']);

        return $this->answer($document->refresh(), __('documents.words.fixed'));
    }

    /** "Original anzeigen" → undo: the word at page/box shows the recognised text again. */
    public function unfix(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(self::placeRules());
        $document = $this->visible($request)->whereKey($id)->firstOrFail();
        $this->fixes->unfix($document, (int) $data['page'], self::box($data));

        return $this->answer($document->refresh(), __('documents.words.unfixed'));
    }

    private function answer(Document $document, ?string $message = null): JsonResponse
    {
        $words = OcrWords::forDocument($document);
        $fixes = array_map(static fn (array $f): array => ['p' => $f['p'], 'x' => $f['x'], 'y' => $f['y'], 'w' => $f['w'], 'h' => $f['h'], 'o' => $f['o']], OcrWords::fixes($document));

        return response()->json(array_filter([
            'pages' => $words['pages'] ?? [],
            'fixes' => $fixes,
            'learned' => (object) ($words !== null ? $this->fixes->learnedFor($document) : []),
            'text' => $message !== null ? (string) $document->getAttribute('ocr_text') : null,
            'message' => $message,
        ], static fn (mixed $v): bool => $v !== null), 200, ['Cache-Control' => 'private, no-store']);
    }

    /** @return Builder<Document> */
    private function visible(Request $request): Builder
    {
        $userId = (string) $request->user()?->getAuthIdentifier();

        return Document::query()->visibleTo($userId, Gate::allows(Permission::ViewAllDocuments->value));
    }

    /** @return array<string, list<string>> */
    private static function placeRules(): array
    {
        return [
            'page' => ['required', 'integer', 'min:1', 'max:65535'],
            'box' => ['required', 'array'],
            'box.x' => ['required', 'numeric', 'between:0,1'],
            'box.y' => ['required', 'numeric', 'between:0,1'],
            'box.w' => ['required', 'numeric', 'between:0,1'],
            'box.h' => ['required', 'numeric', 'between:0,1'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{x: float, y: float, w: float, h: float}
     */
    private static function box(array $data): array
    {
        $box = is_array($data['box'] ?? null) ? $data['box'] : [];

        return ['x' => (float) $box['x'], 'y' => (float) $box['y'], 'w' => (float) $box['w'], 'h' => (float) $box['h']];
    }
}
