<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time code that connects a phone with the account of the person who
 * showed it (PLAN §11.1 point 2). Valid 10 minutes, works once; only the hash
 * of the code is stored.
 *
 * @property string $id
 * @property string $user_id
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property string|null $device_id
 */
final class DevicePairing extends Model
{
    use HasUlids;

    public const MINUTES = 10;

    /** Without look-alikes (0/O, 1/l/I), easy to type on a phone. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 8;

    protected $fillable = ['user_id', 'code_hash', 'expires_at', 'used_at', 'device_id'];

    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Starts a new code for the user; earlier unused codes of this user stop working.
     *
     * @return array{0: self, 1: string} the pairing and the code in plain text (shown once)
     */
    public static function start(string $userId): array
    {
        self::query()->where('user_id', $userId)->whereNull('used_at')->delete();
        self::query()->where('expires_at', '<', now()->subDay())->delete();

        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $pairing = self::query()->create([
            'user_id' => $userId,
            'code_hash' => self::hash($code),
            'expires_at' => now()->addMinutes(self::MINUTES),
        ]);

        return [$pairing, $code];
    }

    /** Uses the code up. Only one caller can win; null if it is wrong, old or used. */
    public static function redeem(string $code): ?self
    {
        $hash = self::hash($code);
        $won = self::query()
            ->where('code_hash', $hash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        return $won === 1 ? self::query()->where('code_hash', $hash)->first() : null;
    }

    /** "abcd-2345 " and "ABCD2345" are the same code. */
    public static function normalise(string $code): string
    {
        return strtoupper((string) preg_replace('/[^0-9a-z]/i', '', $code));
    }

    /** Shown in groups of four: ABCD-2345. */
    public static function format(string $code): string
    {
        return implode('-', str_split(self::normalise($code), 4));
    }

    private static function hash(string $code): string
    {
        return hash('sha256', 'pairing:'.self::normalise($code));
    }
}
