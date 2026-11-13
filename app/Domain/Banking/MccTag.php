<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A300: a card's merchant category code (MCC) suggests a tag for the document linked to the booking, managed under
 * Einstellungen → Bank. A small default list to start with.
 *
 * @property string $id
 * @property string $org_id
 * @property string $mcc
 * @property string $tag
 */
final class MccTag extends Model
{
    use BelongsToOrg;
    use HasUlids;

    /** Fuel, restaurants, hotels, transport, parking, telecom. */
    public const DEFAULTS = ['5541' => 'Tanken', '5542' => 'Tanken', '5812' => 'Bewirtung', '5814' => 'Bewirtung', '7011' => 'Hotel', '4111' => 'Fahrtkosten', '4121' => 'Fahrtkosten', '7523' => 'Parken', '4814' => 'Telefon'];

    protected $table = 'bank_mcc_tags';

    protected $fillable = ['org_id', 'mcc', 'tag'];

    /**
     * @return Collection<int, self>
     */
    public static function ordered(): Collection
    {
        if (! self::query()->exists()) {
            foreach (self::DEFAULTS as $mcc => $tag) {
                self::query()->create(['mcc' => (string) $mcc, 'tag' => $tag]);
            }
        }

        return self::query()->orderBy('mcc')->get();
    }
}
