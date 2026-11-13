<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Org\Permission;
use App\Infrastructure\Environment\LocalOcr;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Which documents and files the OCR pages and the browser fallback may touch (shared by the Ocr*Controller classes).
 */
final class OcrAccess
{
    public const string PDF = 'application/pdf';

    public const array SKIP_MIMES = LocalOcr::BROWSER_SKIP_MIMES;

    /**
     * Point 69: in mode app the server reads the images; the browser gets only the PDFs.
     *
     * @template TQuery of Builder<DocumentFile>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function pdfs(Builder $query): Builder
    {
        return $query->where('mime', self::PDF);
    }

    /**
     * Files the browser can read: images it decodes, and PDFs.
     *
     * @template TQuery of Builder<DocumentFile>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function readable(Builder $query): Builder
    {
        return $query->where(static fn (Builder $w) => $w
            ->where(static fn (Builder $i) => $i->where('mime', 'like', 'image/%')->whereNotIn('mime', self::SKIP_MIMES))
            ->orWhere('mime', self::PDF));
    }

    /**
     * What this person may view (org-scoped by the global scope).
     *
     * @return Builder<Document>
     */
    public static function visible(Request $request): Builder
    {
        return Document::query()->visibleTo((string) $request->user()?->getAuthIdentifier(), Gate::allows(Permission::ViewAllDocuments->value));
    }

    /**
     * Org-scoped by the global scope; editors see what they may view, others only their own uploads.
     *
     * @return Builder<Document>
     */
    public static function editable(Request $request): Builder
    {
        $userId = (string) $request->user()?->getAuthIdentifier();
        $query = Document::query()->visibleTo($userId, Gate::allows(Permission::ViewAllDocuments->value));

        return Gate::allows(Permission::EditDocuments->value) ? $query : $query->where('uploaded_by', $userId);
    }

    public static function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
