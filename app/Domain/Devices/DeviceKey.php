<?php

declare(strict_types=1);

namespace App\Domain\Devices;

use App\Domain\Org\BelongsToOrg;
use App\Domain\Org\OrgScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An upload key for one device (points 152, A329, A330): an iPhone shortcut or a program such as a
 * scan tool. The key may only send documents (POST api/upload) – it reads nothing. Only its SHA-256
 * is stored; the key itself exists once, when it is made.
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property string $name
 * @property string $kind iphone|program
 * @property string $key_hash
 * @property string $key_prefix
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 */
final class DeviceKey extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const KINDS = ['iphone', 'program'];

    private const PREFIX = 'dhk_';

    protected $fillable = ['org_id', 'user_id', 'name', 'kind', 'key_hash', 'key_prefix', 'last_used_at', 'revoked_at'];

    protected $hidden = ['key_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * @return array{self, string} the device and its key, which only exists in this moment
     */
    public static function issue(string $userId, string $name, string $kind): array
    {
        $key = self::PREFIX.Str::random(40);
        $device = self::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'kind' => in_array($kind, self::KINDS, true) ? $kind : 'program',
            'key_hash' => hash('sha256', $key),
            'key_prefix' => substr($key, 0, 10),
        ]);

        return [$device, $key];
    }

    /** The working device behind a key, from any organisation. */
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

    /** What a document sent with this key counts as (documents.source). */
    public function documentSource(): string
    {
        return $this->kind === 'iphone' ? 'phone' : 'api';
    }
}
