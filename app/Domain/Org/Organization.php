<?php

declare(strict_types=1);

namespace App\Domain\Org;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One separate data space – household, company or club (PLAN §2, D-011).
 *
 * @property string $id
 * @property string $name
 * @property array<string, mixed>|null $settings_json
 * @property int|null $retention_days null = keep until the owner deletes
 * @property int $trash_days
 */
#[UseFactory(OrganizationFactory::class)]
final class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = ['name', 'settings_json', 'retention_days', 'trash_days'];

    protected static function booted(): void
    {
        self::created(static fn (self $organization) => OrgRole::ensureDefaults($organization));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings_json' => 'array',
            'retention_days' => 'integer',
            'trash_days' => 'integer',
        ];
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'org_id');
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class, 'org_id');
    }
}
