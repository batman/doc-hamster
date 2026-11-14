<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Documents\Document;
use App\Domain\Documents\Inbox;
use App\Domain\Log\LogEntry;
use App\Domain\Notes\Note;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * What the Home widgets show (A349), for one person in the current organisation. Every query stays inside what the
 * person may see (documents: PLAN §2.1 visibility; notes: own; activity: own events).
 */
final class DashboardWidgets
{
    /** "Letzte Aktivitäten": the events of the logbook "Allgemein" worth showing, with their symbol. */
    public const array ACTIVITY = [
        'document.uploaded' => 'upload',
        'document.created' => 'file-plus',
        'document.moved' => 'folder',
        'document.paid' => 'cash',
        'bank.matched' => 'link',
        'bank.confirmed' => 'link',
        'bank.imported' => 'building-bank',
    ];

    /** Fällige Rechnungen: due within this many days (or overdue) count in the greeting's summary. */
    public const int DUE_SOON_DAYS = 7;

    /** @var array<string, string>|null property key => id (due_date, paid_at, invoice_amount), read once */
    private ?array $propertyIds = null;

    public function __construct(private readonly string $userId, private readonly bool $seesAll) {}

    /**
     * Posteingang: the newest five.
     *
     * @return Collection<int, Document>
     */
    public function inbox(int $limit = 5): Collection
    {
        return Inbox::query($this->userId, $this->seesAll)->latest()->limit($limit)->get();
    }

    /**
     * Fällige Rechnungen: unpaid documents (no paid_at) with a due date, the nearest (or longest overdue) first.
     *
     * @return list<array{document: Document, due: Carbon, amount: ?string, overdue: bool}>
     */
    public function due(int $limit = 8): array
    {
        $ids = $this->propertyIds();
        $dueId = $ids['due_date'] ?? null;
        if (! is_string($dueId)) {
            return [];
        }
        $documents = $this->dueQuery($dueId, $ids['paid_at'] ?? null)
            ->orderBy('due.value_date')->orderBy('documents.id')->limit($limit)
            ->get(['documents.*', 'due.value_date as due_on']);

        $amountId = $ids['invoice_amount'] ?? null;
        $amounts = is_string($amountId) && $documents->isNotEmpty()
            ? DocumentProperty::query()->where('property_id', $amountId)->whereIn('document_id', $documents->modelKeys())->get()->keyBy('document_id')
            : collect();
        $today = Carbon::today();

        return array_values($documents->map(static function (Document $document) use ($amounts, $today): array {
            $due = Carbon::parse((string) $document->getAttribute('due_on'))->startOfDay();
            $amount = $amounts->get($document->id);

            return [
                'document' => $document,
                'due' => $due,
                'amount' => $amount instanceof DocumentProperty && $amount->value_int !== null ? Transaction::money($amount->value_int, $amount->currency ?? 'EUR') : null,
                'overdue' => $due->lt($today),
            ];
        })->all());
    }

    /** How many unpaid documents are due within DUE_SOON_DAYS or overdue (greeting summary). */
    public function dueSoon(): int
    {
        $ids = $this->propertyIds();
        $dueId = $ids['due_date'] ?? null;

        return is_string($dueId)
            ? $this->dueQuery($dueId, $ids['paid_at'] ?? null)->where('due.value_date', '<=', Carbon::today()->addDays(self::DUE_SOON_DAYS)->toDateString())->count()
            : 0;
    }

    /**
     * Offene Buchungen: bookings without a document per account, with their sum.
     *
     * @return list<array{account: BankAccount, count: int, sum: string}>
     */
    public function bookings(): array
    {
        $rows = Transaction::query()->status(Transaction::OPEN)->toBase()
            ->selectRaw('bank_account_id, currency, count(*) as n, sum(amount_minor) as total')
            ->groupBy('bank_account_id', 'currency')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $accounts = BankAccount::query()->whereKey($rows->pluck('bank_account_id')->unique()->all())->get()->keyBy('id');

        $list = [];
        foreach ($rows as $row) {
            $account = $accounts->get($row->bank_account_id);
            if ($account instanceof BankAccount) {
                $list[] = ['account' => $account, 'count' => (int) $row->n, 'sum' => Transaction::signed((int) $row->total, (string) $row->currency)];
            }
        }
        usort($list, static fn (array $a, array $b): int => [$b['count'], $a['account']->name] <=> [$a['count'], $b['account']->name]);

        return $list;
    }

    /**
     * Letzte Aktivitäten: the person's own events (uploaded, moved, paid, linked …), newest first, with the
     * document to open when it is still there and visible.
     *
     * @return list<array{message: string, at: Carbon, icon: string, document: ?string, bank: bool}>
     */
    public function activity(string $orgId, int $limit = 8): array
    {
        $entries = LogEntry::query()->where('org_id', $orgId)->where('user_id', $this->userId)->where('book', 'general')
            ->whereIn('event', array_keys(self::ACTIVITY))->latest('created_at')->orderByDesc('id')->limit($limit)->get();
        $ids = $entries->pluck('document_id')->filter()->unique()->values()->all();
        $visible = $ids === [] ? [] : Document::query()->visibleTo($this->userId, $this->seesAll)->whereKey($ids)->pluck('id')->all();

        return array_values($entries->map(static fn (LogEntry $entry): array => [
            'message' => $entry->message,
            'at' => $entry->created_at,
            'icon' => self::ACTIVITY[(string) $entry->event] ?? 'clock',
            'document' => in_array($entry->document_id, $visible, true) ? $entry->document_id : null,
            'bank' => str_starts_with((string) $entry->event, 'bank.'),
        ])->all());
    }

    /**
     * Notizen: notes have no pinned flag, so the person's three newest.
     *
     * @return Collection<int, Note>
     */
    public function notes(int $limit = 3): Collection
    {
        return Note::query()->where('user_id', $this->userId)->latest()->orderByDesc('id')->limit($limit)->get();
    }

    /** @return array<string, string> */
    private function propertyIds(): array
    {
        return $this->propertyIds ??= Property::query()->whereIn('key', ['due_date', 'paid_at', 'invoice_amount'])->pluck('id', 'key')
            ->filter(static fn (mixed $id): bool => is_string($id))->all();
    }

    /**
     * @return Builder<Document>
     */
    private function dueQuery(string $dueId, mixed $paidId): Builder
    {
        return Document::query()->visibleTo($this->userId, $this->seesAll)
            ->join('document_properties as due', static fn ($join) => $join->on('due.document_id', '=', 'documents.id')->where('due.property_id', '=', $dueId))
            ->whereNotNull('due.value_date')
            ->when(is_string($paidId), static fn (Builder $q) => $q->whereNotExists(static fn ($paid) => $paid->selectRaw('1')->from('document_properties as paid')
                ->whereColumn('paid.document_id', 'documents.id')->where('paid.property_id', $paidId)->whereNotNull('paid.value_date')));
    }
}
