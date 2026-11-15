<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Org\BelongsToOrg;
use App\Domain\Org\RestrictedByRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property string $currency
 * @property array<string, string>|null $import_profile Column names of the last import, by field.
 * @property string|null $source_key Where its bookings come from in a file with several accounts (A299): source, sub-account and currency.
 * @property string|null $iban The account's own IBAN: never proposed as the payee of an invoice (point 120.1).
 * @property string $kind business | private | cash (points 189, 190).
 * @property int $opening_balance_minor A cash account's opening balance (Anfangsbestand, point 190).
 */
final class BankAccount extends Model
{
    use BelongsToOrg;
    use HasUlids;
    use RestrictedByRole;

    public const BUSINESS = 'business';

    public const PRIVATE = 'private';

    /** Point 190: a cash box – no bank, no IBAN, bookings typed in by hand. */
    public const CASH = 'cash';

    public const KINDS = [self::BUSINESS, self::PRIVATE, self::CASH];

    protected $fillable = ['org_id', 'name', 'currency', 'import_profile', 'iban', 'source_key', 'kind', 'opening_balance_minor'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['import_profile' => 'array', 'opening_balance_minor' => 'integer'];
    }

    public function isCash(): bool
    {
        return $this->kind === self::CASH;
    }

    /** The row and window icon: a cash box shows Tabler "cash". */
    public function icon(): string
    {
        return $this->isCash() ? 'cash' : 'building-bank';
    }

    /** Point 189: the account a statement belongs to by its own IBAN (cash boxes have none). */
    public static function byIban(?string $iban): ?self
    {
        return $iban === null || $iban === '' ? null : self::query()->where('iban', $iban)->where('kind', '!=', self::CASH)->first();
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
