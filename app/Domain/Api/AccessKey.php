<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Auth\User;
use App\Domain\Org\BelongsToOrg;
use App\Domain\Org\OrgScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An access key for the interface (D-040, api/v1 and the MCP address): a person's own key for a program, or – with a
 * client – an assistant's connection made by clicks (OAuth): then the key is a short-lived access token (expires_at)
 * with a rotating refresh token. It acts as that person – with their rights, their role's restriction and only their
 * organisation – and on top only what its scope allows: READ ("Nur lesen") or LINK ("Lesen + Verknüpfen"). It never
 * deletes. Only SHA-256 hashes are stored; a key exists in clear only in the moment it is made.
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property string $name
 * @property string $scope read|link
 * @property string|null $client_id the assistant (OAuthClient) of a connection, null for a key made on Mein Konto
 * @property string $key_hash
 * @property string $key_prefix
 * @property string|null $refresh_hash
 * @property Carbon|null $expires_at
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

    private const REFRESH_PREFIX = 'dhr_';

    /** How long a connection's access token works before the assistant refreshes it. */
    public const TOKEN_SECONDS = 3600;

    protected $fillable = ['org_id', 'user_id', 'name', 'scope', 'client_id', 'key_hash', 'key_prefix', 'refresh_hash', 'expires_at', 'last_used_at', 'revoked_at'];

    protected $hidden = ['key_hash', 'refresh_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime', 'expires_at' => 'datetime'];
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
            ->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }

    /**
     * A new assistant connection after a person's "Erlauben" (D-040).
     *
     * @return array{self, string, string} the connection, its access token and its refresh token (both only now)
     */
    public static function connect(string $orgId, string $userId, string $name, string $scope, string $clientId): array
    {
        [$token, $refresh] = [self::PREFIX.Str::random(48), self::REFRESH_PREFIX.Str::random(48)];
        $access = self::query()->create([
            'org_id' => $orgId,
            'user_id' => $userId,
            'name' => $name,
            'scope' => in_array($scope, self::SCOPES, true) ? $scope : self::READ,
            'client_id' => $clientId,
            'key_hash' => hash('sha256', $token),
            'key_prefix' => substr($token, 0, 10),
            'refresh_hash' => hash('sha256', $refresh),
            'expires_at' => now()->addSeconds(self::TOKEN_SECONDS),
        ]);

        return [$access, $token, $refresh];
    }

    /** The working connection behind a refresh token of this client, from any organisation. */
    public static function byRefresh(string $refresh, string $clientId): ?self
    {
        if (! str_starts_with($refresh, self::REFRESH_PREFIX)) {
            return null;
        }

        return self::query()->withoutGlobalScope(OrgScope::class)
            ->where('refresh_hash', hash('sha256', $refresh))->where('client_id', $clientId)->whereNull('revoked_at')->first();
    }

    /**
     * A fresh access token and refresh token; the old ones stop working at once (rotation).
     *
     * @return array{string, string}
     */
    public function rotate(): array
    {
        [$token, $refresh] = [self::PREFIX.Str::random(48), self::REFRESH_PREFIX.Str::random(48)];
        $this->forceFill([
            'key_hash' => hash('sha256', $token),
            'key_prefix' => substr($token, 0, 10),
            'refresh_hash' => hash('sha256', $refresh),
            'expires_at' => now()->addSeconds(self::TOKEN_SECONDS),
        ])->save();

        return [$token, $refresh];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConnection(): bool
    {
        return $this->client_id !== null;
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
