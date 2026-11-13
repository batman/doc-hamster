<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Ocr\OcrWords;
use App\Domain\Org\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Point 119: the word positions of a document's recognised text, corrections applied (same visibility as the
 * detail view).
 */
final class DocumentWordsController extends Controller
{
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $userId = (string) $request->user()?->getAuthIdentifier();
        $document = Document::query()->visibleTo($userId, Gate::allows(Permission::ViewAllDocuments->value))
            ->whereKey($id)->firstOrFail();
        $words = OcrWords::forDocument($document);

        return response()->json(['pages' => $words['pages'] ?? []], 200, ['Cache-Control' => 'private, no-store']);
    }
}
