<?php

declare(strict_types=1);

namespace App\Domain\Org;

use App\Domain\Auth\User;
use Carbon\CarbonImmutable;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A one-time link/QR code to join an organisation (PLAN §11.1). Only the
 * token's SHA-256 is stored; creating and redeeming links is P1-4.
 *
 * @property string $id
 * @property string $org_id
 * @property Role $role
 * @property string $token_hash
 * @property string|null $email
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property string|null $used_by
 * @property string|null $created_by
 */
#[UseFactory(InvitationFactory::class)]
final class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = ['org_id', 'role', 'token_hash', 'email', 'expires_at', 'used_at', 'used_by', 'created_by'];

    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $invitation): void {
            if (! $invitation->role->canBeInvited()) {
                throw new InvalidArgumentException('Tax advisor accounts are created by the owner, not invited (D-012).');
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsable(CarbonImmutable $now): bool
    {
        return $this->used_at === null && $now->lessThan($this->expires_at);
    }
}
