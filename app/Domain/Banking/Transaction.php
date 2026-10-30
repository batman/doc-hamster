<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One line of a bank statement.
 *
 * @property string $id
 * @property string $org_id
 * @property string $bank_account_id
 * @property Carbon $booking_date
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $counterparty
 * @property string|null $purpose
 * @property string $fingerprint
 */
final class Transaction extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $fillable = ['org_id', 'bank_account_id', 'booking_date', 'amount_minor', 'currency', 'counterparty', 'purpose', 'fingerprint'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['booking_date' => 'date', 'amount_minor' => 'integer'];
    }

    /**
     * @return HasMany<TransactionMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(TransactionMatch::class);
    }

    public function formattedAmount(): string
    {
        return number_format($this->amount_minor / 100, 2, ',', '.').' '.$this->currency;
    }
}
