<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A287: a counterparty marked "kein Beleg nötig" with a reason from the list; the next booking from it is suggested
 * the same way. `counterparty_key` is the counterparty in lower case without signs.
 *
 * @property string $id
 * @property string $org_id
 * @property string $counterparty_key
 * @property string $reason_id
 */
final class NoReceiptRule extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $fillable = ['org_id', 'counterparty_key', 'reason_id'];

    public static function key(?string $counterparty): string
    {
        return mb_substr(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $counterparty))), 0, 255);
    }
}
