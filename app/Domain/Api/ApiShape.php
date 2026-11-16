<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Banking\Matcher;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use Illuminate\Support\Collection;

/**
 * What the interface (D-040) answers: bookings and documents as plain arrays – ids, dates as YYYY-MM-DD, money as
 * minor units plus a decimal string and the currency. Text only (F3): the scans themselves never leave the server here.
 */
final class ApiShape
{
    public function __construct(private readonly Matcher $matcher) {}

    /**
     * @return array<string, mixed>
     */
    public function transaction(Transaction $transaction): array
    {
        $purpose = trim(implode(' ', array_filter([$transaction->purpose, $transaction->purpose2, $transaction->reference])));

        return [
            'id' => $transaction->id,
            'account_id' => $transaction->bank_account_id,
            'date' => $transaction->booking_date->format('Y-m-d'),
            'time' => $transaction->booked_time,
            'amount_minor' => $transaction->amount_minor,
            'amount' => self::decimal($transaction->amount_minor),
            'currency' => $transaction->currency,
            'original_amount' => $transaction->orig_amount_minor !== null && $transaction->orig_currency !== null && $transaction->orig_currency !== $transaction->currency
                ? self::decimal($transaction->orig_amount_minor).' '.$transaction->orig_currency : null,
            'counterparty' => $transaction->counterparty,
            'counterparty_iban' => $transaction->counterparty_iban,
            'purpose' => $purpose === '' ? null : $purpose,
            'kind' => $transaction->kind,
            'card_last4' => $transaction->card_last4,
            'status' => $transaction->status(),
            'no_receipt_reason' => $transaction->no_receipt ? $transaction->no_receipt_reason : null,
            'note' => $transaction->note,
        ];
    }

    /**
     * Documents with what the matcher knows about them (amount, Belegdatum, sender, IBAN, invoice number) and the
     * bookings they hang on.
     *
     * @param  Collection<int, Document>  $documents
     * @return list<array<string, mixed>>
     */
    public function documents(Collection $documents): array
    {
        if ($documents->isEmpty()) {
            return [];
        }
        $facts = $this->matcher->facts($documents, false);
        $links = TransactionMatch::query()->whereIn('document_id', $documents->pluck('id')->all())->get(['document_id', 'transaction_id', 'state'])
            ->groupBy('document_id');

        return array_values($documents->map(fn (Document $document): array => $this->document($document, $facts[$document->id] ?? [], $links->get($document->id, collect())))->all());
    }

    /**
     * @param  array<string, mixed>  $fact
     * @param  Collection<int, TransactionMatch>  $links
     * @return array<string, mixed>
     */
    private function document(Document $document, array $fact, Collection $links): array
    {
        $amount = is_int($fact['amount'] ?? null) ? $fact['amount'] : null;

        return [
            'id' => $document->id,
            'title' => $document->title,
            'date' => $fact['invoice_date'] ?? null,
            'time' => $fact['time'] ?? null,
            'uploaded' => $document->created_at->format('Y-m-d'),
            'folder_id' => $document->folder_id,
            'amount_minor' => $amount,
            'amount' => $amount === null ? null : self::decimal($amount),
            'sender' => $fact['sender'] ?? null,
            'iban' => $fact['iban'] ?? null,
            'invoice_number' => $fact['invoice_number'] ?? null,
            'due_date' => $fact['due_date'] ?? null,
            'linked' => $links->where('state', TransactionMatch::CONFIRMED)->pluck('transaction_id')->values()->all(),
            'suggested_for' => $links->where('state', TransactionMatch::SUGGESTED)->pluck('transaction_id')->values()->all(),
        ];
    }

    /** -5400 → "-54.00". */
    public static function decimal(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }
}
