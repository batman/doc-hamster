<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use Carbon\CarbonImmutable;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paired phone or browser with its long-lived device token (PLAN §11.1).
 * Only the token's SHA-256 is stored; issuing, rotating and checking tokens
 * is P1-5.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string|null $user_agent
 * @property string $token_hash
 * @property string $token_family
 * @property CarbonImmutable|null $last_used_at
 * @property string|null $last_ip_hash
 * @property CarbonImmutable|null $revoked_at
 */
#[UseFactory(DeviceFactory::class)]
final class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = ['user_id', 'name', 'user_agent', 'token_hash', 'token_family', 'last_used_at', 'last_ip_hash', 'revoked_at'];

    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The header comes from the client and has no length limit; strict mode
     * would reject anything over the column size (or invalid UTF-8) and break
     * pairing, so it is cleaned and cut to 512 characters.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function userAgent(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : mb_substr(mb_scrub($value, 'UTF-8'), 0, 512),
        );
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
