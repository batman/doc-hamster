<?php

declare(strict_types=1);

namespace App\Domain\Advisor;

use App\Domain\Auth\User;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Org\BelongsToOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\Role;
use App\Domain\ReceiptPack\ReceiptPack;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The tax advisor's access (point 210, AK-4.6.4, D-012): what one accountant membership may see – a period (open
 * ends allowed), the bank accounts incl. cash boxes (null = all) and an optional expiry date. An accountant without
 * this row (given the role on the people page) sees everything read-only (Q-20).
 *
 * @property string $id
 * @property string $org_id
 * @property string $membership_id
 * @property string $user_id
 * @property CarbonImmutable|null $period_from
 * @property CarbonImmutable|null $period_to
 * @property list<string>|null $account_ids
 * @property CarbonImmutable|null $expires_at last day the login works
 * @property bool $must_change_password
 * @property string|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read Membership $membership
 * @property-read User $user
 */
final class AdvisorAccess extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $fillable = ['org_id', 'membership_id', 'user_id', 'period_from', 'period_to', 'account_ids', 'expires_at', 'must_change_password', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'account_ids' => 'array',
            'expires_at' => 'immutable_date',
            'must_change_password' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The access of a membership, across organisations (sign-in runs before an organisation is active). */
    public static function forMembership(Membership $membership): ?self
    {
        return self::query()->withoutGlobalScopes()->where('membership_id', $membership->id)->first();
    }

    /** Past its last day (the expiry date itself still works). */
    public function isExpired(?CarbonImmutable $today = null): bool
    {
        return $this->expires_at !== null && ($today ?? CarbonImmutable::today())->greaterThan($this->expires_at);
    }

    /**
     * Whether a person signs in as tax advisor: an active accountant membership with an access row. Such a person
     * never stays signed in ("Angemeldet bleiben" is ignored, PLAN §11.1 point 8).
     */
    public static function isAdvisor(User $user): bool
    {
        $ids = $user->memberships()->whereNull('revoked_at')->where('role', Role::Accountant->value)->pluck('id')->all();

        return $ids !== [] && self::query()->withoutGlobalScopes()->whereIn('membership_id', $ids)->exists();
    }

    /**
     * The accesses of a person that are all expired: the expiry date to show at sign-in; null if one still works.
     */
    public static function expiredOn(User $user): ?CarbonImmutable
    {
        $memberships = $user->memberships()->whereNull('revoked_at')->get();
        $last = null;
        foreach ($memberships as $membership) {
            $access = $membership->role === Role::Accountant ? self::forMembership($membership) : null;
            if ($access === null || ! $access->isExpired()) {
                return null;
            }
            $last = $last === null || $access->expires_at?->greaterThan($last) ? $access->expires_at : $last;
        }

        return $last;
    }

    /**
     * Bookings in scope: in the period and on the allowed accounts.
     *
     * @return Builder<Transaction>
     */
    public static function bookings(?self $access): Builder
    {
        $query = Transaction::query();
        if ($access === null) {
            return $query;
        }
        if ($access->period_from !== null) {
            $query->whereDate('booking_date', '>=', $access->period_from->toDateString());
        }
        if ($access->period_to !== null) {
            $query->whereDate('booking_date', '<=', $access->period_to->toDateString());
        }
        if ($access->account_ids !== null) {
            $query->whereIn('bank_account_id', $access->account_ids);
        }

        return $query;
    }

    /**
     * Documents in scope: linked (confirmed) to a booking in scope, or marked "Ins Belegpaket" with a receipt date in
     * the period (A38). Without an access row: all documents (Q-20).
     *
     * @return Builder<Document>
     */
    public static function documents(?self $access): Builder
    {
        $query = Document::query();
        if ($access === null) {
            return $query;
        }
        $bookings = self::bookings($access)->select('id');

        return $query->where(static function (Builder $q) use ($access, $bookings): void {
            $q->whereHas('matches', static fn (Builder $m) => $m->where('state', TransactionMatch::CONFIRMED)->whereIn('transaction_id', $bookings))
                ->orWhere(static function (Builder $loose) use ($access): void {
                    $loose->where('in_receipt_pack', true);
                    if ($access->period_from !== null) {
                        $loose->whereRaw(Document::dateSql('doc').' >= ?', [$access->period_from->toDateString()]);
                    }
                    if ($access->period_to !== null) {
                        $loose->whereRaw(Document::dateSql('doc').' <= ?', [$access->period_to->toDateString()]);
                    }
                });
        });
    }

    /**
     * Finished receipt packs in scope (point 211.4: packs show up in the access by themselves): within the period
     * and only of allowed accounts.
     *
     * @return list<ReceiptPack>
     */
    public static function packs(?self $access, string $orgId): array
    {
        $query = ReceiptPack::ofOrg($orgId)->where('status', ReceiptPack::DONE)->latest();
        if ($access?->period_from !== null) {
            $query->whereDate('period_from', '>=', $access->period_from->toDateString());
        }
        if ($access?->period_to !== null) {
            $query->whereDate('period_to', '<=', $access->period_to->toDateString());
        }
        $allowed = $access?->account_ids;

        return array_values($query->get()->filter(static fn (ReceiptPack $p): bool => $allowed === null || array_diff($p->account_ids, $allowed) === [])->all());
    }
}
