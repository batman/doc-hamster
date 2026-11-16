<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Org\BelongsToOrg;
use App\Domain\Org\OrgScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An access key for the interface (D-040, api/v1): a person's own key for a program or an assistant (e.g. through the
 * separate MCP adapter, github.com/batman/doc-hamster-mcp). It acts as that person – with their rights, their role's restriction and only their
 * organisation – and on top only what its scope allows: READ ("Nur lesen") or LINK ("Lesen + Verknüpfen"). It never
 * deletes. Only its SHA-256 is stored; the key itself exists once, when it is made.
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property string $name
 * @property string $scope read|link
 * @property string $key_hash
 * @property string $key_prefix
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 */
final class AccessKey extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const READ = 'read';

    public const LINK = 'link';

    public const SCOPES = [self::READ, self::LINK];

    private const PREFIX = 'dha_';

    protected $fillable = ['org_id', 'user_id', 'name', 'scope', 'key_hash', 'key_prefix', 'last_used_at', 'revoked_at'];

    protected $hidden = ['key_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * @return array{self, string} the access key and the key itself, which only exists in this moment
     */
    public static function issue(string $userId, string $name, string $scope): array
    {
        $key = self::PREFIX.Str::random(48);
        $access = self::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'scope' => in_array($scope, self::SCOPES, true) ? $scope : self::READ,
            'key_hash' => hash('sha256', $key),
            'key_prefix' => substr($key, 0, 10),
        ]);

        return [$access, $key];
    }

    /** The working access key behind a key, from any organisation. */
    public static function byKey(string $key): ?self
    {
        if (! str_starts_with($key, self::PREFIX)) {
            return null;
        }

        return self::query()->withoutGlobalScope(OrgScope::class)
            ->where('key_hash', hash('sha256', $key))
            ->whereNull('revoked_at')
            ->first();
    }

    public function mayLink(): bool
    {
        return $this->scope === self::LINK;
    }

    /** At most one write a minute, not one per request. */
    public function touchUsed(): void
    {
        if ($this->last_used_at === null || $this->last_used_at->lt(now()->subMinute())) {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }
}
