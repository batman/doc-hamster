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
 * @property-read BankAccount $account
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

    protected $fillable = ['org_id', 'bank_account_id', 'booking_date', 'amount_minor', 'currency', 'counterparty', 'counterparty_iban', 'purpose', 'fingerprint', 'no_receipt', 'no_receipt_reason'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['booking_date' => 'date', 'amount_minor' => 'integer', 'no_receipt' => 'boolean'];
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
