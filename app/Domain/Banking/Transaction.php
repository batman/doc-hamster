<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One line of a bank statement. Status (A270): offen (no document), vorgeschlagen (only suggested links),
 * verknüpft (a confirmed link), kein Beleg nötig (with a reason; wins over the others).
 *
 * @property string $id
 * @property string $org_id
 * @property string $bank_account_id
 * @property Carbon $booking_date
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $counterparty
 * @property string|null $counterparty_iban
 * @property string|null $purpose
 * @property string $fingerprint
 * @property bool $no_receipt
 * @property string|null $no_receipt_reason
 * @property string|null $no_receipt_reason_id
 * @property string|null $suggested_reason_id A287: "kein Beleg nötig" with this reason is suggested (remembered counterparty).
 * @property string|null $booking_id
 * @property Carbon|null $value_date
 * @property string|null $purpose2
 * @property string|null $kind
 * @property string|null $reference
 * @property string|null $creditor_id
 * @property string|null $counterparty_bic
 * @property int|null $orig_amount_minor
 * @property string|null $orig_currency
 * @property int|null $fee_minor
 * @property string|null $fee_currency
 * @property int|null $balance_minor
 * @property string|null $category
 * @property string|null $card_last4
 * @property string|null $bank_state
 * @property string|null $sub_account
 * @property string|null $related_id
 * @property string|null $mcc
 * @property array<string, string>|null $extra Columns nobody mapped (A290).
 * @property array<string, string>|null $raw The row as the file had it (143).
 * @property string|null $parent_id The original of a refund or reversal (A298).
 * @property-read BankAccount $account
 * @property-read Transaction|null $parent
 */
final class Transaction extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const OPEN = 'open';

    public const SUGGESTED = 'suggested';

    public const LINKED = 'linked';

    public const NO_RECEIPT = 'none';

    public const STATUSES = [self::OPEN, self::SUGGESTED, self::LINKED, self::NO_RECEIPT];

    /** Bank states that are not final: marked, never linked automatically (A293). */
    public const UNSETTLED = ['pending', 'reverted', 'declined', 'failed', 'pdng'];

    protected $fillable = ['org_id', 'bank_account_id', 'booking_date', 'amount_minor', 'currency', 'counterparty', 'counterparty_iban', 'purpose', 'fingerprint', 'no_receipt', 'no_receipt_reason',
        'no_receipt_reason_id', 'suggested_reason_id', 'booking_id', 'value_date', 'purpose2', 'kind', 'reference', 'creditor_id', 'counterparty_bic', 'orig_amount_minor', 'orig_currency',
        'fee_minor', 'fee_currency', 'balance_minor', 'category', 'card_last4', 'bank_state', 'sub_account', 'related_id', 'mcc', 'extra', 'raw', 'parent_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['booking_date' => 'date', 'value_date' => 'date', 'amount_minor' => 'integer', 'orig_amount_minor' => 'integer', 'fee_minor' => 'integer',
            'balance_minor' => 'integer', 'no_receipt' => 'boolean', 'extra' => 'array', 'raw' => 'array'];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** A293: pending, reverted … – never linked automatically. */
    public function isUnsettled(): bool
    {
        return in_array((string) $this->bank_state, self::UNSETTLED, true);
    }

    /**
     * A312: what kind of booking this is, for the row icon – from the bank's type, else from words in the purpose:
     * card | transfer | debit | fee | own | cash | other.
     */
    public function nature(): string
    {
        $kind = mb_strtolower((string) $this->kind);
        $text = mb_strtolower($kind.' '.$this->purpose.' '.$this->purpose2.' '.$this->counterparty);

        return match (true) {
            preg_match('/fee|gebühr|entgelt|zins|interest|abschluss|kontoführung|charge/u', $kind === '' ? $text : $kind) === 1 => 'fee',
            preg_match('/\batm\b|bargeld|geldautomat|\bcash\b|auszahlung/u', $text) === 1 => 'cash',
            preg_match('/exchange|umbuchung|übertrag|eigenes konto|topup|top-up|sparplan|internal/u', $text) === 1 => 'own',
            preg_match('/card|karte|girocard|\bpos\b|visa|mastercard|apple pay|google pay/u', $kind === '' ? $text : $kind) === 1 => 'card',
            $this->creditor_id !== null || preg_match('/lastschrift|direct debit|dauerauftrag|standing order|einzug/u', $text) === 1 => 'debit',
            preg_match('/überweisung|ueberweisung|transfer|gutschrift|zahlungseingang|sepa/u', $text) === 1 => 'transfer',
            default => 'other',
        };
    }

    /** The Tabler icon of nature() (A312). */
    public function natureIcon(): string
    {
        return match ($this->nature()) {
            'card' => 'credit-card',
            'transfer' => 'arrows-left-right',
            'debit' => 'calendar-repeat',
            'fee' => 'percentage',
            'own' => 'building-bank',
            'cash' => 'cash',
            default => 'circle',
        };
    }

    /** "+12,30 EUR" / "−12,30 EUR" (A309): always with its sign. */
    public function signedAmount(): string
    {
        return self::signed($this->amount_minor, $this->currency);
    }

    public static function signed(int $minor, string $currency = 'EUR'): string
    {
        return ($minor > 0 ? '+' : ($minor < 0 ? "\u{2212}" : '')).self::money(abs($minor), $currency);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    /**
     * @return HasMany<TransactionMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(TransactionMatch::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return self::constrainStatus($query, $status);
    }

    /**
     * The status filter for any query of bookings, also a relation's (withCount).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function constrainStatus(Builder $query, string $status): Builder
    {
        $confirmed = static fn (Builder $match): Builder => $match->where('state', TransactionMatch::CONFIRMED);

        return match ($status) {
            self::NO_RECEIPT => $query->where('no_receipt', true),
            self::LINKED => $query->where('no_receipt', false)->whereHas('matches', $confirmed),
            self::SUGGESTED => $query->where('no_receipt', false)->whereDoesntHave('matches', $confirmed)->has('matches'),
            default => $query->where('no_receipt', false)->doesntHave('matches'),
        };
    }

    /** Needs the counts `confirmed_count` and `matches_count` (withCount) or loads them. */
    public function status(): string
    {
        if ($this->no_receipt) {
            return self::NO_RECEIPT;
        }
        $all = $this->getAttribute('matches_count') ?? $this->matches()->count();
        $confirmed = $this->getAttribute('confirmed_count') ?? $this->matches()->where('state', TransactionMatch::CONFIRMED)->count();

        return $confirmed > 0 ? self::LINKED : ($all > 0 ? self::SUGGESTED : self::OPEN);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithStatusCounts(Builder $query): Builder
    {
        return $query->withCount(['matches', 'matches as confirmed_count' => static fn (Builder $match): Builder => $match->where('state', TransactionMatch::CONFIRMED)]);
    }

    public function formattedAmount(): string
    {
        return self::money($this->amount_minor, $this->currency);
    }

    public static function money(int $minor, string $currency = 'EUR'): string
    {
        return number_format($minor / 100, 2, ',', '.').' '.$currency;
    }
}
