<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property string $currency
 * @property array<string, string>|null $import_profile Column names of the last import, by field.
 */
final class BankAccount extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $fillable = ['org_id', 'name', 'currency', 'import_profile'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['import_profile' => 'array'];
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
