<?php

declare(strict_types=1);

namespace App\Domain\Scanning;

use App\Domain\Org\BelongsToOrg;
use App\Domain\Org\OrgScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A Scan Station: a small always-on program on the home network, next to the
 * printer. It connects out to this server (no open ports at home), is linked
 * once with a one-time code and then uses its own token. Only SHA-256 hashes
 * of code and token are stored, so the database alone cannot drive a station.
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property string $name
 * @property string|null $pairing_hash
 * @property Carbon|null $pairing_expires_at
 * @property string|null $token_hash
 * @property string|null $printer_name
 * @property bool $printer_feeder
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 */
final class ScanStation extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const PAIRING_HOURS = 24;

    /** A station that asked within this many seconds counts as online (it asks every few seconds). */
    public const ONLINE_SECONDS = 90;

    protected $fillable = ['org_id', 'user_id', 'name', 'pairing_hash', 'pairing_expires_at', 'token_hash', 'printer_name', 'printer_feeder', 'last_seen_at', 'revoked_at'];

    protected $hidden = ['pairing_hash', 'token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['pairing_expires_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime', 'printer_feeder' => 'boolean'];
    }

    /**
     * A new station waiting to be linked. Unused expired ones of the person go.
     *
     * @return array{self, string} the station and its code, which only exists in this moment
     */
    public static function startPairing(string $userId, string $name): array
    {
        self::query()->where('user_id', $userId)->whereNull('token_hash')->where('pairing_expires_at', '<', now())->delete();

        $code = Str::random(40);
        $station = self::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'pairing_hash' => hash('sha256', $code),
            'pairing_expires_at' => now()->addHours(self::PAIRING_HOURS),
        ]);

        return [$station, $code];
    }

    /**
     * Trades the code for the station's token, once: of two requests with the
     * same code only one wins. Runs without an active organisation.
     *
     * @return array{self, string}|null
     */
    public static function pair(string $code, string $name): ?array
    {
        $token = Str::random(48);
        $won = self::query()->withoutGlobalScope(OrgScope::class)
            ->where('pairing_hash', hash('sha256', $code))
            ->where('pairing_expires_at', '>', now())
            ->whereNull('revoked_at')
            ->update([
                'pairing_hash' => null,
                'pairing_expires_at' => null,
                'token_hash' => hash('sha256', $token),
                'name' => $name,
                'last_seen_at' => now(),
            ]);
        if ($won !== 1) {
            return null;
        }

        return [self::query()->withoutGlobalScope(OrgScope::class)->where('token_hash', hash('sha256', $token))->firstOrFail(), $token];
    }

    /** The working station behind a token, from any organisation. */
    public static function byToken(string $token): ?self
    {
        return self::query()->withoutGlobalScope(OrgScope::class)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();
    }

    /** Is there a linked station in the active organisation? (Nav entry, scan page.) */
    public static function linked(): bool
    {
        return self::query()->whereNotNull('token_hash')->whereNull('revoked_at')->exists();
    }

    /** The station that can take a scan right now: linked, revoked not, asked recently. */
    public static function online(): ?self
    {
        return self::query()->whereNotNull('token_hash')->whereNull('revoked_at')
            ->where('last_seen_at', '>', now()->subSeconds(self::ONLINE_SECONDS))
            ->latest('last_seen_at')->first();
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subSeconds(self::ONLINE_SECONDS));
    }
}
