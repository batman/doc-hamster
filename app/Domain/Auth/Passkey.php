<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use Carbon\CarbonImmutable;
use Database\Factories\PasskeyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WebAuthn credential of an owner or member (PLAN §11.1). Registration and
 * verification follow in P1-4 (D-006).
 *
 * @property string $id
 * @property string $user_id
 * @property string $credential_id base64url, as sent by the authenticator
 * @property string $credential_hash
 * @property string $public_key raw COSE key bytes (BLOB), as returned by the WebAuthn library
 * @property int $sign_count
 * @property list<string>|null $transports
 * @property string|null $aaguid
 * @property bool $backup_eligible
 * @property bool $backup_state
 * @property string $name
 * @property CarbonImmutable|null $last_used_at
 */
#[UseFactory(PasskeyFactory::class)]
final class Passkey extends Model
{
    /** @use HasFactory<PasskeyFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = ['user_id', 'credential_id', 'public_key', 'sign_count', 'transports', 'aaguid', 'backup_eligible', 'backup_state', 'name', 'last_used_at'];

    /** Binary – would break JSON serialisation, and nobody needs it outside verification. */
    protected $hidden = ['public_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sign_count' => 'integer',
            'transports' => 'array',
            'backup_eligible' => 'boolean',
            'backup_state' => 'boolean',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /**
     * The unique index lives on the hash (credential IDs can be too long to
     * index directly), so the hash is set together with the ID – only when the
     * ID itself is assigned, never from a partly loaded model.
     *
     * @return Attribute<string, string>
     */
    protected function credentialId(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): array => [
                'credential_id' => $value,
                'credential_hash' => self::hashCredentialId($value),
            ],
        );
    }

    public static function hashCredentialId(string $credentialId): string
    {
        return hash('sha256', $credentialId);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
