<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Api\ApiShape;
use App\Domain\Banking\CsvStatement;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Properties\DocumentProperty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The interface's document part (D-040, api/v1): the documents the person may see – as a list (folder, period,
 * search, "free" = in a folder with bank matching and not linked yet) and one at a time with its properties and its
 * recognised text. Text only (F3): no scans, no files.
 */
final class DocumentApiController
{
    private const PER_PAGE = 50;

    /** Longest text one answer carries. */
    private const TEXT_MAX = 50000;

    public function folders(): JsonResponse
    {
        return response()->json(['data' => Folder::query()->orderBy('name')->get()->map(static fn (Folder $f): array => [
            'id' => $f->id, 'name' => $f->name, 'parent_id' => $f->parent_id, 'bank_matching' => (bool) $f->bank_matching,
        ])->all()]);
    }

    public function documents(Request $request, CurrentOrg $org, ApiShape $shape): JsonResponse
    {
        $data = $request->validate([
            'free' => ['nullable', 'boolean'], 'folder' => ['nullable', 'string'], 'from' => ['nullable', 'string'], 'to' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $from = CsvStatement::normaliseDate((string) ($data['from'] ?? ''));
        $to = CsvStatement::normaliseDate((string) ($data['to'] ?? ''));
        $search = trim((string) ($data['q'] ?? ''));
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        $page = BankApiController::visible($org)
            ->when($request->boolean('free'), static fn (Builder $q) => $q->matchable()
                ->whereDoesntHave('matches', static fn (Builder $m) => $m->where('state', TransactionMatch::CONFIRMED)))
            ->when(($data['folder'] ?? null) !== null, static fn (Builder $q) => $q->where('folder_id', $data['folder']))
            ->when($from !== null, static fn (Builder $q) => $q->whereDate('doc_date', '>=', (string) $from))
            ->when($to !== null, static fn (Builder $q) => $q->whereDate('doc_date', '<=', (string) $to))
            ->when($search !== '', static fn (Builder $q) => $q->where(static fn (Builder $w) => $w->where('title', 'like', $like)
                ->orWhere('ocr_text', 'like', $like)->orWhereHas('properties', static fn (Builder $p) => $p->where('value_text', 'like', $like))))
            ->orderByDesc('doc_date')->orderByDesc('created_at')
            ->simplePaginate(self::PER_PAGE, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'data' => $shape->documents(collect($page->items())),
            'page' => $page->currentPage(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /** One document: what the list shows, every property and the recognised text. */
    public function document(string $id, CurrentOrg $org, ApiShape $shape): JsonResponse
    {
        $document = BankApiController::visible($org)->whereKey($id)->first() ?? abort(404, __('api.document_missing'));
        $text = (string) $document->getAttribute('ocr_text');

        return response()->json([
            ...$shape->documents(collect([$document]))[0],
            'note' => $document->note,
            'files' => $document->files()->get(['mime', 'size'])->map(static fn ($f): array => ['type' => $f->mime, 'size_bytes' => (int) $f->size])->all(),
            'properties' => $document->properties()->with('property')->get()->map(static fn (DocumentProperty $p): array => [
                'name' => $p->property->name,
                'value' => $p->value_text ?? ($p->value_int !== null ? ($p->property->type === 'amount' ? ApiShape::decimal($p->value_int).' '.($p->currency ?? '') : (string) $p->value_int) : $p->value_date?->format('Y-m-d')),
                'state' => $p->state,
            ])->values()->all(),
            'text' => mb_substr($text, 0, self::TEXT_MAX),
            'text_cut' => mb_strlen($text) > self::TEXT_MAX,
        ]);
    }
}
