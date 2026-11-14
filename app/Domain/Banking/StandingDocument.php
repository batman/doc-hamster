<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Documents\Document;
use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Dauerbeleg" (point 148, A322–A325): one document for every booking of a counterparty (IBAN, else the normalised
 * name, NoReceiptRule::key) with this amount and direction (in|out). `interval` is guessed from the linked bookings'
 * days: monthly | quarterly | yearly | irregular (null: too few yet). An ended rule (active false) links nothing more.
 *
 * @property string $id
 * @property string $org_id
 * @property string $document_id
 * @property string|null $counterparty_iban
 * @property string|null $counterparty_name
 * @property int $amount_minor
 * @property string $currency
 * @property string $direction
 * @property string|null $interval
 * @property bool $active
 * @property-read Document $document
 */
final class StandingDocument extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const INTERVALS = ['monthly', 'quarterly', 'yearly', 'irregular'];

    protected $fillable = ['org_id', 'document_id', 'counterparty_iban', 'counterparty_name', 'amount_minor', 'currency', 'direction', 'interval', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** The booking's amount with the rule's sign. */
    public function signedAmount(): int
    {
        return $this->direction === 'in' ? $this->amount_minor : -$this->amount_minor;
    }

    public static function iban(?string $iban): ?string
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', (string) $iban));

        return $iban === '' ? null : $iban;
    }

    /** Same counterparty: the IBAN when the rule has one, else the normalised name. */
    public function sameCounterparty(Transaction $transaction): bool
    {
        if ($this->counterparty_iban !== null) {
            return self::iban($transaction->counterparty_iban) === $this->counterparty_iban;
        }

        return $this->counterparty_name !== null && $this->counterparty_name !== '' && NoReceiptRule::key($transaction->counterparty) === $this->counterparty_name;
    }

    /** Same direction and currency (A324); the amount is checked apart (A323). */
    public function sameKind(Transaction $transaction): bool
    {
        return $transaction->currency === $this->currency && ($transaction->amount_minor > 0 ? 'in' : 'out') === $this->direction;
    }
}
