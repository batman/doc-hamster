<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack;

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What goes into a receipt pack (point 211.2/211.3), read from the active organisation: per ticked account (bank
 * accounts and cash boxes alike, F30) its bookings of the period with their confirmed documents; plus the documents
 * without a booking that are marked "Ins Belegpaket" (A38) and whose receipt date lies in the period.
 */
final readonly class PackContents
{
    /**
     * @param  list<PackSection>  $sections
     * @param  list<PackReceipt>  $loose
     */
    public function __construct(public PackPeriod $period, public array $sections, public array $loose) {}

    /**
     * @param  list<string>  $accountIds
     */
    public static function load(PackPeriod $period, array $accountIds): self
    {
        $accounts = BankAccount::query()->whereIn('id', $accountIds)->orderByRaw("kind = 'cash'")->orderBy('name')->get();
        $sections = [];
        foreach ($accounts as $account) {
            $sections[] = new PackSection($account->id, $account->name, (string) $account->iban, $account->kind, $account->currency, self::bookings($account, $period));
        }

        return new self($period, $sections, self::loose($period));
    }

    public function figures(): PackFigures
    {
        return PackFigures::total(...$this->sections);
    }

    /**
     * @return list<PackBooking>
     */
    private static function bookings(BankAccount $account, PackPeriod $period): array
    {
        $bookings = [];
        Transaction::query()->where('bank_account_id', $account->id)
            ->whereDate('booking_date', '>=', $period->from->toDateString())->whereDate('booking_date', '<=', $period->to->toDateString())
            ->with(['matches' => static fn ($m) => $m->where('state', TransactionMatch::CONFIRMED)->with(['document.files', 'document.tags'])])
            ->orderBy('booking_date')->orderBy('id')
            ->each(static function (Transaction $t) use (&$bookings): void {
                $receipts = [];
                $tags = [];
                foreach ($t->matches as $match) {
                    $document = $match->getRelationValue('document');
                    if (! $document instanceof Document) {
                        continue;
                    }
                    $receipts[] = self::receipt($document);
                    foreach ($document->tags as $tag) {
                        $tags[$tag->name] = true;
                    }
                }
                $bookings[] = new PackBooking(
                    $t->id, CarbonImmutable::parse($t->booking_date), $t->amount_minor, $t->currency,
                    trim((string) $t->counterparty), trim(trim((string) $t->purpose).' '.trim((string) $t->purpose2)),
                    array_keys($tags), $receipts, $t->no_receipt, $t->no_receipt_reason, $t->booked_time,
                );
            });

        return $bookings;
    }

    /**
     * @return list<PackReceipt>
     */
    private static function loose(PackPeriod $period): array
    {
        return array_values(Document::query()->where('in_receipt_pack', true)
            ->whereDoesntHave('matches', static fn (Builder $m) => $m->where('state', TransactionMatch::CONFIRMED))
            ->whereRaw(Document::dateSql('doc').' between ? and ?', [$period->from->toDateString(), $period->to->toDateString()])
            ->with('files')->orderByRaw(Document::dateSql('doc'))->orderBy('id')->get()
            ->map(self::receipt(...))->all());
    }

    private static function receipt(Document $document): PackReceipt
    {
        $files = array_values($document->files->sortBy('page_no')
            ->filter(static fn (DocumentFile $f): bool => $f->kind !== 'thumb')
            ->map(static fn (DocumentFile $f): array => ['path' => $f->path, 'mime' => $f->mime])->all());

        return new PackReceipt($document->id, $document->title, $files, $document->dateBy('doc')->format('d.m.Y'));
    }
}
