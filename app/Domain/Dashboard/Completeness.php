<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use Illuminate\Support\Carbon;

/**
 * A55 "Vollständigkeit": what the tax advisor will miss, month by month (last twelve). A month is red with bookings
 * that still have no document, yellow when linked documents lack an amount, a date or a sender, else green. Only what
 * the person may see.
 */
final class Completeness
{
    /** What every linked receipt should carry. */
    public const REQUIRED = ['invoice_amount', 'invoice_date', 'sender'];

    public const MONTHS = 12;

    public function __construct(private readonly string $userId, private readonly bool $seesAll, private readonly bool $banking) {}

    /**
     * @return list<array{month: Carbon, open: int, incomplete: int, state: string}> newest first; state ok|warning|error
     */
    public function months(): array
    {
        $first = Carbon::today()->startOfMonth()->subMonths(self::MONTHS - 1);
        $open = [];
        if ($this->banking) {
            foreach (Transaction::query()->status(Transaction::OPEN)->where('booking_date', '>=', $first->toDateString())->pluck('booking_date') as $day) {
                $key = Carbon::parse($day)->format('Y-m');
                $open[$key] = ($open[$key] ?? 0) + 1;
            }
        }
        $incomplete = [];
        $required = Property::query()->whereIn('key', self::REQUIRED)->pluck('id')->all();
        if ($required !== []) {
            $documents = Document::query()->visibleTo($this->userId, $this->seesAll)
                ->whereIn('documents.id', TransactionMatch::query()->where('state', TransactionMatch::CONFIRMED)->select('document_id'))
                ->where(static fn ($q) => $q->where('doc_date', '>=', $first->toDateString())->orWhere(static fn ($q) => $q->whereNull('doc_date')->where('created_at', '>=', $first)))
                ->get(['documents.id', 'documents.doc_date', 'documents.created_at']);
            $have = DocumentProperty::query()->whereIn('document_id', $documents->modelKeys())->whereIn('property_id', $required)
                ->where(static fn ($q) => $q->whereNotNull('value_text')->orWhereNotNull('value_int')->orWhereNotNull('value_date'))
                ->toBase()->selectRaw('document_id, count(distinct property_id) as n')->groupBy('document_id')->pluck('n', 'document_id');
            foreach ($documents as $document) {
                if ((int) ($have[$document->id] ?? 0) < count($required)) {
                    $key = ($document->doc_date ?? $document->created_at)->format('Y-m');
                    $incomplete[$key] = ($incomplete[$key] ?? 0) + 1;
                }
            }
        }

        $rows = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $month = Carbon::today()->startOfMonth()->subMonths($i);
            $key = $month->format('Y-m');
            $o = $open[$key] ?? 0;
            $n = $incomplete[$key] ?? 0;
            $rows[] = ['month' => $month, 'open' => $o, 'incomplete' => $n, 'state' => $o > 0 ? 'error' : ($n > 0 ? 'warning' : 'ok')];
        }

        return $rows;
    }
}
