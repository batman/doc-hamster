<?php

declare(strict_types=1);

namespace App\Domain\Printing;

use App\Domain\Org\BelongsToOrg;
use App\Domain\Org\OrgScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The printer "Hamster" on one computer. The download carries a one-time
 * code; the computer trades it once for its own token. Only SHA-256 hashes of
 * code and token are stored, so the database alone can't send documents.
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property string $name
 * @property string|null $pairing_hash
 * @property Carbon|null $pairing_expires_at
 * @property string|null $token_hash
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 */
final class Printer extends Model
{
    use BelongsToOrg;
    use HasUlids;

    /** The download may sit in the Downloads folder for a while, but not for ever. */
    public const PAIRING_HOURS = 24;

    protected $fillable = ['org_id', 'user_id', 'name', 'pairing_hash', 'pairing_expires_at', 'token_hash', 'last_used_at', 'revoked_at'];

    protected $hidden = ['pairing_hash', 'token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['pairing_expires_at' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * A new printer waiting for its computer. Unused older ones of the person go.
     *
     * @return array{self, string} the printer and its code, which only exists in this moment
     */
    public static function startPairing(string $userId, string $name): array
    {
        self::query()->where('user_id', $userId)->whereNull('token_hash')->where('pairing_expires_at', '<', now())->delete();

        $code = Str::random(40);
        $printer = self::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'pairing_hash' => hash('sha256', $code),
            'pairing_expires_at' => now()->addHours(self::PAIRING_HOURS),
        ]);

        return [$printer, $code];
    }

    /**
     * Trades a code for the computer's token, once: of two requests with the
     * same code only one wins. Runs without an active organisation.
     *
     * @return array{self, string}|null
     */
    public static function pair(string $code, string $name): ?array
    {
        $hash = hash('sha256', $code);
        $token = Str::random(48);

        $won = self::query()->withoutGlobalScope(OrgScope::class)
            ->where('pairing_hash', $hash)
            ->where('pairing_expires_at', '>', now())
            ->whereNull('revoked_at')
            ->update([
                'pairing_hash' => null,
                'pairing_expires_at' => null,
                'token_hash' => hash('sha256', $token),
                'name' => $name,
                'last_used_at' => now(),
            ]);
        if ($won !== 1) {
            return null;
        }

        return [self::query()->withoutGlobalScope(OrgScope::class)->where('token_hash', hash('sha256', $token))->firstOrFail(), $token];
    }

    /** The working printer behind a token, from any organisation. */
    public static function byToken(string $token): ?self
    {
        return self::query()->withoutGlobalScope(OrgScope::class)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();
    }
}
