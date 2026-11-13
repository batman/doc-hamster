<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One import into an account: the period of its bookings (A296), so gaps between imports show.
 *
 * @property string $id
 * @property string $org_id
 * @property string $bank_account_id
 * @property Carbon $date_from
 * @property Carbon $date_to
 * @property int $rows
 */
final class BankImport extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $fillable = ['org_id', 'bank_account_id', 'date_from', 'date_to', 'rows'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'rows' => 'integer'];
    }

    /**
     * The imported periods of an account, overlapping and touching ones merged, oldest first (A296). Accounts
     * imported before this was kept show the span of their bookings.
     *
     * @return list<array{from: Carbon, to: Carbon}>
     */
    public static function periods(BankAccount $account): array
    {
        $imports = self::query()->where('bank_account_id', $account->id)->orderBy('date_from')->get(['date_from', 'date_to']);
        if ($imports->isEmpty()) {
            $from = $account->transactions()->min('booking_date');
            $to = $account->transactions()->max('booking_date');

            return $from === null || $to === null ? [] : [['from' => Carbon::parse((string) $from), 'to' => Carbon::parse((string) $to)]];
        }
        $periods = [];
        foreach ($imports as $import) {
            $last = count($periods) - 1;
            if ($last >= 0 && $import->date_from->lte($periods[$last]['to']->copy()->addDay())) {
                if ($import->date_to->gt($periods[$last]['to'])) {
                    $periods[$last] = ['from' => $periods[$last]['from'], 'to' => $import->date_to];
                }
            } else {
                $periods[] = ['from' => $import->date_from, 'to' => $import->date_to];
            }
        }

        return $periods;
    }
}
