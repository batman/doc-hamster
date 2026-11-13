<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A reason for "kein Beleg nötig" (A286), managed under Einstellungen → Bank: sorted, switched off, deleted while
 * nobody uses it. Every organisation starts with the default list.
 *
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property int $sort
 * @property bool $active
 */
final class NoReceiptReason extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const DEFAULTS = ['transfer', 'salary', 'fee', 'interest', 'tax', 'cash', 'private', 'chargeback'];

    protected $table = 'no_receipt_reasons';

    protected $fillable = ['org_id', 'name', 'sort', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort' => 'integer', 'active' => 'boolean'];
    }

    /**
     * The organisation's reasons in their order; the defaults are created the first time.
     *
     * @return Collection<int, self>
     */
    public static function ordered(bool $activeOnly = false): Collection
    {
        if (! self::query()->exists()) {
            foreach (self::DEFAULTS as $sort => $key) {
                self::query()->create(['name' => __('bank.reasons_default.'.$key), 'sort' => $sort, 'active' => true]);
            }
        }

        return self::query()->when($activeOnly, static fn ($q) => $q->where('active', true))->orderBy('sort')->orderBy('name')->get();
    }

    public function inUse(): bool
    {
        return Transaction::query()->where(fn ($q) => $q->where('no_receipt_reason_id', $this->id)->orWhere('suggested_reason_id', $this->id))->exists();
    }
}
